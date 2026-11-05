<?php

namespace Tests\Feature;

use App\Services\ComplexityAnalyzer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StartupCheckTest extends TestCase
{
    public function test_startup_retries_a_temporary_database_failure(): void
    {
        DB::shouldReceive('select')->once()->ordered()->andThrow(new \RuntimeException('Temporary connection failure'));
        DB::shouldReceive('purge')->once()->ordered();
        DB::shouldReceive('select')->once()->ordered()->andReturn([]);
        $this->mock(ComplexityAnalyzer::class)->shouldReceive('engine')->with(true)->andReturn('lizard-test');
        $this->artisan('repochronos:doctor')->expectsOutputToContain('Retrying Supabase connection (2/3)')
            ->expectsOutputToContain('PASS Supabase schema reachable')->assertExitCode(0);
    }

    public function test_persistent_failure_returns_actionable_diagnostics_without_raw_errors(): void
    {
        DB::shouldReceive('select')->times(3)->andThrow(new \RuntimeException('could not translate host name private-secret'));
        DB::shouldReceive('purge')->twice();
        $this->mock(ComplexityAnalyzer::class)->shouldReceive('engine')->with(true)->andReturn('lizard-test');
        $this->artisan('repochronos:doctor')->expectsOutputToContain('DNS could not resolve Supabase.')
            ->expectsOutputToContain('FAIL Supabase schema reachable')->doesntExpectOutputToContain('private-secret')->assertExitCode(1);
    }
}
