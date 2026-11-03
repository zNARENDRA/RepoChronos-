import { ensureRepo, saveCommit } from './github.js';
import { transaction } from './db.js';
import { sampleCommits } from './sample-data.js';
export async function loadSample() {
  const repo = await ensureRepo('sample', 'fieldnotes / notebook');
  await transaction(async (client) => {
    await client.query('SELECT pg_advisory_xact_lock(49721502)');
    if ((await client.query('SELECT 1 FROM commits WHERE repo_id=$1 LIMIT 1', [repo.id])).rowCount)
      return;
    const commits = sampleCommits();
    for (const c of commits) await saveCommit(client, repo.id, c);
    const {
      rows: [bug]
    } = await client.query(
      `INSERT INTO bugs(repo_id,title,details,reporter,reported_at)
      VALUES($1,'Notebook count stays stale after archiving','The sidebar still includes archived entries.','Demo reporter',$2) RETURNING id`,
      [repo.id, commits[14].commit.author.date]
    );
    const {
      rows: [fix]
    } = await client.query('SELECT id,developer_id FROM commits WHERE repo_id=$1 AND sha=$2', [
      repo.id,
      commits[16].sha
    ]);
    await client.query(
      "INSERT INTO bug_events(bug_id,kind,developer_id,at) VALUES($1,'assigned',$2,$3)",
      [bug.id, fix.developer_id, commits[15].commit.author.date]
    );
    await client.query(
      "INSERT INTO bug_events(bug_id,kind,commit_id,at) VALUES($1,'fixed',$2,$3)",
      [bug.id, fix.id, commits[16].commit.author.date]
    );
  });
  return repo;
}
