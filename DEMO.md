# Faculty walkthrough with real repository data

Start the configured Laravel app with `php artisan serve`. Keep `ALLOW_SAMPLE_DATA=false`. Log in with your existing account or register your own personal account. Use the real repository `zNARENDRA/RepoChronos-`, approved for this presentation. Standard migrations, registration and database seeding do not create example commits, bugs or measurements. Do not run a demo seed.

The workspace was reset on 4 November 2026 at the owner's request. Open http://127.0.0.1:8000/ and use your existing login: the repository directory is empty. Connect the repository during the presentation to become its owner. Existing login accounts remain available, but all repository memberships and project records were cleared. Old repository URLs no longer work; open the new repository from the directory after connecting it.

## 1. Import a repository

Connect `https://github.com/zNARENDRA/RepoChronos-.git`, then click Sync latest. The initial live import on 4 November 2026 returned three commits, 135 changed file revisions and one contributor. Counts may grow when new commits are pushed. Show the actual contributor and file changes in Change history, including SHAs, timestamps and added/deleted lines. Sync again and show that duplicate commits are not created. Continue import processes another page when one is available.

This repository currently has one contributor. To demonstrate contributions from multiple developers, use genuine commits from collaborators or another real collaborative repository. Application users and Git commit authors are separate facts.

## 2. Analyze maintainability

Open this repository's Complexity section. Coverage labels pending revisions “not analyzed”. Run Analyze next 15 revisions until the desired imported revisions have results. New commits can be imported and analyzed during the presentation. Repeating analysis with the same Lizard version does not create duplicates.

Show the languages and scores that actually occur in the imported source. Open Revision coverage to show unsupported documentation/style files and files still awaiting analysis. A supported file can have no detected functions. A score above the threshold stored with a run creates a warning. Complexity measures maintainability risk; it does not establish that a defect exists.

## 3. Compare history and attribution

Select a real source file changed by two imported commits and analyze both revisions. In Compare revisions, choose those commits as Before and After. Show the stored scores and deltas, even if the function's complexity stayed the same. Expand each revision to show its original author and commit. Both sides use the same Lizard version. A file not changed in a selected commit is explicitly labeled that way.

At the last inspection, the repository's three-commit history had no function measured in two commits at the same path. Do not present an invented numeric increase. After importing, you can show real added/removed-file comparison statuses. To show a numeric function-score delta, push a genuine change to a supported function, import that commit and analyze its changed file first.

## 4. Follow a bug

Report a defect you actually observed and record reproduction steps. Assign a real contributor and mark it in progress. Once a real fixing commit is pushed and imported, resolve the bug with that commit. Expand Event history to show the acting users, assignment, fixing commit and elapsed time. Leave a bug open until a fix exists. The application does not fabricate defects, fixing commits or elapsed times.

Use an owner account to grant an already registered user editor access. Editors can update bugs. A viewer can read the repository but cannot write. An unrelated account cannot open the repository.

## 5. Show the database and dashboard

Return to Overview and check unresolved/resolved totals and the stored complexity chart. Earlier revision measurements remain visible.

In Supabase's SQL editor, execute db/examiner.sql. Show attribution joins, collaborative files from GROUP BY/HAVING, function-score changes from LAG, status views and history triggers. Run `php artisan repochronos:verify --supabase` from the application to verify its actual Supabase connection, table access and examiner queries. This command rejects local host settings.

## Explain the relational design

| Fact | Primary key | Foreign keys |
| --- | --- | --- |
| Repository | repositories.id | — |
| Developer | developers.id | — |
| Commit | commits.id | repository, developer |
| File path | files.id | repository |
| Changed revision | changes.id | commit, file, optional previous file |
| Analysis | analysis_runs.id | changed revision |
| Function measurement | measurements.id | analysis |
| Bug | bugs.id | repository, reporting user |
| Bug event | bug_events.id | bug, acting user, assigned developer or fixing commit |
| Repository membership | repo_id + user_id | repository, user |

Unique keys prevent repeated repository/SHA, commit/file and change/analyzer-version facts. A measurement joins through its analysis and changed revision to obtain language, file, commit and author. Base tables store facts once, while views compute totals and current bug state. Reporter text on older records represents the historical display name.

The design follows 3NF by separating repository, author, commit, file and event facts. Foreign keys protect their relationships. JOIN connects those facts for reports. GROUP BY aggregates contributions and activity. HAVING filters grouped files changed by multiple authors.

Views provide file history, contributors, current bug state and complexity trends. Triggers reject edits and deletion of historical facts and validate same-repository links and bug transitions. Database locks serialize bug events. Transactions save a commit with all its changed files and an analysis with all its measurements together.

## Rehearsal notes

Historical warnings count flagged function revisions. A simplified function does not erase its earlier warning. Trend averages cover measured functions in changed revisions, rather than a complete repository snapshot. An analyzed file may contain no detectable functions.

Old sample, verification and imported project records were removed from the active database during the authorized reset. A recovery snapshot is stored privately in the ignored .local directory; it is not loaded by the app. Keep ALLOW_SAMPLE_DATA=false and do not seed sample data before the demonstration.

All 14 project-data tables were verified empty after the reset. The schema, constraints, views, all 13 history-protection triggers and login accounts were preserved. Supabase verification and all nine examiner queries passed against the empty database. Reports will populate only as you connect, import, analyze and record actual bugs.

The fresh demonstration requires internet access to Supabase and GitHub. The previous imported records and source cache have been cleared. GitHub's anonymous API quota was exhausted during an earlier preparation run, so configure GITHUB_TOKEN in the private .env before repeated live imports and analyses, or allow the quota to reset. This reset did not run an import or check the current GitHub quota, to keep the project empty for your presentation.
