# Repochronos

Repochronos is a local, single-operator DBMS project for tracing repository changes and bug fixes. The web app uses Node.js, Express, EJS and parameterized SQL. PostgreSQL owns the relationships, reports, constraints and history. The same schema runs on Supabase PostgreSQL and on the bundled local PostgreSQL demo.

## Start the examiner demo

Use Node.js 22.9 or newer. From the project directory, run:

```sh
npm ci
npm run demo
```

Open **http://127.0.0.1:4173**. The first command downloads dependencies, including PostgreSQL binaries for the local demo. The second starts a database on loopback port 55432, installs the schema and loads the fictional **fieldnotes / notebook** repository. After installation the sample workflow works offline. GitHub imports require internet access.

Keep the terminal open. Stop with Ctrl+C. Starting again reuses the database under `.local/postgres/`. To rehearse from a clean sample without deleting history, stop the running demo and use a new profile name, for example `npm run demo -- rehearsal-1`. Each name has its own persistent directory. Run one demo at a time.

## Five-minute walkthrough

| Step | What to do | What to show |
| --- | --- | --- |
| 1. Connect | In **Repositories**, enter `octocat/Hello-World` and click **Connect & import**. If it is already connected, click **Sync latest**. | The importer reads the default branch and stores commits, authors, file paths and revision details. Repeating the import leaves existing records intact. |
| 2. Trace | Open that repository's **Change history**, search for `README`, then filter by developer. | Each row shows the author, UTC author time, file, change type, added/deleted lines and commit SHA. Renames display the previous path. |
| 3. Analyze | Return to **Repositories**, open **fieldnotes / notebook**, choose **Complexity**, then **Run analysis**. | The sample produces two score-6 warnings for earlier revisions of `routeEntry`. The later simplified revision leaves both historical warnings intact. |
| 4. Track a bug | In **Bug tracker**, report “Archived entry appears in inbox”. Enter a reporter, assign the bug to Mira Shah, then choose the sample commit **Simplify entry routing** and click **Mark fixed**. | The timeline preserves the report, each assignment and the linked fix. PostgreSQL calculates report-to-fix time from the event timestamps. The preloaded fixed bug shows a longer example. |
| 5. Explain the SQL | Open **Overview** and **SQL workbench**. Run **File attribution**, then **Bug turnaround**. | Compare the 14-day commit chart with actual query results. Show the joins, parameter and result set on screen. |

The demo data is synthetic. GitHub repositories appear separately in the directory. All bug actions belong to the selected repository. The reporter is an entered name and the assignee is an imported contributor.

## Use Supabase

1. Open your Supabase project and copy its **Session pooler** connection string from **Connect**. Use a database role permitted to create the `repochronos` schema. A direct PostgreSQL connection also works when the network supports it. See [Supabase connection options](https://supabase.com/docs/guides/database/connecting-to-postgres).
2. Copy `.env.example` to `.env`. Set `DATABASE_URL` to that URL with your URL-encoded database password and the TLS configuration for your project. For certificate verification with a project CA, provide its path via `sslrootcert` in the URL. Keep `.env` private.
3. Stop the local demo if it is using port 4173. Run `npm ci`, then `npm start`. Startup creates the schema once, inside a transaction. You can also run `npm run db:setup` separately.
4. Open **Repositories** and connect GitHub or click **Load sample**. The Supabase project starts with its own history. You can inspect every table in the SQL editor by qualifying names with `repochronos.`.

`npm run demo` always uses its own local database. `npm start` uses `DATABASE_URL`, or connects to the already-running local demo database when the variable is absent. The web app binds to 127.0.0.1 and is designed for one examiner workstation.

## Query the database directly

While the database is running, use the read-only SQL command from a second terminal:

```sh
npm run sql -- "SELECT * FROM v_contributors ORDER BY repo_id, commits DESC"
npm run sql -- "SELECT title, status, fix_sha, resolution_seconds FROM v_bug_status"
```

The app and CLI set the search path to `repochronos`. The web workbench exposes four prepared reports. **db/examiner.sql** contains standalone SQL for a PostgreSQL client or Supabase SQL editor.

## Relational design

```text
repositories  1 ── N commits N ── 1 developers
repositories  1 ── N files
commits       1 ── N changes N ── 1 files
changes       1 ── 0..1 scans 1 ── N warnings
repositories  1 ── N bugs 1 ── N bug_events
bug_events    N ── 1 developer (assignment) or commit (fix)
repositories  1 ── N import_pages
```

**3NF.** Developer attributes live in `developers`. A file path belongs to a repository and is unique within it. Commit metadata depends on the commit key. A `changes` row represents one commit/file pair and owns that revision's diff counts. Findings reference a scan of that specific revision. Bug status, current assignee, daily totals and resolution durations are computed by views. The base tables hold the facts needed to reconstruct those values.

**Views.** `v_file_history` joins revisions to files, commits and developers. `v_bug_status` retrieves the latest assignment and fixing event. `v_daily_activity` groups commits by UTC author date. `v_contributors` counts each author's commits per repository.

**Triggers.** Every base table rejects UPDATE, DELETE and TRUNCATE. A change must use files from its commit's repository. A bug event trigger locks the parent report, validates the contributor or fixing commit, and rejects events after a fix. A partial unique index permits one fix per bug. Foreign keys and uniqueness constraints also apply to SQL issued outside the app. A database administrator retains the ability to change the schema.

**Transactions and retries.** Commit metadata and all its changed files are inserted together. A scan and its warnings are also atomic. Import pages are pinned to a head SHA and recorded only after the full page succeeds. A failed request can be retried. Unique keys prevent duplicate facts and previously imported commits survive branch rewrites.

## Scope of analysis and imports

- GitHub imports up to 15 commits per request. **Continue import** fetches the next page of the same snapshot. **Sync latest** checks the current head. Merge commits follow GitHub's file-list semantics. GitHub's 3,000-file response ceiling causes an explicit incomplete-import error. An optional server-side `GITHUB_TOKEN` raises the public API rate limit. See the [GitHub commits API](https://docs.github.com/en/rest/commits/commits).
- Authors with linked accounts use a GitHub user ID. Other authors use a hash of the Git author email, falling back to name when unavailable. The displayed developer name is the first imported name for that identity.
- Complexity uses Acorn to parse the stored revision's exact JavaScript blob. Each function starts at one. Decisions, loops, catch clauses, non-default switch cases, ternaries and logical operators add one. A score of six or more creates a warning. Nested functions are measured independently. The scanner processes 15 pending revisions per action. Unsupported syntax and files over 300 KB are recorded as skipped.
- The activity chart ends on the latest imported author date, so older repositories still show a meaningful window. Historical warnings count each flagged function revision.

## Verification

Run `npm test`. Tests start a separate disposable PostgreSQL cluster and web server, exercise report → assign → reassign → fix, reject cross-repository fixes and post-fix changes, repeat a scan and seed, and attempt forbidden history edits. The tests do not need GitHub credentials or a running demo.

The schema and triggers are in `db/schema.sql`, the workbench reports in `src/reports.js`, the importer in `src/github.js`, and deterministic analysis in `src/complexity.js`.
