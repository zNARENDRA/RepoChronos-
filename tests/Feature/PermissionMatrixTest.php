<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ComplexityAnalyzer;
use App\Services\RepositoryImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_role_and_unrelated_user_obey_repository_permissions(): void
    {
        config(['repochronos.allow_sample_data' => false]);
        $users = collect(['owner', 'editor', 'viewer', 'outsider'])->mapWithKeys(fn ($role) => [$role => User::factory()->create()]);
        $repo = DB::table('repositories')->insertGetId(['source_key' => 'github:permission-'.bin2hex(random_bytes(6)), 'full_name' => 'verification/permissions']);
        foreach (['owner', 'editor', 'viewer'] as $role) {
            DB::table('repository_members')->insert(['repo_id' => $repo, 'user_id' => $users[$role]->id, 'role' => $role]);
        }
        $developer = DB::table('developers')->insertGetId(['identity_key' => 'permissions:'.$repo, 'display_name' => 'Verification contributor']);
        $commit = DB::table('commits')->insertGetId(['repo_id' => $repo, 'developer_id' => $developer, 'sha' => sha1('permissions:'.$repo), 'authored_at' => now(), 'message' => 'Permission fixture']);
        $this->mock(ComplexityAnalyzer::class, function ($mock) {
            $mock->shouldReceive('engine')->andReturn('permission-verification');
            $mock->shouldReceive('run')->twice()->andReturn(0);
        });
        $this->mock(RepositoryImporter::class)->shouldReceive('import')->twice()->andReturn('Permission verified');

        foreach (['owner', 'editor', 'viewer'] as $role) {
            $this->actingAs($users[$role]);
            foreach (['overview', 'history', 'analysis', 'compare', 'bugs', 'sql'] as $tab) {
                $this->get("/r/$repo/$tab")->assertOk();
            }
            if ($role === 'viewer') {
                continue;
            }
            $this->post("/r/$repo/import")->assertRedirect();
            $this->post("/r/$repo/analysis")->assertRedirect();
            $this->post("/r/$repo/bugs", ['title' => "$role report"])->assertRedirect();
            $bug = DB::table('bugs')->where('repo_id', $repo)->orderByDesc('id')->first();
            $this->assertEquals($users[$role]->id, $bug->reporter_user_id);
            foreach ([['assigned', $developer], ['progress', null], ['fixed', $commit]] as [$kind, $target]) {
                $this->post("/r/$repo/bugs/$bug->id", ['kind' => $kind, 'target' => $target])->assertRedirect();
            }
            $this->assertEquals($users[$role]->name, DB::table('v_bug_current')->where('id', $bug->id)->value('resolved_by'));
        }

        foreach (['viewer', 'outsider'] as $role) {
            $this->actingAs($users[$role]);
            foreach (['import', 'analysis', 'bugs', 'members'] as $action) {
                $this->post("/r/$repo/$action", ['title' => 'Denied'])->assertForbidden();
            }
            foreach (['assigned', 'progress', 'fixed'] as $kind) {
                $this->post("/r/$repo/bugs/$bug->id", ['kind' => $kind, 'target' => $commit])->assertForbidden();
            }
        }
        foreach (['overview', 'history', 'analysis', 'compare', 'bugs', 'sql'] as $tab) {
            $this->actingAs($users['outsider'])->get("/r/$repo/$tab")->assertForbidden();
        }
        $this->actingAs($users['editor'])->post("/r/$repo/members", ['email' => $users['outsider']->email, 'role' => 'editor'])->assertForbidden();
        $this->actingAs($users['owner'])->post("/r/$repo/members", ['email' => $users['outsider']->email, 'role' => 'viewer'])->assertRedirect();
        $this->actingAs($users['outsider'])->get("/r/$repo/history")->assertOk();
        $this->post("/r/$repo/bugs", ['title' => 'Denied after invitation'])->assertForbidden();
        $this->actingAs($users['owner'])->post("/r/$repo/members", ['email' => $users['editor']->email, 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->assertEquals(2, DB::table('bugs')->where('repo_id', $repo)->count());
        $this->assertEquals(6, DB::table('bug_events')->whereIn('bug_id', DB::table('bugs')->where('repo_id', $repo)->select('id'))->count());
    }
}
