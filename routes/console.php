<?php

use App\Models\User;
use App\Services\DemoData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('repochronos:demo', function () {
    if (! config('repochronos.allow_sample_data')) {
        $this->error('Sample data is disabled. Register an account and connect a real GitHub repository.');

        return 1;
    }
    foreach (['demo_email', 'demo_password'] as $setting) {
        if (! config('repochronos.'.$setting)) {
            throw new RuntimeException('Set DEMO_EMAIL and DEMO_PASSWORD first.');
        }
    }
    $owner = User::firstOrCreate(['email' => config('repochronos.demo_email')], ['name' => 'Demo owner', 'password' => config('repochronos.demo_password')]);
    $id = app(DemoData::class)->load($owner);
    if (config('repochronos.demo_viewer_email') && config('repochronos.demo_viewer_password')) {
        $viewer = User::firstOrCreate(['email' => config('repochronos.demo_viewer_email')], ['name' => 'Demo viewer', 'password' => config('repochronos.demo_viewer_password')]);
        DB::table('repository_members')->insertOrIgnore(['repo_id' => $id, 'user_id' => $viewer->id, 'role' => 'viewer']);
    }
    $this->info('Demo history and memberships ready. Existing passwords and history were preserved.');
});
Artisan::command('repochronos:claim {email} {repository}', function () {
    $owner = User::where('email', $this->argument('email'))->firstOrFail();
    $id = (int) $this->argument('repository');
    DB::transaction(function () use ($owner, $id) {
        DB::table('repositories')->where('id', $id)->lockForUpdate()->firstOrFail();
        if (DB::table('repository_members')->where('repo_id', $id)->where('role', 'owner')->exists()) {
            throw new RuntimeException('Repository already has an owner.');
        }
        DB::table('repository_members')->insert(['repo_id' => $id, 'user_id' => $owner->id, 'role' => 'owner']);
    });
    $this->info('Legacy repository owner assigned.');
});

Artisan::command('repochronos:verify {--supabase : Require a verified TLS connection to a Supabase hostname}', function () {
    $connection = DB::connection()->getConfig();
    if (($connection['driver'] ?? null) !== 'pgsql') {
        $this->error('Verification requires PostgreSQL.');

        return 1;
    }
    if ($this->option('supabase')) {
        $host = $connection['host'] ?? '';
        if (! preg_match('/\.supabase\.(com|co)$/i', $host) || ($connection['sslmode'] ?? '') !== 'verify-full') {
            $this->error('Supabase verification requires its hostname and DB_SSLMODE=verify-full. Local settings are rejected.');

            return 1;
        }
    }
    DB::transaction(function () {
        DB::statement('SET TRANSACTION READ ONLY');
        foreach (['repositories', 'developers', 'commits', 'files', 'changes', 'import_pages', 'analysis_runs', 'measurements', 'bugs', 'bug_events'] as $table) {
            $count = DB::table('repochronos.'.$table)->count();
            $this->line($table.': '.$count.' records');
        }
        $triggers = DB::selectOne("SELECT count(*) AS count FROM pg_trigger WHERE NOT tgisinternal AND tgname='preserve_history' AND tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace='repochronos'::regnamespace)")->count;
        if ((int) $triggers !== 13) {
            throw new RuntimeException('Expected 13 history-protection triggers.');
        }
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path('db/examiner.sql')));
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($queries as $query) {
            DB::select($query);
        }
        $this->info('History triggers present. All '.(count($queries) - 1).' examiner queries executed.');
    });
    $this->info($this->option('supabase') ? 'Supabase PostgreSQL read-only verification passed.' : 'PostgreSQL regression verification passed. This does not verify Supabase.');

    return 0;
});
