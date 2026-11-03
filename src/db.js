import pg from 'pg';
import { readFile } from 'node:fs/promises';
export const localUrl = 'postgresql://repochronos:local-demo@127.0.0.1:55432/postgres';
export const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL || localUrl,
  onConnect: (client) =>
    client.query("SET search_path TO repochronos, pg_catalog; SET timezone='UTC'"),
  connectionTimeoutMillis: 8000,
  max: 5
});
export const rows = async (sql, args = []) => (await pool.query(sql, args)).rows;
export const one = async (sql, args = []) => (await rows(sql, args))[0];
export async function transaction(work) {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const result = await work(client);
    await client.query('COMMIT');
    return result;
  } catch (error) {
    await client.query('ROLLBACK');
    throw error;
  } finally {
    client.release();
  }
}
export async function setup() {
  await transaction(async (client) => {
    await client.query('SELECT pg_advisory_xact_lock(49721501)');
    const {
      rows: [state]
    } = await client.query("SELECT to_regclass('repochronos.v_bug_status') AS installed");
    if (!state.installed)
      await client.query(await readFile(new URL('../db/schema.sql', import.meta.url), 'utf8'));
  });
}
