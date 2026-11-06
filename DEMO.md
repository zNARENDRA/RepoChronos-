# Faculty demonstration — Campus Library

Start Run-RepoChronos.bat, log in with your existing account and open http://127.0.0.1:8000/r/107/overview. Credentials stay in the ignored .env. On another installation, connect https://github.com/zNARENDRA/campus-library-repochronos-demo and use the repository ID assigned there.

This is a deliberately small demonstration repository. Alex Student and Maya Rao are fictional commit authors. Its source and commits are actual GitHub records imported using the API. Keep ALLOW_SAMPLE_DATA=false; do not run a seed or reset before presenting. Registration and the normal database seeder do not create project records.

## 1. Import and attribution

Open Change history. The verified snapshot contains four commits, two contributors, four file paths and eight changed revisions. Show authors, dates, SHAs, paths and additions/deletions. Click Sync latest again: existing commits are not duplicated. Continue import processes another page when available.

## 2. Complexity analysis

Open Complexity. The verified snapshot contains eight analysis runs and twelve function measurements. Python and PHP files are supported; README.md is explicitly unsupported. Repeat analysis to demonstrate duplicate prevention. Every valid score is stored, including low scores.

With the default threshold of five this small example currently has no warnings. A warning means score > the threshold stored with that run; it indicates maintainability risk, not a proven bug. Automated Supabase tests also exercise high scores, unsupported/removed/unavailable files, pending revisions and analyzed source with no functions.

## 3. Historical comparison

Choose library.py in Compare. Select `Add initial library fine calculation` as Before and `Add tiered fines and borrowing rules` as After. calculate_fine changes from complexity 1 to 4. Select commits by message/SHA because several were created close together.

The later fixes retain complexity 4: correcting behavior need not lower a complexity score. Both comparison sides use the same Lizard version. Matching functions uses signatures. Earlier measurements remain available.

## 4. Bug tracking and permissions

The prepared `Early returns produce negative fines` report is already Fixed. Expand its history to show assignment, progress, the acting user, fixing commit `a994f3e` and actual elapsed time. No artificial duration was inserted.

To demonstrate writing, enter a clearly labeled historical example: in `a994f3e`, calculate_fine(1) incorrectly returned zero. Report `First-week fees incorrectly return zero`, assign Maya Rao, mark it in progress and resolve with `8643a3f`, which corrects that behavior and adds boundary tests. Explain that reporting an already-fixed historical issue records today's report-to-resolution time, not the original development time. New reports become permanent history.

For multi-user demonstration, register additional accounts and use the owner membership form. Viewer reads all repository sections; Editor imports, analyzes and updates bugs; Owner also manages editor/viewer membership. An unrelated user cannot open the repository. Git authors and application login accounts are different identities.

## 5. Dashboard and database

Show the dashboard's four commits, contributor activity, complexity history and resolved bug. The prepared snapshot has one resolved bug and no unresolved bugs; new actions change those counts.

Run `php artisan repochronos:verify --supabase` using compatible PHP. It checks the actual Supabase connection, verified TLS, table access, thirteen history triggers and nine examiner queries. Execute db/examiner.sql in Supabase's SQL editor too. Show JOINs for attribution, GROUP BY/HAVING for files changed by both authors, and LAG for complexity changes.

## Explain the design

Repositories, developers, commits, paths, changes, analyses, measurements, reports and events store separate facts. Primary keys identify records; foreign keys enforce relationships. Membership uses the composite primary key (repo_id,user_id). Unique constraints protect repository/SHA, commit/file, change/engine and run/signature/line combinations.

Atomic attributes support 1NF. Composite-key attributes depend on the full key for 2NF. Separate entity facts and derived reports avoid transitive duplication for 3NF. Historical reporter text is an intentional display-name snapshot. Views calculate status, resolution duration, attribution and trends through joins. Transactions save complete imports/analyses; row locks serialize bug events. Triggers reject historical edits and cross-repository links.

Use the Chen ER, Level 0/1 DFD and use case diagrams in presentation/. The walkthrough video is an illustrated fictional example, not a recording of this verified live session.

## Running and testing

The launcher can use the ignored .local/php runtime with PostgreSQL support. Its --no-reload option preserves the Windows environment needed by Python. If starting manually on Windows, use `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`. Restart after changing .env. Install dependencies according to README.md on a different computer; local runtimes and secrets are not committed.

Most regression fixtures roll back. The concurrency test uses an isolated temporary schema on Supabase, runs independent PHP workers and removes its schema/account afterwards. It requires CREATE SCHEMA permission. Test runs do not add fixtures to the presentation's project history.

Internet and API access are needed for new imports and uncached source downloads. Existing history remains readable when GitHub is unavailable if Supabase is reachable. Large-repository load testing and public hosting are outside this verified local college demonstration.
