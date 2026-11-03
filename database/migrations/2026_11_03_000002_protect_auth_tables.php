<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sessions ENABLE ROW LEVEL SECURITY;
DO $$
DECLARE role_name text;
BEGIN
 FOREACH role_name IN ARRAY ARRAY['anon','authenticated'] LOOP
 IF EXISTS(SELECT FROM pg_roles WHERE rolname=role_name) THEN
 EXECUTE format('REVOKE ALL ON public.users, public.sessions, public.password_reset_tokens FROM %I',role_name);
 END IF;
 END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only security migration.');
    }
};
