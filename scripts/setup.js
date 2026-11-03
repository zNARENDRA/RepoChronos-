import { setup, pool } from '../src/db.js';
try {
  await setup();
  console.log('Repochronos schema ready.');
} finally {
  await pool.end();
}
