<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE OR REPLACE VIEW repochronos.v_complexity_trend AS
    SELECT repo_id,commit_id,sha,authored_at,count(measurement_id) AS functions,
    round(avg(score),2) AS mean_complexity,max(score) AS max_complexity,
    count(*) FILTER(WHERE warning) AS warnings,engine
    FROM repochronos.v_measurement_history WHERE analysis_status='analyzed'
    GROUP BY repo_id,commit_id,sha,authored_at,engine");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only history migration.');
    }
};
