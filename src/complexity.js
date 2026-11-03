import { parse } from 'acorn';
import { fullAncestor } from 'acorn-walk';
import { github } from './github.js';
import { rows, transaction } from './db.js';
import { sampleBlobs } from './sample-data.js';

const functions = new Set(['FunctionDeclaration', 'FunctionExpression', 'ArrowFunctionExpression']);
const decisions = new Set([
  'IfStatement',
  'ForStatement',
  'ForOfStatement',
  'ForInStatement',
  'WhileStatement',
  'DoWhileStatement',
  'CatchClause',
  'ConditionalExpression',
  'LogicalExpression'
]);
export function measure(source) {
  let ast;
  try {
    ast = parse(source, { ecmaVersion: 'latest', sourceType: 'module', locations: true });
  } catch {
    ast = parse(source, {
      ecmaVersion: 'latest',
      sourceType: 'script',
      locations: true,
      allowReturnOutsideFunction: true
    });
  }
  const scores = new Map(),
    result = [];
  fullAncestor(ast, (node, state, parents) => {
    if (decisions.has(node.type) || (node.type === 'SwitchCase' && node.test)) {
      const fn = parents.findLast((p) => functions.has(p.type));
      if (fn) scores.set(fn, (scores.get(fn) || 1) + 1);
    }
    if (functions.has(node.type)) {
      const parent = parents.at(-2);
      result.push({
        name: node.id?.name || parent?.id?.name || parent?.key?.name || '(anonymous)',
        line: node.loc.start.line,
        score: scores.get(node) || 1
      });
    }
  });
  return result;
}
export const eligible =
  "f.path ~* '\\.(js|mjs|cjs)$' AND ch.kind <> 'removed' AND ch.blob_sha IS NOT NULL";
export async function scan(repo) {
  const changes = await rows(
    `SELECT ch.id,ch.blob_sha FROM changes ch JOIN files f ON f.id=ch.file_id
    JOIN commits c ON c.id=ch.commit_id LEFT JOIN scans s ON s.change_id=ch.id
    WHERE c.repo_id=$1 AND s.change_id IS NULL AND ${eligible} ORDER BY c.authored_at DESC,ch.id LIMIT 15`,
    [repo.id]
  );
  let count = 0,
    skipped = 0;
  for (const change of changes) {
    let source,
      reason,
      findings = [];
    if (repo.source_key === 'sample') source = sampleBlobs.get(change.blob_sha);
    else {
      const { data } = await github('/repos/' + repo.full_name + '/git/blobs/' + change.blob_sha);
      if (data.size > 300000 || data.encoding !== 'base64')
        reason = 'Unsupported encoding or file over 300 KB';
      else source = Buffer.from(data.content, 'base64').toString('utf8');
    }
    if (!reason) {
      try {
        findings = measure(source).filter((f) => f.score >= 6);
      } catch (e) {
        if (!(e instanceof SyntaxError) && !(e instanceof RangeError)) throw e;
        reason = 'Unsupported JavaScript syntax or nesting';
      }
    }
    await transaction(async (client) => {
      const inserted = await client.query(
        'INSERT INTO scans(change_id,skip_reason) VALUES($1,$2) ON CONFLICT DO NOTHING RETURNING change_id',
        [change.id, reason || null]
      );
      if (!inserted.rowCount) return;
      for (const f of findings)
        await client.query(
          'INSERT INTO warnings(change_id,function_name,line,score) VALUES($1,$2,$3,$4)',
          [change.id, f.name, f.line, f.score]
        );
      count += findings.length;
      if (reason) skipped++;
    });
  }
  return `${changes.length} revision(s) processed · ${count} new warning(s) · ${skipped} skipped.`;
}
