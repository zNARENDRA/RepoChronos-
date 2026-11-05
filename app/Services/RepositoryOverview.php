<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RepositoryOverview
{
    public function load(int $repo, string $engine): array
    {
        // One database round trip and one statement snapshot for the dashboard.
        // Only analyzer metadata is cached; repository statistics stay live.
        $row = DB::selectOne(<<<'SQL'
WITH scope AS (SELECT ?::integer AS repo_id, ?::text AS engine)
SELECT
    jsonb_build_object(
        'commits', (SELECT count(*) FROM commits WHERE repo_id=s.repo_id),
        'files', (SELECT count(*) FROM files WHERE repo_id=s.repo_id),
        'open', (SELECT count(*) FROM bugs b WHERE b.repo_id=s.repo_id
            AND NOT EXISTS (SELECT FROM bug_events e WHERE e.bug_id=b.id AND e.kind='fixed')),
        'fixed', (SELECT count(*) FROM bugs b WHERE b.repo_id=s.repo_id
            AND EXISTS (SELECT FROM bug_events e WHERE e.bug_id=b.id AND e.kind='fixed')),
        'warnings', (SELECT count(*) FROM v_measurement_history
            WHERE repo_id=s.repo_id AND engine=s.engine AND warning)
    ) AS totals,
    COALESCE((SELECT jsonb_agg(p ORDER BY p.commits DESC,p.id)
        FROM v_contributors p WHERE p.repo_id=s.repo_id),'[]'::jsonb) AS people,
    COALESCE((SELECT jsonb_agg(t ORDER BY t.authored_at,t.commit_id)
        FROM v_complexity_trend t WHERE t.repo_id=s.repo_id AND t.engine=s.engine),'[]'::jsonb) AS trend,
    COALESCE((SELECT jsonb_agg(a ORDER BY a.day) FROM
        (SELECT * FROM v_daily_activity WHERE repo_id=s.repo_id ORDER BY day DESC LIMIT 14) a
    ),'[]'::jsonb) AS activity
FROM scope s
SQL, [$repo, $engine]);

        return [
            'totals' => json_decode($row->totals, false, 512, JSON_THROW_ON_ERROR),
            'people' => collect(json_decode($row->people, false, 512, JSON_THROW_ON_ERROR)),
            'trend' => collect(json_decode($row->trend, false, 512, JSON_THROW_ON_ERROR)),
            'activity' => collect(json_decode($row->activity, false, 512, JSON_THROW_ON_ERROR)),
        ];
    }
}
