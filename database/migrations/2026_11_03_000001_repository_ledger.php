<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::selectOne("SELECT to_regclass('repochronos.repositories') AS relation")->relation) {
            DB::unprepared(file_get_contents(base_path('db/schema.sql')));
        }
        DB::unprepared(file_get_contents(base_path('db/upgrade.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('History migrations are forward-only. Restore a verified backup for rollback.');
    }
};
