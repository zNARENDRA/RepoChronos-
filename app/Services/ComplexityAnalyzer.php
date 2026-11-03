<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ComplexityAnalyzer
{
    public function measure(string $path, string $source): array
    {
        $process = new Process([config('repochronos.python'), base_path('scripts/analyze.py')]);
        $process->setInput(json_encode(['path' => $path, 'source' => $source], JSON_THROW_ON_ERROR));
        $process->setTimeout(40);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function run(object $repo): int
    {
        $probe = $this->measure('probe.txt', '');
        $changes = DB::select('SELECT ch.*, f.path FROM changes ch JOIN files f ON f.id=ch.file_id
   WHERE f.repo_id=? AND NOT EXISTS(SELECT FROM analysis_runs a WHERE a.change_id=ch.id AND a.engine=?)
   ORDER BY ch.id LIMIT 15', [$repo->id, $probe['engine']]);
        foreach ($changes as $change) {
            $data = $this->measure($change->path, '');
            if ($change->kind === 'removed') {
                $data['status'] = 'removed';
            } elseif ($data['status'] !== 'unsupported') {
                $source = DB::table('source_blobs')->where('sha', $change->blob_sha)->value('content');
                if ($source === null && $repo->source_key !== 'sample' && $change->blob_sha) {
                    $blob = app(RepositoryImporter::class)->request('/repos/'.$repo->full_name.'/git/blobs/'.$change->blob_sha)->json();
                    if (($blob['size'] ?? 0) > 300000 || ($blob['encoding'] ?? '') !== 'base64') {
                        $data['status'] = 'unavailable';
                        $data['reason'] = 'File exceeds 300 KB or encoding is unsupported';
                    } else {
                        $source = base64_decode($blob['content'], true);
                    }
                }
                if ($source === null || $source === false) {
                    if ($data['status'] !== 'unavailable') {
                        // Leave transient failures retryable instead of calling missing source healthy.
                        throw ValidationException::withMessages(['analysis' => 'Source unavailable for '.$change->path.'. No result was recorded; retry when source is available.']);
                    }
                } else {
                    $data = $this->measure($change->path, $source);
                }
            }
            DB::transaction(function () use ($change, $data) {
                $insert = DB::select('INSERT INTO analysis_runs(change_id,engine,language,status,reason,threshold) VALUES(?,?,?,?,?,?) ON CONFLICT DO NOTHING RETURNING id', [$change->id, $data['engine'], $data['language'], $data['status'], $data['reason'] ?? null, max(1, config('repochronos.threshold'))]);
                if (! $insert) {
                    return;
                }
                foreach ($data['functions'] as $function) {
                    DB::table('measurements')->insertOrIgnore(['run_id' => $insert[0]->id, 'function_name' => $function['name'], 'signature' => $function['signature'], 'line' => $function['line'], 'score' => $function['score']]);
                }
            });
        }

        return count($changes);
    }
}
