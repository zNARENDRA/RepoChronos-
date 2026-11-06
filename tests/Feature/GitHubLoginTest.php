<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as GitHubUser;
use Mockery;
use Tests\TestCase;

class GitHubLoginTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.github.client_id' => 'oauth-test-client', 'services.github.client_secret' => 'oauth-test-secret',
            'services.github.redirect' => 'http://localhost/auth/github/callback']);
    }

    private function identity(string $id, ?string $email = null): void
    {
        $identity = (new GitHubUser)->map(['id' => $id, 'name' => 'GitHub test user', 'nickname' => 'github-test', 'email' => $email]);
        $identity->token = 'never-store-this-oauth-token';
        $provider = Mockery::mock(GithubProvider::class);
        $provider->shouldReceive('setScopes')->with(['user:email'])->andReturnSelf();
        $provider->shouldReceive('enablePKCE')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);
    }

    public function test_real_provider_redirect_and_callback_use_state_pkce_and_verified_primary_email(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continue with GitHub');
        $response = $this->get('/auth/github')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('github.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
        $this->assertSame('user:email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('state'), $query['state']);
        $verifier = session('code_verifier');
        $this->assertNotEmpty($verifier);

        $email = 'oauth-'.bin2hex(random_bytes(6)).'@example.test';
        $queue = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'short-lived-test-token', 'token_type' => 'bearer', 'scope' => 'user:email'])),
            new Response(200, [], json_encode(['id' => 987654321012, 'login' => 'test-user', 'name' => 'Test user', 'avatar_url' => '', 'node_id' => 'node', 'email' => 'ignore-unverified-profile@example.test'])),
            new Response(200, [], json_encode([
                ['email' => 'unverified@example.test', 'primary' => true, 'verified' => false],
                ['email' => $email, 'primary' => true, 'verified' => true],
            ])),
        ]);
        $history = [];
        $stack = HandlerStack::create($queue);
        $stack->push(Middleware::history($history));
        // A fresh provider receives the callback's request and session, like a real HTTP request.
        $this->app->make(Factory::class)->forgetDrivers();
        $this->app->make(Factory::class)->extend('github', fn ($app) => (new GithubProvider($app['request'], 'oauth-test-client', 'oauth-test-secret', 'http://localhost/auth/github/callback'))
            ->setHttpClient(new Client(['handler' => $stack])));
        $this->get('/auth/github/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertRedirect('/');
        $user = User::where('github_id', '987654321012')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($email, $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, DB::table('repository_members')->where('user_id', $user->id)->count());
        $this->assertStringNotContainsString('short-lived-test-token', json_encode($user->getAttributes()));
        parse_str((string) $history[0]['request']->getBody(), $tokenRequest);
        $this->assertSame($verifier, $tokenRequest['code_verifier']);
        $this->assertNull(session('state'));
        $this->assertNull(session('code_verifier'));
    }

    public function test_missing_configuration_and_cancelled_authorization_keep_password_login_available(): void
    {
        config(['services.github.client_secret' => null]);
        $this->get('/auth/github')->assertRedirect('/login')->assertSessionHasErrors('github');
        $this->get('/auth/github/callback')->assertRedirect('/login')->assertSessionHasErrors('github');
        config(['services.github.client_secret' => 'test-secret']);
        $this->withSession(['state' => 'discard'])->get('/auth/github/callback?error=access_denied')->assertRedirect('/login')->assertSessionHasErrors('github');
        $this->assertGuest();
        $this->assertNull(session('state'));
    }

    public function test_invalid_state_is_rejected_before_contacting_github(): void
    {
        $this->withSession(['state' => 'expected', 'code_verifier' => 'test-verifier'])->get('/auth/github/callback?state=wrong&code=test')
            ->assertRedirect('/login')->assertSessionHasErrors('github');
        $this->assertGuest();
        $this->assertNull(session('code_verifier'));
    }

    public function test_existing_identity_logs_into_the_same_user_without_changing_email_or_memberships(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['github_id' => '100001'])->save();
        $email = $user->email;
        $password = $user->password;
        $count = User::count();
        $this->identity('100001', 'different-email@example.test');
        $this->get('/auth/github/callback')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($count, User::count());
        $this->assertSame($email, $user->fresh()->email);
        $this->assertSame($password, $user->fresh()->password);
    }

    public function test_matching_email_does_not_automatically_link_an_existing_account(): void
    {
        $user = User::factory()->create();
        $this->identity('100002', strtoupper($user->email));
        $count = User::count();
        $this->get('/auth/github/callback')->assertRedirect('/login')->assertSessionHasErrors('github');
        $this->assertGuest();
        $this->assertNull($user->fresh()->github_id);
        $this->assertSame($count, User::count());
    }

    public function test_new_account_requires_a_verified_primary_email(): void
    {
        $count = User::count();
        $this->identity('100003');
        $this->get('/auth/github/callback')->assertRedirect('/login')->assertSessionHasErrors('github');
        $this->assertGuest();
        $this->assertSame($count, User::count());
    }

    public function test_authenticated_user_can_explicitly_link_and_keep_password_and_repository_access(): void
    {
        $password = 'existing-password-123';
        $user = User::factory()->create(['password' => $password]);
        $repo = DB::table('repositories')->insertGetId(['source_key' => 'github:link-'.bin2hex(random_bytes(6)), 'full_name' => 'test/oauth-link']);
        DB::table('repository_members')->insert(['repo_id' => $repo, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Link GitHub for sign-in');
        $this->identity('100004', $user->email);
        $this->withSession(['github_link_user_id' => (string) $user->id])->get('/auth/github/callback')->assertRedirect('/');
        $this->assertSame('100004', $user->fresh()->github_id);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertSame('owner', DB::table('repository_members')->where('repo_id', $repo)->where('user_id', $user->id)->value('role'));
        $this->get('/')->assertOk()->assertSee('GitHub sign-in linked')->assertDontSee('Link GitHub for sign-in');
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/auth/github/callback')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_link_start_requires_authentication_and_stores_an_explicit_intent(): void
    {
        $this->post('/auth/github/link')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->post('/auth/github/link')->assertRedirect();
        $this->assertSame((string) $user->id, session('github_link_user_id'));
        $this->assertNotEmpty(session('state'));
    }

    public function test_identity_owned_by_another_account_cannot_be_linked(): void
    {
        $first = User::factory()->create();
        $first->forceFill(['github_id' => '100005'])->save();
        $second = User::factory()->create();
        $this->identity('100005');
        $this->actingAs($second)->withSession(['github_link_user_id' => (string) $second->id])
            ->get('/auth/github/callback')->assertRedirect('/')->assertSessionHasErrors('github');
        $this->assertNull($second->fresh()->github_id);
        $this->assertSame('100005', $first->fresh()->github_id);
        $this->assertAuthenticatedAs($second);
    }

    public function test_callback_cannot_switch_an_authenticated_account_without_link_intent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/auth/github/callback')->assertRedirect('/')->assertSessionHasErrors('github');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->github_id);
    }

    public function test_provider_failure_does_not_expose_credentials(): void
    {
        $provider = Mockery::mock(GithubProvider::class);
        $provider->shouldReceive('setScopes')->andReturnSelf();
        $provider->shouldReceive('enablePKCE')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('token=private-oauth-token client_secret=private-secret'));
        Socialite::shouldReceive('driver')->andReturn($provider);
        $this->get('/auth/github/callback')->assertRedirect('/login')->assertSessionHasErrors('github');
        $this->get('/login')->assertOk()->assertDontSee('private-oauth-token')->assertDontSee('private-secret');
        $this->assertGuest();
    }
}
