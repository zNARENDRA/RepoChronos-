<?php

namespace App\Services;

use App\Exceptions\AnalysisUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class ComplexityAnalyzer
{
    public function measure(string $path, string $source): array
    {
        return $this->invoke(['path' => $path, 'source' => $source]);
    }

    public function measureMany(array $files): array
    {
        return $this->invoke(['files' => array_values($files)]);
    }

    private function invoke(array $payload): array
    {
        $process = new Process([config('repochronos.python'), base_path('scripts/analyze.py')], base_path());
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(isset($payload['files']) ? 80 : 40);
        try {
            $process->mustRun();

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $error) {
            throw new AnalysisUnavailable('The analyzer is unavailable. Check the configured Python environment.', 0, $error);
        }
    }

    private function engineCacheKey(): string
    {
        $python = config('repochronos.python');

        return 'lizard-engine:'.hash('sha256', $python.'|'.(is_file($python) ? filemtime($python) : '')
            .'|'.hash_file('sha256', base_path('scripts/analyze.py'))
            .'|'.hash_file('sha256', base_path('requirements.txt')));
    }

    public function engine(bool $refresh = false): string
    {
        $key = $this->engineCacheKey();
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, fn () => $this->measure('probe.txt', '')['engine']);
    }

    public function run(object $repo): int
    {
        $probe = $this->measure('probe.txt', '');
        Cache::put($this->engineCacheKey(), $probe['engine'], 60);
        $changes = DB::select('SELECT ch.*, f.path, b.content AS source FROM changes ch JOIN files f ON f.id=ch.file_id
   LEFT JOIN source_blobs b ON b.sha=ch.blob_sha
   WHERE f.repo_id=? AND NOT EXISTS(SELECT FROM analysis_runs a WHERE a.change_id=ch.id AND a.engine=?)
   ORDER BY ch.id LIMIT 15', [$repo->id, $probe['engine']]);
        if (! $changes) {
            return 0;
        }
        $descriptions = $this->measureMany(array_map(fn ($change) => ['path' => $change->path, 'source' => ''], $changes));
        $results = [];
        $sources = [];
        foreach ($changes as $index => $change) {
            $data = $descriptions[$index];
            if ($change->kind === 'removed') {
                $data['status'] = 'removed';
            } elseif ($data['status'] !== 'unsupported') {
                $source = $change->source;
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
                    if (! mb_check_encoding($source, 'UTF-8')) {
                        $data['status'] = 'unavailable';
                        $data['reason'] = 'Source encoding is not UTF-8';
                    } else {
                        $sources[$change->id] = ['path' => $change->path, 'source' => $source];
                    }
                }
            }
            $results[$change->id] = $data;
        }
        if ($sources) {
            $measurements = $this->measureMany($sources);
            foreach (array_keys($sources) as $index => $changeId) {
                $results[$changeId] = $measurements[$index];
            }
        }
        $this->persist($results);

        return count($changes);
    }

    private function persist(array $results): void
    {
        // Commit the bounded batch atomically. Concurrent scans only insert
        // measurements for the runs they won; existing history is never edited.
        DB::transaction(function () use ($results) {
            $bindings = [];
            foreach ($results as $changeId => $data) {
                array_push($bindings, $changeId, $data['engine'], $data['language'], $data['status'],
                    $data['reason'] ?? null, max(1, config('repochronos.threshold')));
            }
            $values = implode(',', array_fill(0, count($results), '(?,?,?,?,?,?)'));
            $inserted = DB::select('INSERT INTO analysis_runs(change_id,engine,language,status,reason,threshold) VALUES '
                .$values.' ON CONFLICT DO NOTHING RETURNING id,change_id', $bindings);
            $rows = [];
            foreach ($inserted as $run) {
                foreach ($results[$run->change_id]['functions'] as $function) {
                    $rows[] = ['run_id' => $run->id, 'function_name' => $function['name'],
                        'signature' => $function['signature'], 'line' => $function['line'], 'score' => $function['score']];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('measurements')->insertOrIgnore($chunk);
            }
        }, 3);
    }
}
