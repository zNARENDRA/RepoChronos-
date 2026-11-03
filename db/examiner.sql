-- Run after connecting a repository. All reports are read-only.
SET search_path TO repochronos, pg_catalog;

-- Who changed each file, and when?
SELECT r.full_name, d.display_name, f.path, c.authored_at,
       left(c.sha, 8) AS commit_sha, ch.kind, ch.additions, ch.deletions
FROM changes ch
JOIN files f ON f.id = ch.file_id
JOIN commits c ON c.id = ch.commit_id
JOIN developers d ON d.id = c.developer_id
JOIN repositories r ON r.id = c.repo_id
ORDER BY c.authored_at DESC, ch.id DESC
LIMIT 30;

-- Which files have been touched by multiple developers?
SELECT repo_id, path, count(DISTINCT developer_id) AS authors
FROM v_file_history
GROUP BY repo_id, path
HAVING count(DISTINCT developer_id) > 1;

-- Which historical function revisions crossed the threshold?
SELECT h.path, h.sha, w.function_name, w.line, w.score, s.scanned_at
FROM warnings w
JOIN scans s ON s.change_id = w.change_id
JOIN v_file_history h ON h.change_id = w.change_id
ORDER BY w.score DESC, h.authored_at DESC;

-- Resolve the duration from the immutable report and fix event.
SELECT title, reporter, status, assignee, reported_at, fixed_at,
       left(fix_sha, 8) AS fixing_commit, resolution_seconds
FROM v_bug_status
ORDER BY reported_at DESC;

-- Dashboard series. The application fills empty days with zero.
SELECT repo_id, day, commits FROM v_daily_activity ORDER BY repo_id, day;

-- Inspect the actual protection on the commits table.
SELECT tgname, pg_get_triggerdef(oid) AS definition
FROM pg_trigger
WHERE tgrelid = 'repochronos.commits'::regclass AND NOT tgisinternal;
