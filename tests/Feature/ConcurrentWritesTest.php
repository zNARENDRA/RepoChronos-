<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentWritesTest extends TestCase
{
    private ?string $verificationSchema = null;

    private ?User $verificationOwner = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Separate sessions need committed fixtures. Keep them out of the app's history.
        $this->verificationSchema = 'rc_verify_'.bin2hex(random_bytes(6));
        $schema = $this->verificationSchema;
        DB::transaction(function () use ($schema) {
            DB::unprepared(str_replace('repochronos', $schema, file_get_contents(base_path('db/schema.sql'))));
            DB::unprepared(str_replace('repochronos', $schema, file_get_contents(base_path('db/upgrade.sql'))));
        });
        DB::statement('SET search_path TO public, '.$schema);
    }

    protected function tearDown(): void
    {
        try {
            DB::statement('SET search_path TO public, repochronos');
            if ($this->verificationSchema !== null) {
                DB::statement('DROP SCHEMA IF EXISTS '.$this->verificationSchema.' CASCADE');
            }
            $this->verificationOwner?->delete();
        } finally {
            parent::tearDown();
        }
    }

    private function race(array $payload): array
    {
        $gate = tempnam(sys_get_temp_dir(), 'rc-gate-');
        unlink($gate);
        $payload['gate'] = $gate;
        $payload['schema'] = $this->verificationSchema;
        $workers = [];
        try {
            foreach (range(1, 2) as $i) {
                $p = new Process([PHP_BINARY, base_path('tests/concurrent-worker.php')], base_path(), ['APP_ENV' => 'testing']);
                $p->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
                $p->setTimeout(60);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 20;
            while (count(array_filter($workers, fn ($p) => str_contains($p->getOutput(), 'READY'))) !== 2) {
                foreach ($workers as $p) {
                    if (! $p->isRunning()) {
                        $this->fail($p->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not become ready.');
                }
                usleep(10000);
            }
            touch($gate);
            foreach ($workers as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            }

            return array_map(fn ($p) => $p->getOutput(), $workers);
        } finally {
            foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            if (file_exists($gate)) {
                unlink($gate);
            }
        }
    }

    public function test_simultaneous_import_analysis_and_fix_preserve_one_history(): void
    {
        $owner = $this->verificationOwner = User::factory()->create();
        $repo = DB::table('repositories')->insertGetId(['source_key' => 'race:'.bin2hex(random_bytes(8)), 'full_name' => 'Concurrent verification']);
        DB::table('repository_members')->insert(['repo_id' => $repo, 'user_id' => $owner->id, 'role' => 'owner']);
        $source = "def score(x):\n    if x: return 1\n    return 0\n";
        $blob = sha1($source);
        DB::table('source_blobs')->insertOrIgnore(['sha' => $blob, 'content' => $source]);
        $item = ['sha' => sha1('race-'.$repo), 'commit' => ['message' => 'Concurrent import', 'author' => ['name' => 'Verification author', 'email' => 'verification-'.$repo.'@example.test', 'date' => now()->toIso8601String()]],
            'files' => [['filename' => 'score.py', 'status' => 'added', 'additions' => 3, 'deletions' => 0, 'sha' => $blob]]];
        $this->race(['op' => 'commit', 'repo' => $repo, 'commit' => $item]);
        $this->assertEquals(1, DB::table('commits')->where('repo_id', $repo)->count());
        $commit = DB::table('commits')->where('repo_id', $repo)->first();
        $change = DB::table('changes')->where('commit_id', $commit->id)->first();
        $this->assertEquals(1, DB::table('changes')->where('commit_id', $commit->id)->count());
        $this->race(['op' => 'scan', 'repo' => $repo]);
        $run = DB::table('analysis_runs')->where('change_id', $change->id)->sole();
        $this->assertEquals(1, DB::table('measurements')->where('run_id', $run->id)->count());
        $this->assertEquals(2, DB::table('measurements')->where('run_id', $run->id)->value('score'));
        $bug = DB::table('bugs')->insertGetId(['repo_id' => $repo, 'title' => 'Concurrent resolution', 'reporter' => $owner->name, 'reporter_user_id' => $owner->id]);
        DB::table('bug_events')->insert(['bug_id' => $bug, 'kind' => 'assigned', 'developer_id' => $commit->developer_id, 'actor_user_id' => $owner->id]);
        $outputs = $this->race(['op' => 'fix', 'bug' => $bug, 'commit' => $commit->id, 'actor' => $owner->id]);
        $this->assertEquals(1, count(array_filter($outputs, fn ($s) => str_contains($s, 'CONFLICT_REJECTED'))));
        $this->assertEquals(1, DB::table('bug_events')->where('bug_id', $bug)->where('kind', 'fixed')->count());
        $this->assertEquals('Fixed', DB::table('v_bug_current')->where('id', $bug)->value('status'));
    }
}
