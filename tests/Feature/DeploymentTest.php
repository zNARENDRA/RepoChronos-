<?php

namespace Tests\Feature;

use App\Exceptions\AnalysisUnavailable;
use App\Models\User;
use App\Services\ComplexityAnalyzer;
use App\Services\RepositoryImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bad_route_identifiers_are_not_server_errors(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/r/nope', '/r/9999999999', '/r/0'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_unavailable_python_has_a_safe_service_response(): void
    {
        $user = User::factory()->create();
        $id = DB::table('repositories')->insertGetId(['source_key' => 'error:'.bin2hex(random_bytes(6)), 'full_name' => 'Error fixture']);
        DB::table('repository_members')->insert(['repo_id' => $id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->mock(ComplexityAnalyzer::class)->shouldReceive('engine')->andThrow(new AnalysisUnavailable('The analyzer is unavailable.'));
        $this->actingAs($user)->get("/r/$id/overview")->assertStatus(503)->assertSee('The analyzer is unavailable.')->assertDontSee('Stack trace');
    }

    public function test_github_connection_failure_is_retryable_validation(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(ValidationException::class);
        app(RepositoryImporter::class)->request('/repos/example/repo');
    }

    public function test_get_database_error_does_not_redirect_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::shouldReceive('table')->once()->andThrow(new QueryException('pgsql', 'sensitive query', [], new \PDOException('private failure', 23514)));
        $this->get('/')->assertStatus(503)->assertDontSee('sensitive query')->assertDontSee('private failure');
    }

    public function test_health_checks_the_database(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_production_preflight_rejects_development_settings(): void
    {
        config(['app.env' => 'local', 'app.url' => 'http://localhost', 'session.secure' => false]);
        $this->artisan('repochronos:doctor --production')->assertExitCode(1);
    }

    public function test_oversized_passwords_produce_validation_errors(): void
    {
        $password = str_repeat('a', 73);
        $this->post('/register', ['name' => 'Validation check', 'email' => 'length-check@example.test', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->post('/login', ['email' => 'length-check@example.test', 'password' => $password])->assertSessionHasErrors('password');
        $unicode = str_repeat(chr(195).chr(169), 40);
        $this->post('/register', ['name' => 'Validation check', 'email' => 'unicode-check@example.test', 'password' => $unicode, 'password_confirmation' => $unicode])->assertSessionHasErrors('password');
    }

    public function test_non_utf8_source_is_recorded_as_unavailable(): void
    {
        $repo = DB::table('repositories')->insertGetId(['source_key' => 'encoding:'.bin2hex(random_bytes(6)), 'full_name' => 'encoding/fixture']);
        $source = base64_encode(chr(255));
        Http::fake(['*' => Http::response(['size' => 1, 'encoding' => 'base64', 'content' => $source])]);
        app(RepositoryImporter::class)->saveCommit($repo, ['sha' => str_repeat('e', 40), 'commit' => ['message' => 'Non UTF-8 fixture', 'author' => ['name' => 'Encoding author', 'email' => 'encoding@example.test', 'date' => now()->toIso8601String()]], 'files' => [['filename' => 'source.py', 'status' => 'added', 'additions' => 1, 'deletions' => 0, 'sha' => str_repeat('f', 40)]]]);
        app(ComplexityAnalyzer::class)->run(DB::table('repositories')->find($repo));
        $change = DB::table('v_file_history')->where('repo_id', $repo)->first();
        $this->assertEquals('unavailable', DB::table('analysis_runs')->where('change_id', $change->change_id)->value('status'));
    }

    public function test_pending_coverage_does_not_depend_on_the_demo_state(): void
    {
        $user = User::factory()->create();
        $repo = DB::table('repositories')->insertGetId(['source_key' => 'pending:'.bin2hex(random_bytes(6)), 'full_name' => 'Pending fixture']);
        DB::table('repository_members')->insert(['repo_id' => $repo, 'user_id' => $user->id, 'role' => 'owner']);
        app(RepositoryImporter::class)->saveCommit($repo, ['sha' => str_repeat('c', 40), 'commit' => ['message' => 'Pending source', 'author' => ['name' => 'Pending author', 'email' => 'pending@example.test', 'date' => now()->toIso8601String()]], 'files' => [['filename' => 'pending.py', 'status' => 'added', 'additions' => 1, 'deletions' => 0, 'sha' => str_repeat('b', 40)]]]);
        $this->actingAs($user)->get("/r/$repo/analysis")->assertOk()->assertSee('not analyzed')->assertViewHas('pending', 1);
    }
}
