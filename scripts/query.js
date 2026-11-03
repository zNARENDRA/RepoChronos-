import { pool } from '../src/db.js';
const sql = process.argv.slice(2).join(' ').trim();
try {
  if (!sql) throw new Error('Supply a query, e.g. npm run sql -- "SELECT * FROM v_contributors"');
  const client = await pool.connect();
  try {
    await client.query('BEGIN READ ONLY');
    await client.query("SET LOCAL statement_timeout='10s'");
    const result = await client.query({ text: sql, queryMode: 'extended' });
    console.table(result.rows);
  } finally {
    await client.query('ROLLBACK');
    client.release();
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
} finally {
  await pool.end();
}
