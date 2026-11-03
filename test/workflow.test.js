import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import pg from 'pg';
import EmbeddedPostgres from 'embedded-postgres';
import { measure } from '../src/complexity.js';
import { repositoryName } from '../src/github.js';

test('JavaScript decisions belong to their nearest function', () => {
  const source = 'function outer(a) { const inner = b => b && a ? 1 : 2; if (a) return inner(a); }';
  assert.deepEqual(
    measure(source).map(({ name, score }) => [name, score]),
    [
      ['inner', 3],
      ['outer', 2]
    ]
  );
  assert.equal(repositoryName('https://github.com/octocat/Hello-World.git'), 'octocat/Hello-World');
  assert.throws(() => repositoryName('https://other.example/owner/repo'));
});
async function freePort() {
  const s = createServer();
  s.listen(0, '127.0.0.1');
  await once(s, 'listening');
  const port = s.address().port;
  await new Promise((r) => s.close(r));
  return port;
}

test(
  'end-to-end SQL ledger with an isolated PostgreSQL cluster',
  { timeout: 120000 },
  async (t) => {
    const port = await freePort();
    const directory = await mkdtemp(join(tmpdir(), 'repochronos-test-'));
    const database = new EmbeddedPostgres({
      databaseDir: directory,
      port,
      user: 'test_user',
      password: 'local-test',
      persistent: true,
      autoRehydrateSymlinks: true,
      initdbFlags: ['--encoding=UTF8', '--locale=C'],
      postgresFlags: ['-h', '127.0.0.1'],
      onLog: () => {}
    });
    await database.initialise();
    await database.start();
    const databaseUrl = `postgresql://test_user:local-test@127.0.0.1:${port}/postgres`;
    const db = new pg.Pool({
      connectionString: databaseUrl,
      options: '-c search_path=repochronos,pg_catalog'
    });
    const webPort = await freePort(),
      url = 'http://127.0.0.1:' + webPort;
    const child = spawn(process.execPath, ['src/server.js', '--sample'], {
      env: { ...process.env, DATABASE_URL: databaseUrl, PORT: String(webPort) },
      stdio: ['ignore', 'pipe', 'pipe']
    });
    const stopped = once(child, 'exit');
    t.after(async () => {
      if (child.exitCode === null && !child.killed) child.kill();
      await stopped;
      await db.end();
      await database.stop();
      await rm(directory, { recursive: true, force: true, maxRetries: 15, retryDelay: 200 });
    });
    let errors = '';
    child.stderr.on('data', (b) => (errors += b));
    await Promise.race([
      once(child.stdout, 'data'),
      stopped.then(() => {
        throw new Error(errors || 'Server exited early');
      })
    ]);
    const post = (path, body = {}) =>
      fetch(url + path, { method: 'POST', body: new URLSearchParams(body), redirect: 'manual' });
    const query = async (sql, args = []) => (await db.query(sql, args)).rows;
    const [{ id: repo }] = await query('SELECT id FROM repositories');
    const base = '/r/' + repo;
    for (const path of [
      '/repositories',
      ...[
        'overview',
        'bugs',
        'history',
        'analysis',
        'sql',
        'sql?report=1',
        'sql?report=2',
        'sql?report=3'
      ].map((p) => base + '/' + p)
    ]) {
      assert.equal((await fetch(url + path)).status, 200, path);
    }
    assert.equal((await post('/sample')).status, 303);
    assert.deepEqual(await query('SELECT count(*)::int AS n FROM commits'), [{ n: 18 }]);
    assert.equal((await post(base + '/analysis')).status, 303);
    assert.equal((await post(base + '/analysis')).status, 303);
    assert.deepEqual(await query('SELECT score FROM warnings ORDER BY id'), [
      { score: 6 },
      { score: 6 }
    ]);

    const created = await post(base + '/bugs', {
      title: 'Editor loses focus',
      reporter: 'Examiner',
      details: 'Switch notebook tabs.'
    });
    assert.equal(created.status, 303);
    const detail = created.headers.get('location').split('?')[0];
    const bug = Number(detail.split('/').at(-1));
    const [first, second] = await query('SELECT id FROM developers ORDER BY id LIMIT 2');
    const [commit] = await query('SELECT id FROM commits ORDER BY id DESC LIMIT 1');
    assert.equal((await post(detail, { kind: 'fixed', target: commit.id })).status, 400);
    assert.equal((await post(detail, { kind: 'assigned', target: first.id })).status, 303);
    assert.equal((await post(detail, { kind: 'assigned', target: second.id })).status, 303);
    const [other] = await query(
      "INSERT INTO repositories(source_key,full_name) VALUES('test','other/repo') RETURNING id"
    );
    const [foreign] = await query(
      "INSERT INTO commits(repo_id,sha,developer_id,authored_at,message) VALUES($1,repeat('a',40),$2,now(),'unrelated') RETURNING id",
      [other.id, first.id]
    );
    assert.equal((await post(detail, { kind: 'fixed', target: foreign.id })).status, 400);
    assert.equal((await post(detail, { kind: 'fixed', target: commit.id })).status, 303);
    assert.equal((await post(detail, { kind: 'assigned', target: first.id })).status, 400);
    const [status] = await query('SELECT * FROM v_bug_status WHERE id=$1', [bug]);
    assert.equal(status.status, 'Fixed');
    assert.ok(Number(status.resolution_seconds) >= 0);
    assert.deepEqual(
      (await query('SELECT kind FROM bug_events WHERE bug_id=$1 ORDER BY id', [bug])).map(
        (e) => e.kind
      ),
      ['assigned', 'assigned', 'fixed']
    );
    const html = await (await fetch(url + detail)).text();
    assert.match(html, /Event timeline/);
    assert.match(html, /Marked fixed/);
    for (const statement of [
      "UPDATE commits SET message='changed'",
      'DELETE FROM warnings',
      'TRUNCATE warnings'
    ]) {
      await assert.rejects(db.query(statement), /append-only/);
    }
  }
);
