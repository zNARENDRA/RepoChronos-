<?php

use App\Services\ComplexityAnalyzer;
use App\Services\RepositoryImporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (isset($data['schema'])) {
    if (! app()->environment('testing') || ! preg_match('/^rc_verify_[a-f0-9]{12}$/', $data['schema'])) {
        throw new RuntimeException('Invalid verification schema.');
    }
    DB::statement('SET search_path TO public, '.$data['schema']);
}
DB::connection()->getPdo();
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! file_exists($data['gate'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency gate timed out.');
    }
    usleep(10000);
}
if ($data['op'] === 'commit') {
    app(RepositoryImporter::class)->saveCommit($data['repo'], $data['commit']);
} elseif ($data['op'] === 'scan') {
    app(ComplexityAnalyzer::class)->run(DB::table('repositories')->find($data['repo']));
} else {
    try {
        DB::transaction(function () use ($data) {
            DB::table('bugs')->where('id', $data['bug'])->lockForUpdate()->first();
            DB::table('bug_events')->insert(['bug_id' => $data['bug'], 'kind' => 'fixed', 'commit_id' => $data['commit'], 'actor_user_id' => $data['actor']]);
        });
    } catch (QueryException $error) {
        if ($error->getCode() !== '23514') {
            throw $error;
        }
        echo "CONFLICT_REJECTED\n";
    }
}
echo "DONE\n";
