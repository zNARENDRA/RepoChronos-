SET search_path TO repochronos, public, pg_catalog;

-- Four-table join for attribution.
SELECT d.display_name, f.path, c.sha, c.authored_at, ch.kind
FROM changes ch JOIN commits c ON c.id=ch.commit_id
JOIN developers d ON d.id=c.developer_id JOIN files f ON f.id=ch.file_id
ORDER BY c.authored_at DESC;

-- GROUP BY and HAVING find collaborative files.
SELECT repo_id,path,count(DISTINCT developer_id) AS developers
FROM v_file_history GROUP BY repo_id,path
HAVING count(DISTINCT developer_id)>1;

-- All measurements, with source language and original author.
SELECT path,sha,display_name,language,function_name,line,score,threshold,warning
FROM v_measurement_history WHERE measurement_id IS NOT NULL
ORDER BY path,authored_at;

-- Window function identifies complexity increases in a function's history.
SELECT path,function_name,sha,score,
score-lag(score) OVER(PARTITION BY repo_id,path,signature ORDER BY authored_at,commit_id) AS increase
FROM v_measurement_history WHERE measurement_id IS NOT NULL;

-- Measured trends, and explicit unsupported-file coverage.
SELECT * FROM v_complexity_trend ORDER BY repo_id,authored_at;
SELECT h.path,a.status,a.reason FROM analysis_runs a
JOIN v_file_history h ON h.change_id=a.change_id WHERE a.status<>'analyzed';

-- User-reported defects and elapsed resolution time.
SELECT title,reporter_name,assignee,resolved_by,status,fix_sha,resolution_seconds
FROM v_bug_current ORDER BY reported_at DESC;

-- Legacy warnings remain accessible.
SELECT * FROM warnings;

-- Inspect the immutable-history trigger.
SELECT tgname,pg_get_triggerdef(oid) FROM pg_trigger
WHERE tgrelid='repochronos.commits'::regclass AND NOT tgisinternal;
