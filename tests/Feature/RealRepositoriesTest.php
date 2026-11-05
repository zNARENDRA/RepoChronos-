<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealRepositoriesTest extends TestCase
{
    use DatabaseTransactions;

    private function sampleFor(User $user): object
    {
        DB::table('repositories')->insertOrIgnore(['source_key' => 'sample', 'full_name' => 'Preserved sample fixture']);
        $repo = DB::table('repositories')->where('source_key', 'sample')->first();
        DB::table('repository_members')->insert(['repo_id' => $repo->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $repo;
    }

    public function test_directory_only_shows_github_records_and_preserves_sample_history(): void
    {
        config(['repochronos.allow_sample_data' => false]);
        $user = User::factory()->create();
        $sample = $this->sampleFor($user);
        foreach (['github:'.random_int(1000000000, 2000000000) => 'integration/live-repository', 'race:'.bin2hex(random_bytes(5)) => 'Hidden verification fixture'] as $key => $name) {
            $repo = DB::table('repositories')->insertGetId(['source_key' => $key, 'full_name' => $name]);
            DB::table('repository_members')->insert(['repo_id' => $repo, 'user_id' => $user->id, 'role' => 'owner']);
        }
        $this->actingAs($user)->get('/')->assertOk()->assertSee('integration/live-repository')
            ->assertDontSee($sample->full_name)->assertDontSee('Hidden verification fixture')
            ->assertViewHas('repos', fn ($repos) => $repos->count() === 1);
        $this->assertTrue(DB::table('repositories')->where('id', $sample->id)->exists());
    }

    public function test_disabled_sample_cannot_be_viewed_or_modified_through_old_links(): void
    {
        config(['repochronos.allow_sample_data' => false]);
        $user = User::factory()->create();
        $repo = $this->sampleFor($user);
        $before = DB::table('bugs')->where('repo_id', $repo->id)->count();
        $this->actingAs($user)->get("/r/$repo->id/overview")->assertNotFound();
        $this->post("/r/$repo->id/analysis")->assertNotFound();
        $this->post("/r/$repo->id/bugs", ['title' => 'Must not be created'])->assertNotFound();
        $this->assertEquals($before, DB::table('bugs')->where('repo_id', $repo->id)->count());
    }

    public function test_demo_command_is_blocked_before_creating_an_account(): void
    {
        $email = 'blocked-seed-'.bin2hex(random_bytes(5)).'@example.test';
        config(['repochronos.allow_sample_data' => false, 'repochronos.demo_email' => $email]);
        $this->artisan('repochronos:demo')->assertExitCode(1);
        $this->assertFalse(User::where('email', $email)->exists());
    }

    public function test_standard_seeder_creates_no_application_data(): void
    {
        config(['repochronos.allow_sample_data' => true]);
        $before = DB::table('repositories')->count();
        $users = User::count();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertEquals($before, DB::table('repositories')->count());
        $this->assertEquals($users, User::count());
    }

    public function test_registration_starts_with_an_empty_real_workspace(): void
    {
        config(['repochronos.allow_sample_data' => false]);
        $before = DB::table('repositories')->count();
        $password = bin2hex(random_bytes(12));
        $this->post('/register', ['name' => 'Faculty presenter', 'email' => 'presenter-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => $password, 'password_confirmation' => $password])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee('No GitHub repositories connected yet.')
            ->assertViewHas('repos', fn ($repos) => $repos->isEmpty());
        $this->assertEquals($before, DB::table('repositories')->count());
    }
}
