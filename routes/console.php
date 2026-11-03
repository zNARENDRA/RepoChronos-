<?php

use App\Models\User;
use App\Services\DemoData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('repochronos:demo', function () {
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
