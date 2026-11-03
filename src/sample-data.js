import { createHash } from 'node:crypto';
const hash = (s) => createHash('sha1').update(s).digest('hex');
const branchy = `export function routeEntry(entry) {
  if (!entry) return 'invalid';
  if (entry.archived) return 'archive';
  if (entry.draft) return 'drafts';
  if (entry.private) return 'private';
  if (entry.pinned) return 'pinned';
  return 'inbox';
}`;
const normal = 'export function entryTitle(entry) { return entry.title.trim(); }';
export const sampleBlobs = new Map([branchy, normal].map((s) => [hash(s), s]));
const entries = [
  [13, 0, 'Start the field journal', 'README.md'],
  [12, 1, 'Add the notebook schema', 'db/notebooks.sql'],
  [11, 2, 'Create the entry editor', 'src/editor.js'],
  [10, 0, 'Add entry routing rules', 'src/router.js'],
  [9, 1, 'Index the notebook lookup', 'db/notebooks.sql'],
  [8, 2, 'Polish editor focus styles', 'ui/editor.css'],
  [7, 0, 'Document the export format', 'docs/export.md'],
  [7, 1, 'Cover empty notebooks', 'tests/notebook.js'],
  [6, 2, 'Align the toolbar controls', 'ui/editor.css'],
  [5, 0, 'Handle archived entries', 'src/router.js'],
  [5, 2, 'Add a shortcut for new entries', 'src/editor.js'],
  [4, 1, 'Index recent entries', 'db/entries.sql'],
  [3, 0, 'Refine notebook navigation', 'src/navigation.js'],
  [3, 2, 'Improve empty state copy', 'ui/empty.html'],
  [3, 1, 'Test the archive query', 'tests/notebook.js'],
  [2, 2, 'Restore editor selection', 'src/editor.js'],
  [1, 1, 'Fix stale notebook counts', 'db/notebooks.sql'],
  [0, 0, 'Simplify entry routing', 'src/router.js']
];
export const samplePeople = ['Mira Shah', 'Leo Park', 'Ana Silva'];
export function sampleCommits(now = new Date()) {
  const seen = new Set();
  return entries.map(([days, who, message, path], i) => {
    const at = new Date(now);
    at.setUTCDate(at.getUTCDate() - days);
    at.setUTCHours(days ? 9 + (i % 8) : 0, i % 60, 0, 0);
    const status = seen.has(path) ? 'modified' : 'added';
    seen.add(path);
    return {
      sha: hash('fieldnotes-commit-' + i),
      commit: {
        message,
        author: {
          name: samplePeople[who],
          email: 'author' + who + '@fieldnotes.invalid',
          date: at.toISOString()
        }
      },
      files: [
        {
          filename: path,
          status,
          additions: 4 + (i % 13),
          deletions: status === 'added' ? 0 : i % 5,
          sha: hash(path === 'src/router.js' && i !== entries.length - 1 ? branchy : normal)
        }
      ]
    };
  });
}
