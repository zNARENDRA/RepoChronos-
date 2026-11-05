<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ComplexityAnalyzer;
use App\Services\DemoData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_complete_authenticated_ledger(): void
    {
        $user = User::factory()->create();
        $id = app(DemoData::class)->load($user);
        $this->get('/')->assertRedirect('/login');
        $this->actingAs($user);
        foreach (['overview', 'history', 'analysis', 'compare', 'bugs', 'sql'] as $tab) {
            $this->get("/r/$id/$tab")->assertOk();
        }
        $this->post("/r/$id/analysis")->assertRedirect();
        $this->post("/r/$id/analysis")->assertRedirect();
        $count = DB::table('measurements')->count();
        $this->assertGreaterThan(10, $count);
        $this->post("/r/$id/analysis")->assertRedirect();
        $this->assertEquals($count, DB::table('measurements')->count());
        foreach (['python', 'java', 'c', 'cpp', 'php'] as $language) {
            $this->assertTrue(DB::table('analysis_runs')->whereRaw('lower(language)=?', [strtolower($language)])->exists(), $language);
        }
        $this->assertTrue(DB::table('analysis_runs')->where('status', 'unsupported')->exists());
        $this->assertTrue(DB::table('measurements')->where('score', 1)->exists());
        $file = DB::table('files')->where('repo_id', $id)->where('path', 'src/route.py')->first();
        $commits = DB::table('v_file_history')->where('repo_id', $id)->where('path', 'src/route.py')->orderBy('authored_at')->pluck('commit_id');
        $this->get("/r/$id/compare?file=$file->id&before={$commits[0]}&after={$commits[1]}")->assertOk()->assertSee('route');
        $change = DB::table('v_file_history')->where('commit_id', $commits[1])->where('path', 'src/route.py')->first();
        $run = DB::table('analysis_runs')->insertGetId(['change_id' => $change->change_id, 'engine' => 'historical-test-version', 'language' => 'python', 'status' => 'analyzed', 'threshold' => 5]);
        DB::table('measurements')->insert(['run_id' => $run, 'signature' => 'route( entry )', 'function_name' => 'route', 'line' => 1, 'score' => 99]);
        $this->get("/r/$id/compare?file=$file->id&before={$commits[0]}&after={$commits[1]}")
            ->assertViewHas('deltas', fn ($rows) => $rows->first()->delta === 5);
        $this->get("/r/$id/overview")->assertViewHas('trend', fn ($rows) => $rows->max('max_complexity') < 99);
        $this->get("/r/$id/compare?file=$file->id&before=99999999&after={$commits[1]}")->assertStatus(422);
        $this->post("/r/$id/bugs", ['title' => 'Focus regression', 'details' => 'Reproduction steps'])->assertRedirect();
        $bug = DB::table('bugs')->orderByDesc('id')->first();
        $developer = DB::table('v_contributors')->where('repo_id', $id)->first();
        $this->post("/r/$id/bugs/$bug->id", ['kind' => 'assigned', 'target' => $developer->id])->assertRedirect();
        $this->post("/r/$id/bugs/$bug->id", ['kind' => 'progress'])->assertRedirect();
        $commit = DB::table('commits')->where('repo_id', $id)->first();
        $this->post("/r/$id/bugs/$bug->id", ['kind' => 'fixed', 'target' => $commit->id])->assertRedirect();
        $status = DB::table('v_bug_current')->where('id', $bug->id)->first();
        $this->assertEquals('Fixed', $status->status);
        $this->assertEquals($user->name, $status->resolved_by);
        $this->assertGreaterThanOrEqual(0, $status->resolution_seconds);
        $this->assertEquals(3, DB::table('bug_events')->where('bug_id', $bug->id)->count());
        $this->post("/r/$id/bugs/$bug->id", ['kind' => 'progress'])->assertSessionHasErrors('database');
        app(DemoData::class)->load($user);
        $this->assertEquals(25, DB::table('commits')->where('repo_id', $id)->count());
    }

    public function test_registration_login_and_logout(): void
    {
        $email = 'auth-'.bin2hex(random_bytes(5)).'@example.test';
        $password = bin2hex(random_bytes(12));
        $this->post('/register', ['name' => 'Tester', 'email' => $email, 'password' => $password, 'password_confirmation' => $password])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $email, 'password' => 'incorrect'])->assertSessionHasErrors();
        $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_access_permissions_are_enforced(): void
    {
        $owner = User::factory()->create();
        $id = app(DemoData::class)->load($owner);
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($owner)->post("/r/$id/members", ['email' => $viewer->email, 'role' => 'viewer'])->assertRedirect();
        $this->actingAs($viewer)->get("/r/$id")->assertOk();
        foreach (['analysis', 'import', 'bugs', 'members'] as $action) {
            $this->post("/r/$id/$action")->assertForbidden();
        }
        $this->actingAs($stranger)->get("/r/$id")->assertForbidden();
        $this->actingAs($owner)->post("/r/$id/members", ['email' => $stranger->email, 'role' => 'editor'])->assertRedirect();
        $this->actingAs($stranger)->post("/r/$id/bugs", ['title' => 'Editor report'])->assertRedirect();
        $bug = DB::table('bugs')->orderByDesc('id')->first();
        $developer = DB::table('v_contributors')->where('repo_id', $id)->first();
        $this->post("/r/$id/bugs/$bug->id", ['kind' => 'assigned', 'target' => $developer->id])->assertRedirect();
        $other = DB::table('repositories')->insertGetId(['source_key' => 'other-fixture', 'full_name' => 'other/project']);
        $foreign = DB::table('commits')->insertGetId(['repo_id' => $other, 'sha' => str_repeat('d', 40), 'developer_id' => $developer->id, 'authored_at' => now(), 'message' => 'Other repository']);
        $this->post("/r/$id/bugs/$bug->id", ['kind' => 'fixed', 'target' => $foreign])->assertSessionHasErrors('database');
        $this->assertEquals('Assigned', DB::table('v_bug_current')->where('id', $bug->id)->value('status'));

    }

    public function test_github_import_uses_api_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $sha = str_repeat('a', 40);
        Http::fake(function ($request) use ($sha) {
            if (str_contains($request->url(), '/commits/'.$sha)) {
                return Http::response(['sha' => $sha, 'commit' => ['message' => 'realistic fixture', 'author' => ['name' => 'Developer', 'email' => 'dev@example.test', 'date' => '2026-01-01T00:00:00Z']], 'files' => [['filename' => 'src/helper.py', 'status' => 'added', 'additions' => 2, 'deletions' => 0, 'sha' => str_repeat('b', 40)]]]);
            }
            if (str_contains($request->url(), '/commits')) {
                return Http::response([['sha' => $sha]]);
            }

            return Http::response(['id' => 987123, 'full_name' => 'example/project']);
        });
        $this->actingAs($user)->post('/repositories', ['repository' => 'example/project'])->assertRedirect();
        $repo = DB::table('repositories')->where('source_key', 'github:987123')->first();
        $this->post("/r/$repo->id/import")->assertRedirect();
        $this->post("/r/$repo->id/import")->assertRedirect();
        $this->assertEquals(1, DB::table('commits')->where('repo_id', $repo->id)->count());
    }

    public function test_history_updates_are_rejected(): void
    {
        $user = User::factory()->create();
        app(DemoData::class)->load($user);
        $this->expectException(QueryException::class);
        DB::table('commits')->update(['message' => 'overwritten']);
    }

    public function test_sql_constraints_reject_history_edits_and_cross_repository_links(): void
    {
        $user = User::factory()->create();
        $repo = app(DemoData::class)->load($user);
        foreach (['UPDATE commits SET message=message', 'DELETE FROM warnings', 'TRUNCATE measurements'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('History edit was allowed.');
            } catch (QueryException $error) {
                $this->assertEquals('23514', $error->getCode());
            }
        }
        $other = DB::table('repositories')->insertGetId(['source_key' => 'foreign:'.bin2hex(random_bytes(4)), 'full_name' => 'Foreign fixture']);
        $file = DB::table('files')->insertGetId(['repo_id' => $other, 'path' => 'foreign.py']);
        $commit = DB::table('commits')->where('repo_id', $repo)->first();
        try {
            DB::transaction(fn () => DB::table('changes')->insert(['commit_id' => $commit->id, 'file_id' => $file, 'kind' => 'added', 'additions' => 1, 'deletions' => 0]));
            $this->fail('Cross-repository file link was allowed.');
        } catch (QueryException $error) {
            $this->assertEquals('23514', $error->getCode());
        }
    }

    public function test_analyzer_measures_real_source(): void
    {
        $analyzer = app(ComplexityAnalyzer::class);
        $result = $analyzer->measure('source.py', "def choose(x):\n    if x: return 1\n    return 0\n");
        $this->assertEquals(2, $result['functions'][0]['score']);
        $this->assertEquals('unsupported', $analyzer->measure('notes.txt', 'hello')['status']);
    }
}
