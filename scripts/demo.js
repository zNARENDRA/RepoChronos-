import EmbeddedPostgres from 'embedded-postgres';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { localUrl } from '../src/db.js';

const profile = process.argv[2] || 'postgres';
if (!/^[a-z0-9_-]+$/i.test(profile))
  throw new Error('Use letters, digits, hyphens, or underscores for a demo profile.');
const directory = resolve('.local', profile);
const database = new EmbeddedPostgres({
  databaseDir: directory,
  user: 'repochronos',
  password: 'local-demo',
  authMethod: 'scram-sha-256',
  port: 55432,
  persistent: true,
  postgresFlags: ['-h', '127.0.0.1'],
  initdbFlags: ['--encoding=UTF8', '--locale=C'],
  autoRehydrateSymlinks: true,
  onLog: () => {}
});
if (!existsSync(resolve(directory, 'PG_VERSION'))) await database.initialise();
await database.start();
const server = spawn(process.execPath, ['src/server.js', '--sample'], {
  stdio: 'inherit',
  env: { ...process.env, DATABASE_URL: localUrl }
});
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true;
  if (server.exitCode === null && !server.killed) server.kill();
  await database.stop();
}
server.on('exit', async (code) => {
  await stop();
  process.exitCode = code || 0;
});
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
