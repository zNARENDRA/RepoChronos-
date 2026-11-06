# RepoChronos
Repository Health and Code Quality Tracking System

Laravel 13 / PHP 8.4.1+, Blade, Bootstrap 5 CSS, Supabase PostgreSQL, GitHub REST API, and Lizard 1.25.0 on Python 3.10+. Every page and chart is rendered on the server. Bootstrap CSS is bundled locally.

## Setup with Supabase

On Windows, after completing setup, double-click `Run-RepoChronos.bat` to check requirements, start Laravel and open the application. Keep the server window open while using it; press Ctrl+C to stop. If the app is already running on port 8000, the launcher opens it. Run `Run-RepoChronos.bat --check` to check configuration without opening a browser or starting a server. It selects PHP 8.4.1+ with `pdo_pgsql`, skipping incompatible PATH entries such as Herd Lite builds without PostgreSQL support. You can set `REPOCHRONOS_PHP` to the full path of a compatible php.exe to choose one explicitly. The launcher does not seed data or run migrations.

1. Install PHP with pdo_pgsql, mbstring, xml, curl, intl, zip and fileinfo, Composer, and Python.
2. Run `composer install`, `python -m venv .venv`, then `.venv/Scripts/python -m pip install -r requirements.txt` on Windows. On Linux/macOS use `.venv/bin/python`. Set PYTHON_BINARY to the absolute path of that interpreter so the PHP server uses the same installed packages.
3. Copy `.env.example` to `.env`. Copy your **session pooler** host, database name, username and database password from the Supabase Connect panel into the DB_* variables. Use `DB_SSLMODE=verify-full`. Download the database CA from Supabase Database Settings and set `DB_SSLROOTCERT` to its absolute path. The database role needs permission to create tables, functions, views and triggers.
4. Run `php artisan key:generate`, then `php artisan migrate --force`.
5. Keep `ALLOW_SAMPLE_DATA=false`. Standard migrations and `db:seed` create no example users, commits, measurements or bugs.
6. Run `php artisan serve --host=127.0.0.1 --port=8000 --no-reload` and open http://127.0.0.1:8000. On Windows, `--no-reload` preserves the TEMP/PATH environment needed by Python subprocesses. Restart the server after configuration changes. Register your own account, log in and connect your GitHub repository. Click **Sync latest** to import its actual history.

The workspace connects to Supabase with `verify-full` TLS. The prepared faculty repository is `zNARENDRA/campus-library-repochronos-demo`; see [DEMO.md](DEMO.md) for the verified workflow and diagrams. Normal repository pages allow access only to GitHub-backed repositories with membership. Use your existing login or register a personal account for the presentation.

Supabase is the deployment database. Laravel connects directly with its PostgreSQL driver. No Supabase service key is needed. The custom schema is accessed through PHP. Auth tables have RLS enabled and anonymous Supabase roles are denied access. For HTTPS deployment, set APP_ENV=production, APP_URL to the HTTPS address and SESSION_SECURE_COOKIE=true. The database owner used for migrations can bypass RLS.

The connection search path puts **public first**, followed by repochronos. Laravel's authentication and migration tables stay in public, while the domain tables retain their original schema.

## GitHub sign-in

GitHub sign-in uses Laravel Socialite and an OAuth App registered by the developer of RepoChronos. Other GitHub users authorize that one app; they do not register their own OAuth Apps. Your existing email/password login stays available.

1. In GitHub Settings → Developer settings → OAuth Apps, register RepoChronos. For this local installation use homepage `http://127.0.0.1:8000` and authorization callback `http://127.0.0.1:8000/auth/github/callback`.
2. Put its `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in the ignored `.env`. Set `GITHUB_REDIRECT_URI` to that exact callback. Always open the app at the same host (`127.0.0.1`, not a mixture with `localhost`) so the session cookie survives the round trip. Production needs its own HTTPS callback configuration.
3. Run `composer install` and `php artisan migrate --force`, clear cached configuration if used, and restart the server.
4. New users select **Continue with GitHub**. Existing users first log in with their password and select **Link GitHub for sign-in** in the repository directory. After linking, they can use GitHub on subsequent logins. Matching emails never automatically merge accounts.

The OAuth App client secret is not a personal access token. `GITHUB_TOKEN` still serves the separate repository-import integration. Sign-in requests identity and verified primary email only (`user:email`), uses session state and PKCE, and never stores the returned OAuth access token. New GitHub accounts receive no repository memberships automatically. Existing repository roles remain in force. A unique nullable `users.github_id` associates an account with one GitHub identity; existing accounts and history are preserved. Users without a verified primary GitHub email can keep using email/password registration.

If credentials are absent or authorization fails, the login page shows a friendly error and the password option remains usable. Completing an actual GitHub round trip requires valid OAuth App credentials; mocked provider tests alone do not establish that live authorization works.

## Upgrade an existing installation

Back up the database first. Stop the old app, install PHP/Python dependencies and configure Laravel to use the same PostgreSQL database. Run `php artisan migrate --force`.

The migration detects the existing repochronos.repositories table and preserves all original commits, developers, paths, changes, import checkpoints, warnings, scans, bugs and events. New Lizard runs have separate tables. Old warning-only scans remain available through SQL and are not represented as complete measurements. Original events retain their original reporter text and may have a null actor. New reports and events require user identities.

Register an account, then assign ownership of each legacy repository using:

```sh
php artisan repochronos:claim owner@example.test REPOSITORY_ID
```

This administrative command only claims repositories that have no owner. Use the repository's Overview page to add registered users as editors or viewers. The original Git history in the Git repository retains the prior implementation.

## Examiner demonstration

1. Log in with your own account and open your real GitHub repository. For this workspace the approved presentation repository is `zNARENDRA/RepoChronos-`.
2. Click **Sync latest** and, when available, **Continue import**. Filter **Change history** by an actual path and author. Repeating the import retains the same facts.
3. In **Complexity**, run **Analyze next 15 revisions** until the desired imported revisions have results. Show supported and unsupported statuses and every stored measurement. Warnings depend on actual scores and the stored threshold.
4. In **Compare revisions**, select a real source file and two imported commits that both changed it and have been analyzed. Show their actual scores, deltas and authors. A score can increase, decrease or stay the same.
5. Report an issue you actually observed, assign a real contributor and mark it in progress. Resolve it only with an imported commit that fixes it. Expand event history to see actors and timestamps and check the updated dashboard.
6. Register a second account and grant editor or viewer access from Overview. Verify that viewers can read and cannot write. Use the contributors that GitHub actually returns; do not invent additional developers.
7. Inspect Overview for contributor counts, commit activity, historical warnings and complexity charts. Trend averages describe measured functions in changed files per commit, rather than a full repository snapshot.
8. Open **SQL workbench**, then execute `db/examiner.sql` in the Supabase SQL editor.

Complexity is a maintainability measure. It does not establish a defect or assign blame to the author of a change. Bug assignment is an explicit user action.

## Real records and preserved test fixtures

The normal application has sample data disabled. `php artisan repochronos:demo` is blocked, the standard database seeder is empty, and registration does not create repositories or reports. A new workspace stays empty until a real GitHub repository is connected and imported. Dashboard counts come from that repository's stored records, including genuine zero counts.

Historical fixtures and `db/demo.sql` are retained for isolated automated testing and archival reference. Do not load them into the faculty workflow. PHPUnit explicitly enables fixtures inside its test process; normal configuration defaults to `ALLOW_SAMPLE_DATA=false`. `db/examiner.sql` filters repository reports to GitHub-backed records so archived sample and verification repositories are excluded.

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

Coverage includes imported revisions with no analysis result. Pending counts use the installed Lizard version. Comparisons and dashboard metrics filter by that version so different analyzer versions are not mixed.

Function comparison matches signatures. Renames and changed signatures may need manual interpretation. Only imported changed revisions are analyzed. Historical warnings count each flagged function revision, including older revisions.

## Tests

Use a dedicated PostgreSQL database, configure its DB_* settings in `.env.testing`, set an APP_KEY, and run:

```sh
php artisan migrate --env=testing --force
php artisan test
```

The test database must have the same migrations. Most tests use transactions and actual PostgreSQL constraints. The concurrency test creates a temporary schema using the domain DDL, commits fixtures there for independent PHP processes, and removes the schema and temporary account afterwards. Its database role needs CREATE SCHEMA permission. Tests exercise authentication, the owner/editor/viewer permission matrix, bug workflows, API-fixture imports, Lizard subprocesses, comparisons, duplicate prevention and immutable history. They require Python and the pinned Lizard package. Use a dedicated test database or a designated Supabase verification project.

Repository sections load in place with a visible loading message. Rapid clicks keep only the latest destination queued; a failed or stalled request offers a normal link and leaves the interface usable. Optional navigation regression checks run with `node --test tests/section-navigation.test.cjs`. Node is only needed for those developer checks, with no packages or build step; deployment still requires only PHP, Python and PostgreSQL.

The dashboard reads its statistics in one PostgreSQL statement after a live permission check. Statistics and permissions are not cached. Lizard version metadata alone is cached for 60 seconds; analysis and `repochronos:doctor --production` probe Python directly. After upgrading Lizard, run `php artisan cache:clear` or the doctor command to refresh the metadata immediately. Keep `CACHE_STORE=file` for the single-server deployment to avoid extra Supabase round trips. Imports insert files and changes in batches of up to 500 rows. Analysis processes up to 15 revisions using batched Python calls and commits the new runs and measurements together; a failed batch can be retried without changing historical records.

Run `php artisan repochronos:verify --supabase` to check table access, the 13 history-protection triggers and every statement in db/examiner.sql through Laravel. This read-only command rejects local host settings and requires verify-full TLS.

See DEMO.md for the five-feature examiner walkthrough and database design explanation. See REPORT.md for actual verification results and remaining limitations.

## Production deployment

For the local single-server launcher, `DB_PERSISTENT=true` reuses the verified Supabase connection across HTTP requests. PostgreSQL search path and UTC timezone are supplied in connection startup options, avoiding repeated remote SET statements. User authentication, permissions and statistics are still read on every request. A newly started worker needs one cold connection; later requests reuse it. Each PHP worker can retain a connection, so budget connections for production worker counts or set `DB_PERSISTENT=false`. PHPUnit forces this option off for isolated database tests. Restart the PHP server after changing it. History and measurement pagination use Previous/Next links to avoid a separate total-count query.

Use DEPLOYMENT.md and .env.production.example for the single-server PHP-FPM/Nginx deployment. Run php artisan repochronos:doctor --production to check configuration and dependencies. The /up endpoint checks database access.
