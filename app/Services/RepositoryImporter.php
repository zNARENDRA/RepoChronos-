<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RepositoryImporter
{
    public function request(string $path, bool $allowEmpty = false)
    {
        $request = Http::acceptJson()->withHeaders(['User-Agent' => 'RepoChronos'])->timeout(30)->withOptions(['verify' => config('repochronos.github_ca') ?: true]);
        if (config('repochronos.github_token')) {
            $request = $request->withToken(config('repochronos.github_token'));
        }
        $response = $request->get('https://api.github.com'.$path);
        if ($allowEmpty && $response->status() === 409) {
            return $response;
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['github' => 'GitHub returned '.$response->status().'. Check access, name, connection and API quota, then retry.']);
        }

        return $response;
    }

    public function connect(string $input, int $userId): object
    {
        $name = preg_replace('#^https://github.com/#i', '', trim($input));
        $name = preg_replace('#(?:\.git)?/$#', '', $name);
        $name = preg_replace('/\.git$/', '', $name);
        if (! preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$#', $name) || in_array(basename($name), ['.', '..'])) {
            throw ValidationException::withMessages(['repository' => 'Use a GitHub owner/name or URL.']);
        }
        $meta = $this->request('/repos/'.$name)->json();

        return DB::transaction(function () use ($meta, $userId) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [(int) $meta['id']]);
            $key = 'github:'.$meta['id'];
            $repo = DB::table('repositories')->where('source_key', $key)->first();
            if ($repo) {
                abort_unless(DB::table('repository_members')->where('repo_id', $repo->id)->where('user_id', $userId)->whereIn('role', ['owner', 'editor'])->exists(), 403, 'Ask the repository owner to add you.');

                return $repo;
            }
            $id = DB::table('repositories')->insertGetId(['source_key' => $key, 'full_name' => $meta['full_name']]);
            DB::table('repository_members')->insert(['repo_id' => $id, 'user_id' => $userId, 'role' => 'owner']);

            return DB::table('repositories')->find($id);
        });
    }

    public function import(object $repo, ?string $head = null): string
    {
        $base = '/repos/'.$repo->full_name.'/commits';
        if (! $head) {
            $response = $this->request($base.'?per_page=1', true);
            if ($response->status() === 409) {
                return 'Repository is empty.';
            }
            $response->throw();
            $head = $response->json('0.sha');
        }
        if (! $head) {
            return 'No commits.';
        }
        abort_unless(preg_match('/^[a-f0-9]{40}$/', $head), 422);
        $last = DB::table('import_pages')->where('repo_id', $repo->id)->where('head_sha', $head)->orderByDesc('page')->first();
        if ($last && ! $last->more) {
            return 'Snapshot is already complete.';
        }
        $page = ($last->page ?? 0) + 1;
        $response = $this->request($base.'?sha='.$head.'&per_page=10&page='.$page);
        $count = 0;
        foreach ($response->json() as $summary) {
            if (DB::table('commits')->where('repo_id', $repo->id)->where('sha', $summary['sha'])->exists()) {
                continue;
            }
            $path = $base.'/'.$summary['sha'].'?per_page=100';
            $detail = $this->request($path);
            $item = $detail->json();
            for ($p = 2; str_contains($detail->header('Link') ?? '', 'rel="next"'); $p++) {
                $detail = $this->request($path.'&page='.$p);
                $item['files'] = array_merge($item['files'], $detail->json('files'));
            }
            if (count($item['files']) >= 3000) {
                throw ValidationException::withMessages(['github' => 'Commit file list exceeds the GitHub API limit; incomplete commit was not saved.']);
            }
            $count += (int) $this->saveCommit($repo->id, $item);
        }
        DB::table('import_pages')->insertOrIgnore(['repo_id' => $repo->id, 'head_sha' => $head, 'page' => $page, 'more' => str_contains($response->header('Link') ?? '', 'rel="next"')]);

        return "$count new commits imported. Continue import for more history.";
    }

    public function saveCommit(int $repoId, array $item): bool
    {
        return DB::transaction(function () use ($repoId, $item) {
            $author = $item['commit']['author'] ?? $item['commit']['committer'];
            $identity = isset($item['author']['id']) ? 'github:'.$item['author']['id'] : 'git:'.hash('sha256', strtolower(trim($author['email'] ?? $author['name'])));
            DB::table('developers')->insertOrIgnore(['identity_key' => $identity, 'display_name' => $author['name']]);
            $developer = DB::table('developers')->where('identity_key', $identity)->value('id');
            $inserted = DB::select('INSERT INTO commits(repo_id,sha,developer_id,authored_at,message) VALUES(?,?,?,?,?) ON CONFLICT DO NOTHING RETURNING id', [$repoId, $item['sha'], $developer, $author['date'], $item['commit']['message']]);
            if (! $inserted) {
                return false;
            }
            foreach ($item['files'] as $file) {
                $old = isset($file['previous_filename']) ? $this->file($repoId, $file['previous_filename']) : null;
                DB::table('changes')->insert(['commit_id' => $inserted[0]->id, 'file_id' => $this->file($repoId, $file['filename']), 'previous_file_id' => $old, 'kind' => $file['status'], 'additions' => $file['additions'], 'deletions' => $file['deletions'], 'blob_sha' => $file['sha'] ?? null]);
            }

            return true;
        });
    }

    private function file(int $repo, string $path): int
    {
        DB::table('files')->insertOrIgnore(['repo_id' => $repo, 'path' => $path]);

        return DB::table('files')->where('repo_id', $repo)->where('path', $path)->value('id');
    }
}
