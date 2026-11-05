<?php

namespace App\Console\Commands;

use App\Services\ComplexityAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeploymentCheck extends Command
{
    protected $signature = 'repochronos:doctor {--production : Validate production settings}';

    protected $description = 'Check configuration, writable directories, Supabase and Lizard without modifying data';

    public function handle(): int
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        $checks = [
            'Application encryption key valid' => is_string($key) && Encrypter::supported($key, config('app.cipher')),
            'PostgreSQL selected' => config('database.default') === 'pgsql',
            'PostgreSQL PHP driver installed' => extension_loaded('pdo_pgsql'),
            'Storage writable' => is_writable(storage_path('framework/sessions'))
                && is_writable(storage_path('framework/views')) && is_writable(storage_path('logs')),
            'Bootstrap cache writable' => is_writable(base_path('bootstrap/cache')),
            'Positive complexity threshold' => config('repochronos.threshold') > 0,
        ];
        if ($this->option('production')) {
            $db = DB::connection()->getConfig();
            $checks += [
                'Production environment' => config('app.env') === 'production',
                'Debug disabled' => config('app.debug') === false,
                'Sample data disabled' => ! config('repochronos.allow_sample_data'),
                'HTTPS application URL' => str_starts_with(config('app.url'), 'https://'),
                'Secure session cookie' => config('session.secure') === true,
                'Verified Supabase TLS' => ($db['sslmode'] ?? '') === 'verify-full'
                    && preg_match('/\.supabase\.(co|com)$/i', $db['host'] ?? ''),
            ];
        }
        foreach (['Supabase schema reachable' => fn () => DB::select('SELECT 1 FROM repochronos.v_bug_current LIMIT 1'),
            'Lizard available' => fn () => app(ComplexityAnalyzer::class)->engine(true)] as $label => $check) {
            $attempts = $label === 'Supabase schema reachable' && extension_loaded('pdo_pgsql') ? 3 : 1;
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $check();
                    $checks[$label] = true;
                    break;
                } catch (Throwable $error) {
                    $checks[$label] = false;
                    if ($attempt < $attempts) {
                        $this->line('Retrying Supabase connection ('.($attempt + 1).'/'.$attempts.')...');
                        DB::purge();
                        if (! app()->runningUnitTests()) {
                            sleep(1);
                        }
                    } elseif ($label === 'Supabase schema reachable') {
                        $message = strtolower($error->getMessage());
                        $hint = match (true) {
                            str_contains($message, 'could not find driver') => 'This PHP installation lacks pdo_pgsql. Run Run-RepoChronos.bat to select a compatible PHP runtime.',
                            str_contains($message, 'password authentication failed') => 'Check DB_USERNAME and DB_PASSWORD in .env.',
                            str_contains($message, 'certificate'), str_contains($message, 'ssl') => 'Check DB_SSLROOTCERT: the Supabase CA file must exist. Keep DB_SSLMODE=verify-full.',
                            str_contains($message, 'translate host'), str_contains($message, 'resolve') => 'DNS could not resolve Supabase. Check your internet connection and DB_HOST in .env.',
                            str_contains($message, 'does not exist') => 'The database schema is missing. Check the selected Supabase project and run php artisan migrate --force.',
                            default => 'Check internet access, Supabase project availability, and the session-pooler DB_HOST and DB_PORT in .env.',
                        };
                        $this->warn($hint);
                    }
                }
            }
        }
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? 'PASS ' : 'FAIL ').$label);
        }

        return in_array(false, array_map('boolval', $checks), true) ? self::FAILURE : self::SUCCESS;
    }
}
