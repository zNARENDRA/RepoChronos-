<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DemoData
{
    public function load(User $owner): int
    {
        return DB::transaction(function () use ($owner) {
            DB::statement('SELECT pg_advisory_xact_lock(49721502)');
            DB::table('repositories')->insertOrIgnore(['source_key' => 'sample', 'full_name' => 'fieldnotes / notebook']);
            $repo = DB::table('repositories')->where('source_key', 'sample')->first();
            DB::table('repository_members')->insertOrIgnore(['repo_id' => $repo->id, 'user_id' => $owner->id, 'role' => 'owner']);
            $fixture = json_decode(file_get_contents(database_path('fixtures/legacy.json')), true);
            foreach (['branchy', 'normal'] as $key) {
                DB::table('source_blobs')->insertOrIgnore(['sha' => sha1($fixture[$key]), 'content' => $fixture[$key]]);
            }
            $names = ['Mira Shah', 'Leo Park', 'Ana Silva'];
            $seen = [];
            foreach ($fixture['entries'] as $i => [$days,$who,$message,$path]) {
                $source = $path === 'src/router.js' && $i !== 17 ? $fixture['branchy'] : $fixture['normal'];
                $at = now('UTC')->subDays($days)->setTime($days ? 9 + $i % 8 : 0, $i % 60);
                app(RepositoryImporter::class)->saveCommit($repo->id, ['sha' => sha1('fieldnotes-commit-'.$i),
                    'commit' => ['message' => $message, 'author' => ['name' => $names[$who], 'email' => 'author'.$who.'@fieldnotes.invalid', 'date' => $at->toIso8601String()]],
                    'files' => [['filename' => $path, 'status' => isset($seen[$path]) ? 'modified' : 'added', 'additions' => 4 + $i % 13, 'deletions' => isset($seen[$path]) ? $i % 5 : 0, 'sha' => sha1($source)]]]);
                $seen[$path] = true;
            }
            if (! DB::table('bugs')->where('repo_id', $repo->id)->exists()) {
                $id = DB::table('bugs')->insertGetId(['repo_id' => $repo->id, 'title' => 'Notebook count stays stale after archiving', 'details' => 'The sidebar still includes archived entries.', 'reporter' => 'Demo reporter', 'reporter_user_id' => $owner->id, 'reported_at' => now()->subDays(3)]);
                $fix = DB::table('commits')->where('repo_id', $repo->id)->where('sha', sha1('fieldnotes-commit-16'))->first();
                DB::table('bug_events')->insert(['bug_id' => $id, 'kind' => 'assigned', 'actor_user_id' => $owner->id, 'developer_id' => $fix->developer_id, 'at' => now()->subDays(2)]);
                DB::table('bug_events')->insert(['bug_id' => $id, 'kind' => 'fixed', 'actor_user_id' => $owner->id, 'commit_id' => $fix->id, 'at' => now()->subDay()]);
            }
            $sources = json_decode(file_get_contents(database_path('fixtures/multilingual.json')), true);
            foreach ($sources as $i => $file) {
                DB::table('source_blobs')->insertOrIgnore(['sha' => sha1($file['source']), 'content' => $file['source']]);
                app(RepositoryImporter::class)->saveCommit($repo->id, ['sha' => sha1('multilingual-'.$i), 'commit' => ['message' => $file['message'], 'author' => ['name' => $names[$i % 3], 'email' => 'author'.($i % 3).'@fieldnotes.invalid', 'date' => now()->subMinutes(60 - $i)->toIso8601String()]],
                    'files' => [['filename' => $file['path'], 'status' => $i === 1 ? 'modified' : 'added', 'additions' => 10, 'deletions' => 0, 'sha' => sha1($file['source'])]]]);
            }

            return $repo->id;
        });
    }
}
