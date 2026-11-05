<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ComplexityAnalyzer;
use App\Services\RepositoryImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use DatabaseTransactions;

    private function repository(): array
    {
        $user = User::factory()->create();
        $id = DB::table('repositories')->insertGetId(['source_key' => 'performance:'.bin2hex(random_bytes(6)), 'full_name' => 'Performance fixture']);
        DB::table('repository_members')->insert(['repo_id' => $id, 'user_id' => $user->id, 'role' => 'owner']);

        return [$user, $id];
    }

    public function test_dashboard_uses_two_queries_and_reads_new_records_immediately(): void
    {
        [$user, $id] = $this->repository();
        $this->actingAs($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get("/r/$id/overview")->assertOk()
            ->assertViewHas('totals', fn ($totals) => $totals->open === 0 && $totals->commits === 0);
        $this->assertCount(2, DB::getQueryLog(), implode("\n", array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        $this->post("/r/$id/bugs", ['title' => 'Live dashboard update'])->assertRedirect();
        $this->get("/r/$id/overview")->assertOk()
            ->assertViewHas('totals', fn ($totals) => $totals->open === 1);
        $head = sha1('checkpoint fixture');
        DB::table('import_pages')->insert(['repo_id' => $id, 'head_sha' => $head, 'page' => 1, 'more' => true]);
        $this->get("/r/$id/overview")->assertOk()->assertSee('Continue import')
            ->assertViewHas('checkpoint', fn ($checkpoint) => $checkpoint->head_sha === $head && $checkpoint->more);
        DB::table('repository_members')->where('repo_id', $id)->where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->get("/r/$id/overview")->assertOk()->assertDontSee('Continue import')->assertViewHas('checkpoint', null);
        DB::table('repository_members')->where('repo_id', $id)->where('user_id', $user->id)->delete();
        $this->get("/r/$id/overview")->assertForbidden();
    }

    public function test_bulk_import_keeps_renames_idempotency_and_bounded_query_count(): void
    {
        [, $repo] = $this->repository();
        $files = [];
        foreach (range(1, 501) as $n) {
            $files[] = ['filename' => "file$n.py", 'status' => 'added', 'additions' => 1, 'deletions' => 0];
        }
        $files[] = ['filename' => 'renamed.py', 'previous_filename' => 'old.py', 'status' => 'renamed', 'additions' => 0, 'deletions' => 0];
        $item = ['sha' => sha1('bulk import'), 'commit' => ['message' => 'Bulk rename fixture', 'author' => ['name' => 'Bulk author', 'email' => 'bulk@example.test', 'date' => now()->toIso8601String()]], 'files' => $files];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(app(RepositoryImporter::class)->saveCommit($repo, $item));
        $this->assertLessThanOrEqual(9, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertEquals(502, DB::table('v_file_history')->where('repo_id', $repo)->count());
        $rename = DB::table('v_file_history')->where('repo_id', $repo)->where('path', 'renamed.py')->sole();
        $this->assertEquals('old.py', $rename->previous_path);
        $this->assertFalse(app(RepositoryImporter::class)->saveCommit($repo, $item));
        $this->assertEquals(502, DB::table('v_file_history')->where('repo_id', $repo)->count());
    }

    public function test_batch_analysis_keeps_all_measurements_and_explicit_file_statuses(): void
    {
        [, $id] = $this->repository();
        $source = "def choose(x):\n    if x: return 1\n    return 0\n";
        $sha = sha1($source);
        DB::table('source_blobs')->insertOrIgnore(['sha' => $sha, 'content' => $source]);
        $files = [];
        foreach (range(1, 12) as $n) {
            $files[] = ['filename' => "function$n.py", 'status' => 'added', 'additions' => 3, 'deletions' => 0, 'sha' => $sha];
        }
        $files[] = ['filename' => 'readme.txt', 'status' => 'added', 'additions' => 1, 'deletions' => 0];
        $files[] = ['filename' => 'removed.py', 'status' => 'removed', 'additions' => 0, 'deletions' => 1];
        $files[] = ['filename' => 'empty.py', 'status' => 'added', 'additions' => 0, 'deletions' => 0, 'sha' => sha1('')];
        DB::table('source_blobs')->insertOrIgnore(['sha' => sha1(''), 'content' => '']);
        app(RepositoryImporter::class)->saveCommit($id, ['sha' => sha1('batch scan'), 'commit' => ['message' => 'Batch fixture', 'author' => ['name' => 'Batch author', 'email' => 'batch@example.test', 'date' => now()->toIso8601String()]], 'files' => $files]);
        $repo = DB::table('repositories')->find($id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertEquals(15, app(ComplexityAnalyzer::class)->run($repo));
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $history = DB::table('v_measurement_history')->where('repo_id', $id);
        $this->assertEquals(12, (clone $history)->where('score', 2)->where('warning', false)->count());
        $this->assertEquals(1, (clone $history)->where('analysis_status', 'unsupported')->count());
        $this->assertEquals(1, (clone $history)->where('analysis_status', 'removed')->count());
        $this->assertTrue((clone $history)->where('path', 'empty.py')->where('analysis_status', 'analyzed')->whereNull('measurement_id')->exists());
        $this->assertEquals(0, app(ComplexityAnalyzer::class)->run($repo));
        $this->assertEquals(12, (clone $history)->whereNotNull('measurement_id')->count());
    }
}
