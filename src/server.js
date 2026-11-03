import express from 'express';
import { fileURLToPath } from 'node:url';
import { pool, rows, one, setup } from './db.js';
import { importGitHub, InputError } from './github.js';
import { loadSample } from './sample.js';
import { scan, eligible } from './complexity.js';
import { reports } from './reports.js';

const app = express();
app.disable('x-powered-by');
app.set('views', fileURLToPath(new URL('../views/', import.meta.url)));
app.set('view engine', 'ejs');
app.use(express.static(fileURLToPath(new URL('../public/', import.meta.url))));
app.use(express.urlencoded({ extended: false, limit: '16kb' }));
app.use((req, res, next) => {
  const host = req.hostname;
  if (!['127.0.0.1', 'localhost', '[::1]'].includes(host))
    return res.status(403).send('Use the local app address.');
  if (
    req.method === 'POST' &&
    (req.get('sec-fetch-site') === 'cross-site' ||
      (req.get('origin') && req.get('origin') !== 'http://' + req.get('host')))
  )
    return res.sendStatus(403);
  res.set('Cache-Control', 'no-store');
  next();
});
const dt = (d, time = true) =>
  new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    ...(time ? { hour: '2-digit', minute: '2-digit' } : {}),
    timeZone: 'UTC'
  }).format(new Date(d));
function duration(seconds) {
  const n = Math.floor(Number(seconds));
  if (n < 60) return n + 's';
  if (n < 3600) return Math.floor(n / 60) + 'm ' + (n % 60) + 's';
  if (n < 86400) return Math.floor(n / 3600) + 'h ' + Math.floor((n % 3600) / 60) + 'm';
  return Math.floor(n / 86400) + 'd ' + Math.floor((n % 86400) / 3600) + 'h';
}
const nav = [
  ['overview', 'Overview', 'grid'],
  ['history', 'Change history', 'branch'],
  ['analysis', 'Complexity', 'pulse'],
  ['bugs', 'Bug tracker', 'bug'],
  ['sql', 'SQL workbench', 'code']
];
app.locals.dt = dt;
app.locals.duration = duration;
app.locals.nav = nav;
app.locals.short = (s) => s?.slice(0, 8) || '—';
app.locals.initials = (s) =>
  s
    .split(/\s+/)
    .slice(0, 2)
    .map((v) => v[0])
    .join('')
    .toUpperCase();
app.locals.int = (v) => Number(v).toLocaleString('en-US');
function redirect(res, path, message) {
  res.redirect(303, path + (message ? '?notice=' + encodeURIComponent(message) : ''));
}
async function context(req, res, next) {
  const repos = await rows('SELECT * FROM repositories ORDER BY id');
  const repo = req.params.id ? repos.find((r) => String(r.id) === req.params.id) : repos[0];
  if (req.params.id && !repo) throw new InputError('Repository not found.');
  Object.assign(res.locals, {
    repo,
    repos,
    base: repo ? '/r/' + repo.id : '',
    section: 'repositories',
    notice: String(req.query.notice || '').slice(0, 280),
    dbName: process.env.DATABASE_URL?.includes('supabase.') ? 'Supabase PostgreSQL' : 'PostgreSQL'
  });
  next();
}
app.get('/', context, (req, res) =>
  res.redirect(res.locals.repo ? res.locals.base + '/overview' : '/repositories')
);
app.get('/repositories', context, async (req, res) => {
  const checkpoints = await rows(
    `SELECT DISTINCT ON(repo_id) * FROM import_pages ORDER BY repo_id,imported_at DESC`
  );
  res.render('page', { title: 'Repositories', checkpoints });
});
app.post('/sample', async (req, res) => {
  const repo = await loadSample();
  redirect(res, '/r/' + repo.id + '/overview', 'Sample history loaded.');
});
app.post('/connect', async (req, res) => {
  const { repo, notice } = await importGitHub(req.body.name);
  redirect(res, '/r/' + repo.id + '/overview', notice);
});
app.use('/r/:id', context);
app.post('/r/:id/import', async (req, res) => {
  const repo = res.locals.repo;
  if (repo.source_key === 'sample') throw new InputError('This is a bundled sample.');
  const { notice } = await importGitHub(repo.full_name, req.body.head || undefined);
  redirect(res, '/repositories', notice);
});
app.get('/r/:id/overview', async (req, res) => {
  const args = [res.locals.repo.id];
  const totals = await one(
    `SELECT
    (SELECT count(*) FROM commits WHERE repo_id=$1) AS commits,
    (SELECT count(*) FROM files WHERE repo_id=$1) AS files,
    (SELECT count(*) FROM v_contributors WHERE repo_id=$1) AS people,
    (SELECT count(*) FROM v_bug_status WHERE repo_id=$1 AND status<>'Fixed') AS bugs,
    (SELECT count(*) FROM warnings w JOIN v_file_history h ON h.change_id=w.change_id WHERE h.repo_id=$1) AS warnings`,
    args
  );
  const days = await rows(
    `WITH anchor AS (SELECT COALESCE(max(day), (now() AT TIME ZONE 'UTC')::date) AS day FROM v_daily_activity WHERE repo_id=$1)
    SELECT to_char(anchor.day - offset_day,'Mon DD') AS label, COALESCE(a.commits,0) AS n
    FROM anchor CROSS JOIN generate_series(13,0,-1) AS offset_day
    LEFT JOIN v_daily_activity a ON a.day=anchor.day-offset_day AND a.repo_id=$1 ORDER BY offset_day DESC`,
    args
  );
  const people = await rows(
    'SELECT * FROM v_contributors WHERE repo_id=$1 ORDER BY commits DESC,id LIMIT 5',
    args
  );
  const recent = await rows(
    `SELECT c.sha,c.message,c.authored_at,d.display_name,count(ch.id) AS files
    FROM commits c JOIN developers d ON d.id=c.developer_id LEFT JOIN changes ch ON ch.commit_id=c.id
    WHERE c.repo_id=$1 GROUP BY c.id,d.id ORDER BY c.authored_at DESC,c.id DESC LIMIT 6`,
    args
  );
  res.render('page', {
    title: 'Repository overview',
    section: 'overview',
    totals,
    days,
    people,
    recent
  });
});
app.get('/r/:id/history', async (req, res) => {
  const { repo } = res.locals,
    developer = Number(req.query.author) || 0,
    q = String(req.query.q || '').slice(0, 200);
  const page = Math.min(100000, Math.max(1, parseInt(req.query.page, 10) || 1));
  const people = await rows('SELECT * FROM v_contributors WHERE repo_id=$1 ORDER BY display_name', [
    repo.id
  ]);
  const items = await rows(
    `SELECT * FROM v_file_history WHERE repo_id=$1 AND ($2::int=0 OR developer_id=$2)
    AND ($3='' OR strpos(lower(path),lower($3))>0 OR starts_with(sha,lower($3)))
    ORDER BY authored_at DESC,change_id DESC LIMIT 31 OFFSET $4`,
    [repo.id, developer, q, (page - 1) * 30]
  );
  res.render('page', {
    title: 'Change history',
    section: 'history',
    people,
    developer,
    q,
    page,
    items: items.slice(0, 30),
    more: items.length > 30
  });
});
app.get('/r/:id/analysis', async (req, res) => {
  const args = [res.locals.repo.id];
  const stats = await one(
    `SELECT count(*) FILTER(WHERE s.change_id IS NULL) AS pending,
    count(*) FILTER(WHERE s.change_id IS NOT NULL AND s.skip_reason IS NULL) AS analyzed, count(s.skip_reason) AS skipped
    FROM changes ch JOIN files f ON ch.file_id=f.id LEFT JOIN scans s ON s.change_id=ch.id WHERE f.repo_id=$1 AND ${eligible}`,
    args
  );
  const warnings = await rows(
    `SELECT w.*,h.path,h.sha,h.display_name,h.authored_at FROM warnings w
    JOIN v_file_history h ON h.change_id=w.change_id WHERE h.repo_id=$1 ORDER BY h.authored_at DESC,w.line`,
    args
  );
  const skips = await rows(
    `SELECT h.path,h.sha,s.skip_reason FROM scans s JOIN v_file_history h ON h.change_id=s.change_id
    WHERE h.repo_id=$1 AND s.skip_reason IS NOT NULL ORDER BY s.scanned_at DESC LIMIT 20`,
    args
  );
  res.render('page', { title: 'Complexity analysis', section: 'analysis', stats, warnings, skips });
});
app.post('/r/:id/analysis', async (req, res) =>
  redirect(res, res.locals.base + '/analysis', await scan(res.locals.repo))
);
app.get('/r/:id/bugs', async (req, res) => {
  const bugs = await rows(
    'SELECT * FROM v_bug_status WHERE repo_id=$1 ORDER BY reported_at DESC,id DESC',
    [res.locals.repo.id]
  );
  res.render('page', { title: 'Bug tracker', section: 'bugs', bugs });
});
app.post('/r/:id/bugs', async (req, res) => {
  const title = String(req.body.title || '').trim(),
    reporter = String(req.body.reporter || '').trim();
  if (!title || title.length > 160 || !reporter || reporter.length > 80)
    throw new InputError('Enter a bug title (1–160 characters) and reporter (1–80 characters).');
  const b = await one(
    'INSERT INTO bugs(repo_id,title,details,reporter) VALUES($1,$2,$3,$4) RETURNING id',
    [res.locals.repo.id, title, String(req.body.details || '').slice(0, 4000), reporter]
  );
  redirect(res, res.locals.base + '/bugs/' + b.id, 'Bug reported.');
});
app.use('/r/:id/bugs/:bug', async (req, res, next) => {
  const b = await one('SELECT * FROM v_bug_status WHERE id=$1 AND repo_id=$2', [
    req.params.bug,
    res.locals.repo.id
  ]);
  if (!b) throw new InputError('Bug not found in this repository.');
  res.locals.bug = b;
  next();
});
app.get('/r/:id/bugs/:bug', async (req, res) => {
  const { repo, bug } = res.locals;
  const people = await rows('SELECT * FROM v_contributors WHERE repo_id=$1 ORDER BY display_name', [
    repo.id
  ]);
  const commits = await rows(
    'SELECT id,sha,message FROM commits WHERE repo_id=$1 ORDER BY authored_at DESC,id DESC',
    [repo.id]
  );
  const events = await rows(
    `SELECT e.kind,e.at,d.display_name,c.sha FROM bug_events e LEFT JOIN developers d ON d.id=e.developer_id
    LEFT JOIN commits c ON c.id=e.commit_id WHERE e.bug_id=$1 ORDER BY e.at,e.id`,
    [bug.id]
  );
  res.render('page', { title: 'Bug #' + bug.id, section: 'bug', people, commits, events });
});
app.post('/r/:id/bugs/:bug', async (req, res) => {
  const { kind, target } = req.body;
  if (!['assigned', 'fixed'].includes(kind) || !/^\d+$/.test(target))
    throw new InputError('Choose a valid assignee or commit.');
  await pool.query(
    'INSERT INTO bug_events(bug_id,kind,developer_id,commit_id) VALUES($1,$2,$3,$4)',
    [res.locals.bug.id, kind, kind === 'assigned' ? target : null, kind === 'fixed' ? target : null]
  );
  redirect(res, res.locals.base + '/bugs/' + res.locals.bug.id, 'Event recorded.');
});
app.get('/r/:id/sql', async (req, res) => {
  const report = reports[Number(req.query.report)] || reports[0];
  const result = await pool.query(report.sql, [res.locals.repo.id]);
  res.render('page', { title: 'SQL workbench', section: 'sql', reports, report, result });
});
app.use((req, res) => res.status(404).send('Page not found.'));
app.use((err, req, res, next) => {
  const expected = err instanceof InputError || ['23514', '23503', '22P02'].includes(err.code);
  if (!expected) console.error(err.message);
  res
    .status(expected ? 400 : 500)
    .render('error', {
      message: expected
        ? err.message
        : 'The request could not finish. Check the server console and database connection, then retry.',
      back: res.locals.base ? res.locals.base + '/overview' : '/repositories'
    });
});
await setup();
if (process.argv.includes('--sample')) await loadSample();
const port = Number(process.env.PORT) || 4173;
const server = app.listen(port, '127.0.0.1', () =>
  console.log('Repochronos is ready at http://127.0.0.1:' + port)
);
for (const signal of ['SIGTERM', 'SIGINT'])
  process.on(signal, () => server.close(() => pool.end()));
