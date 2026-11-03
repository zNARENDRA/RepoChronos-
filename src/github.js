import { createHash } from 'node:crypto';
import { one, pool, transaction } from './db.js';

export class InputError extends Error {}
export function repositoryName(input) {
  const match = String(input)
    .trim()
    .replace(/^https:\/\/github\.com\//i, '')
    .replace(/\/$/, '')
    .replace(/\.git$/i, '')
    .match(/^([\w-]+)\/([\w.-]+)$/);
  if (!match || ['.', '..'].includes(match[2]))
    throw new InputError('Enter a GitHub repository as owner/name or a github.com URL.');
  return match[0];
}
export async function github(path) {
  const headers = { Accept: 'application/vnd.github+json', 'User-Agent': 'Repochronos' };
  if (process.env.GITHUB_TOKEN) headers.Authorization = 'Bearer ' + process.env.GITHUB_TOKEN;
  const res = await fetch('https://api.github.com' + path, {
    headers,
    signal: AbortSignal.timeout(25000)
  }).catch(() => {
    throw new InputError('GitHub could not be reached. Check your connection and retry.');
  });
  if (!res.ok) {
    const error = new InputError(
      'GitHub returned ' +
        res.status +
        '. ' +
        (res.status === 404
          ? 'Check the repository name and access.'
          : [403, 429].includes(res.status)
            ? 'Check API access or rate limits, then retry. An optional GITHUB_TOKEN increases the public quota.'
            : 'Please retry the import.')
    );
    error.statusCode = res.status;
    throw error;
  }
  return { data: await res.json(), more: (res.headers.get('link') || '').includes('rel="next"') };
}
export async function ensureRepo(sourceKey, fullName) {
  await pool.query(
    'INSERT INTO repositories(source_key,full_name) VALUES($1,$2) ON CONFLICT DO NOTHING',
    [sourceKey, fullName]
  );
  return one('SELECT * FROM repositories WHERE source_key=$1', [sourceKey]);
}
export async function saveCommit(client, repoId, item) {
  const author = item.commit.author || item.commit.committer;
  const identity = item.author?.id
    ? 'github:' + item.author.id
    : 'git:' +
      createHash('sha256')
        .update((author.email || author.name).trim().toLowerCase())
        .digest('hex');
  await client.query(
    'INSERT INTO developers(identity_key,display_name) VALUES($1,$2) ON CONFLICT DO NOTHING',
    [identity, author.name || item.author?.login || 'Unknown author']
  );
  const {
    rows: [commit]
  } = await client.query(
    `INSERT INTO commits(repo_id,sha,developer_id,authored_at,message)
    SELECT $1,$2,id,$3,$4 FROM developers WHERE identity_key=$5 ON CONFLICT DO NOTHING RETURNING id`,
    [repoId, item.sha, author.date, item.commit.message, identity]
  );
  if (!commit) return false;
  async function fileId(path) {
    await client.query('INSERT INTO files(repo_id,path) VALUES($1,$2) ON CONFLICT DO NOTHING', [
      repoId,
      path
    ]);
    return (await client.query('SELECT id FROM files WHERE repo_id=$1 AND path=$2', [repoId, path]))
      .rows[0].id;
  }
  for (const f of item.files) {
    await client.query(
      `INSERT INTO changes(commit_id,file_id,previous_file_id,kind,additions,deletions,blob_sha)
      VALUES($1,$2,$3,$4,$5,$6,$7)`,
      [
        commit.id,
        await fileId(f.filename),
        f.previous_filename ? await fileId(f.previous_filename) : null,
        f.status,
        f.additions,
        f.deletions,
        f.sha || null
      ]
    );
  }
  return true;
}
export async function importGitHub(name, pinnedHead) {
  const { data: meta } = await github('/repos/' + repositoryName(name));
  const repo = await ensureRepo('github:' + meta.id, meta.full_name);
  const base = '/repos/' + meta.full_name + '/commits';
  let head = pinnedHead;
  if (!head) {
    try {
      head = (await github(base + '?per_page=1')).data[0]?.sha;
    } catch (e) {
      if (e.statusCode !== 409) throw e;
    }
  }
  if (!head) return { repo, notice: 'Connected. This repository has no commits yet.' };
  if (!/^[a-f0-9]{40}$/.test(head)) throw new InputError('Invalid import checkpoint.');
  const last = await one(
    'SELECT * FROM import_pages WHERE repo_id=$1 AND head_sha=$2 ORDER BY page DESC LIMIT 1',
    [repo.id, head]
  );
  if (last && !last.more)
    return { repo, notice: 'Up to date. All commits for this snapshot are already imported.' };
  const page = (last?.page || 0) + 1;
  const list = await github(base + '?sha=' + head + '&per_page=15&page=' + page);
  let added = 0;
  for (const { sha } of list.data) {
    if (await one('SELECT id FROM commits WHERE repo_id=$1 AND sha=$2', [repo.id, sha])) continue;
    const path = base + '/' + sha + '?per_page=100';
    const detail = await github(path);
    const item = detail.data;
    let next = detail.more;
    for (let p = 2; next; p++) {
      const extra = await github(path + '&page=' + p);
      item.files.push(...extra.data.files);
      next = extra.more;
    }
    if (item.files.length >= 3000)
      throw new InputError(
        'GitHub limits file lists to 3,000. This commit was left unimported to avoid incomplete history.'
      );
    if (await transaction((client) => saveCommit(client, repo.id, item))) added++;
  }
  await pool.query(
    'INSERT INTO import_pages(repo_id,head_sha,page,more) VALUES($1,$2,$3,$4) ON CONFLICT DO NOTHING',
    [repo.id, head, page, list.more]
  );
  return {
    repo,
    notice:
      added +
      ' new commit(s) imported. ' +
      (list.more
        ? 'More commits are available. Continue the import from Repositories.'
        : 'Snapshot complete.')
  };
}
