# RepoChronos
Repository Health and Code Quality Tracking System

Laravel 13 / PHP 8.3+, Blade, Bootstrap 5 CSS, Supabase PostgreSQL, GitHub REST API, and Lizard 1.25.0 on Python 3.10+. Every page and chart is rendered on the server. Bootstrap CSS is bundled locally.

## Setup with Supabase

1. Install PHP with pdo_pgsql, mbstring, xml, curl, intl, zip and fileinfo, Composer, and Python.
2. Run `composer install` and `python -m pip install -r requirements.txt`.
3. Copy `.env.example` to `.env`. Copy your **session pooler** host, database name, username and database password from the Supabase Connect panel into the DB_* variables. Use `DB_SSLMODE=verify-full`. If your project requires its CA, set the process environment `PGSSLROOTCERT` to the downloaded certificate path. The database role needs permission to create tables, functions, views and triggers.
4. Run `php artisan key:generate`, then `php artisan migrate --force`.
5. Set DEMO_EMAIL and a unique DEMO_PASSWORD of at least 12 characters. Optionally set DEMO_VIEWER_EMAIL and DEMO_VIEWER_PASSWORD. Run `php artisan repochronos:demo`.
6. Run `php artisan serve --host=127.0.0.1 --port=8000` and open http://127.0.0.1:8000. Log in with the credentials you supplied. The seed never changes existing passwords.

The ignored local `.env` in this workspace contains generated owner and viewer demo credentials used during verification. Its DB settings point to local PostgreSQL for testing. Replace those settings to use your Supabase project. A hosted Supabase connection has not been verified.

Supabase is the deployment database. Laravel connects directly with its PostgreSQL driver. No Supabase service key is needed. The custom schema is accessed through PHP. Auth tables have RLS enabled and anonymous Supabase roles are denied access. For HTTPS deployment, set APP_ENV=production, APP_URL to the HTTPS address and SESSION_SECURE_COOKIE=true. The database owner used for migrations can bypass RLS.

The connection search path puts **public first**, followed by repochronos. Laravel's authentication and migration tables stay in public, while the domain tables retain their original schema.

## Upgrade an existing installation

Back up the database first. Stop the old app, install PHP/Python dependencies and configure Laravel to use the same PostgreSQL database. Run `php artisan migrate --force`.

The migration detects the existing repochronos.repositories table and preserves all original commits, developers, paths, changes, import checkpoints, warnings, scans, bugs and events. New Lizard runs have separate tables. Old warning-only scans remain available through SQL and are not represented as complete measurements. Original events retain their original reporter text and may have a null actor. New reports and events require user identities.

Register an account, then assign ownership of each legacy repository using:

```sh
php artisan repochronos:claim owner@example.test REPOSITORY_ID
```

This administrative command only claims repositories that have no owner. Use the repository's Overview page to add registered users as editors or viewers. The original Git history in the Git repository retains the prior implementation.

## Examiner demonstration

1. Log in as the demo owner. Open Fieldnotes to see the preserved original 18 commits and three authors, plus seven multilingual demo commits.
2. Connect `octocat/Hello-World`. Click **Sync latest** to import its commits. Filter **Change history** by README and author. Repeating the import retains the same facts.
3. Return to Fieldnotes. In **Complexity**, click **Analyze next 15 revisions** twice. Both supported and unsupported revisions are recorded. All measured functions appear, including scores of one. Warnings appear when the stored score exceeds the run's threshold.
4. In **Compare revisions**, select src/route.py and its two commits. The function route changes from score 1 to score 6. The page shows function deltas and the file's measured trend.
5. Report a bug, assign a contributor, mark it in progress, then resolve it with an imported commit. Expand its event history to see actors and timestamps. The dashboard updates unresolved and resolved totals.
6. Add another registered account as an editor from Overview. Log in as the demo viewer to verify read access and the absence of write actions.
7. Inspect Overview for contributor counts, commit activity, historical warnings and complexity charts. Trend averages describe measured functions in changed files per commit, rather than a full repository snapshot.
8. Open **SQL workbench**, then execute `db/examiner.sql` in the Supabase SQL editor.

Complexity is a maintainability measure. It does not establish a defect or assign blame to the author of a change. Bug assignment is an explicit user action.

## Offline seed and direct SQL

`php artisan repochronos:demo` inserts fixtures through Laravel into the configured database. No GitHub connection is needed for sample imports or sample analysis. The original sample identities and SHAs are preserved.

Alternatively, after migrations run `db/demo.sql` directly in the Supabase SQL editor. It inserts repository history and source text with conflict protection. Register an account and use the claim command to grant access. It intentionally contains no login credentials. The Artisan demo command additionally creates the example resolved bug if the sample has no bugs.

## Data design

The original normalized relations remain central:

- repositories → commits → changes → files, with developers referenced by commits.
- import_pages stores completed pages pinned to a specific head SHA.
- public.users ↔ repository_members supplies owner, editor and viewer access.
- bugs stores report facts. bug_events stores assignments, progress updates and fixing commits, including the acting user.
- analysis_runs stores one result per changed revision and Lizard version, with status, language, threshold and timestamp.
- measurements stores every function signature, starting line and score under a run.
- source_blobs holds offline source by content identifier. Legacy scans and warnings remain separate.

Function language, file and commit are obtained through joins from measurements to runs and changes. This keeps dependencies in 3NF. Views expose file attribution, contributor counts, current bug status, measurements and grouped complexity trends.

The database enforces foreign keys, unique repository/SHA and commit/file pairs, same-repository file references, contributor assignments, valid fixing commits and one fix per bug. Parent bug locks serialize updates. Triggers reject UPDATE, DELETE and TRUNCATE on history tables. Memberships and authentication remain editable. Migrations are forward-only to protect history.

## Analysis and import behavior

Lizard chooses a reader from the filename. Unsupported files are labeled unsupported, deleted files are labeled removed, and oversized/unsupported-encoding blobs are labeled unavailable. Parsing failures are errors, leaving the revision retryable. Source text is never executed. Lizard is a heuristic parser, so a recorded result does not validate syntax or compiler correctness.

COMPLEXITY_THRESHOLD defaults to 5, so a score of 6 warns. Each run retains its threshold and engine version permanently. Changing the threshold affects new runs. Repeating analysis with the same engine cannot duplicate a revision. A new engine version creates a separate history.

GitHub imports ten commits per HTTP request. Continue import resumes the pinned snapshot; Sync latest checks the latest default-branch head. Changed-file pagination is followed, and GitHub's 3,000-file ceiling produces an error rather than incomplete data. Optional GITHUB_TOKEN is server-side. If PHP reports a certificate issuer error, configure its CA trust store or set GITHUB_CA_BUNDLE to an official CA bundle path. TLS verification stays enabled. Credentials are never embedded in views.

Function comparison matches signatures. Renames and changed signatures may need manual interpretation. Only imported changed revisions are analyzed. Historical warnings count each flagged function revision, including older revisions.

## Tests

Use a dedicated PostgreSQL database, configure its DB_* settings in `.env.testing`, set an APP_KEY, and run:

```sh
php artisan migrate --env=testing --force
php artisan test
```

The test database must have the same migrations. Tests use transactions and actual PostgreSQL constraints. They exercise Laravel authentication/access, full bug workflows, imports with API fixtures, Lizard subprocesses, comparisons, duplicate prevention and immutable history. They require Python and the pinned Lizard package. Run them on a dedicated test database.

See REPORT.md for the actual verification results and remaining limitations.
