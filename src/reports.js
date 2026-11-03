export const reports = [
  {
    name: 'File attribution',
    about: 'A four-table join connects each file revision to its commit and author.',
    sql: `SELECT d.display_name, f.path, ch.kind,
       left(c.sha, 8) AS commit, c.authored_at
FROM changes ch
JOIN commits c ON c.id = ch.commit_id
JOIN developers d ON d.id = c.developer_id
JOIN files f ON f.id = ch.file_id
WHERE c.repo_id = $1
ORDER BY c.authored_at DESC, ch.id DESC
LIMIT 25`
  },
  {
    name: 'Bug turnaround',
    about:
      'The status view finds the last assignment and subtracts the report time from the fix event time.',
    sql: `SELECT title, status, assignee,
       left(fix_sha, 8) AS fix_commit,
       round(resolution_seconds / 3600, 2) AS hours
FROM v_bug_status
WHERE repo_id = $1
ORDER BY reported_at DESC
LIMIT 25`
  },
  {
    name: 'Complexity warnings',
    about:
      'Each warning points to one analyzed revision, even after someone simplifies the function.',
    sql: `SELECT h.path, w.function_name, w.line, w.score,
       left(h.sha, 8) AS commit
FROM warnings w
JOIN v_file_history h ON h.change_id = w.change_id
WHERE h.repo_id = $1
ORDER BY w.score DESC, w.id
LIMIT 25`
  },
  {
    name: 'Daily commit counts',
    about:
      'GROUP BY counts distinct commit records per UTC day. A commit touching several files still counts once.',
    sql: `SELECT day, commits
FROM v_daily_activity
WHERE repo_id = $1
ORDER BY day DESC
LIMIT 30`
  }
];
