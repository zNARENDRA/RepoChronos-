<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public.users', function (Blueprint $table) {
            $table->string('github_id', 30)->nullable()->unique('users_github_id_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only identity migration. Restore a verified backup for rollback.');
    }
};
