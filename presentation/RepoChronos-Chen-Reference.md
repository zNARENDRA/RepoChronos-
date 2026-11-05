# RepoChronos detailed Chen ER model

Read the PPTX in order or zoom into the SVG atlas left to right, then down. Both describe the existing SQL schema; neither changes the database.

## Scope and notation

14 repochronos tables and public.users. Framework sessions, password resets, jobs and cache are omitted. Rectangles = entities; diamonds = relationships; ovals = attributes; underlining = primary key; dashed ovals = derived values. Double lines indicate mandatory participation. (min,max) beside an entity states how many instances of the relationship that entity can participate in. Repeated entity names are the same entity. FK-tagged ovals document SQL mapping; the relationship diamonds provide conceptual meaning. All attributes are single-valued. No weak-entity or multivalued-attribute notation is asserted. Composite and shared keys are shown explicitly instead.

## Relationship catalog

| ID | Parent endpoint | Relationship | Child endpoint | Foreign key | Detail |
|---|---|---|---|---|---|
| R01 | repositories (0,N) | contains | commits (1,1) | commits.repo_id → repositories.id | Every commit belongs to exactly one repository. |
| R02 | developers (0,N) | authors | commits (1,1) | commits.developer_id → developers.id | A commit records one Git author, independent of app membership. |
| R03 | repositories (0,N) | tracks | files (1,1) | files.repo_id → repositories.id | File paths are unique within their repository. |
| R04 | commits (0,N) | records | changes (1,1) | changes.commit_id → commits.id | Each change belongs to one imported commit. |
| R05 | files (0,N) | is changed by | changes (1,1) | changes.file_id → files.id | The current file path of the change is mandatory. |
| R06 | files (0,N) | is prior path of | changes (0,1) | changes.previous_file_id → files.id | Only changes with a prior path need this optional relationship. |
| R07 | changes (0,N) | has analysis | analysis_runs (1,1) | analysis_runs.change_id → changes.id | A new engine can add a run. Repeating the same engine cannot duplicate it. |
| R08 | analysis_runs (0,N) | produces | measurements (1,1) | measurements.run_id → analysis_runs.id | A run can have zero functions, including unsupported or empty source. |
| R09 | users (0,N) | holds | repository_members (1,1) | repository_members.user_id → users.id | One membership links exactly one user and one repository. |
| R10 | repositories (0,N) | has member | repository_members (1,1) | repository_members.repo_id → repositories.id | Role belongs to the membership, not globally to the user. |
| R11 | repositories (0,N) | has report | bugs (1,1) | bugs.repo_id → repositories.id | Every report belongs to exactly one repository. |
| R12 | users (0,N) | reports | bugs (0,1*) | bugs.reporter_user_id → users.id | *New reports require one user. Legacy records may have NULL. |
| R13 | bugs (0,N) | has event | bug_events (1,1) | bug_events.bug_id → bugs.id | A newly reported bug may have no events yet. |
| R14 | users (0,N) | performs | bug_events (0,1*) | bug_events.actor_user_id → users.id | *New events require one user. Legacy records may have NULL. |
| R15 | developers (0,N) | is assigned by | bug_events (0,1) | bug_events.developer_id → developers.id | Exactly one developer for assigned events; NULL for progress and fixed. |
| R16 | commits (0,N) | fixes through | bug_events (0,1) | bug_events.commit_id → commits.id | Exactly one commit for fixed events; NULL for assigned and progress. |
| R17 | repositories (0,N) | checkpoints | import_pages (1,1) | import_pages.repo_id → repositories.id | A repository may have many imported pages across head SHAs. |
| R18 | changes (0,1) | has legacy scan | scans (1,1) | scans.change_id → changes.id | The scan shares the change key. No scan means no legacy result. |
| R19 | scans (0,N) | raises legacy | warnings (1,1) | warnings.change_id → scans.change_id | Warning.change_id references Scan.change_id, not Change directly. |

The 0,1* reporter/actor relationship reflects legacy nullable columns. CHECK ... NOT VALID constraints require non-null values on new rows. The fixed event has exactly one commit, assigned has exactly one developer, and progress has neither.

## repositories

UK (source_key). Identity id. A repository may exist before any commits, files or bugs are imported.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| source_key | text | UK | No |
| full_name | text | — | No |

## developers

UK (identity_key). A Git author identity is independent of an application user account.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| identity_key | text | UK | No |
| display_name | text | — | No |

## commits

UK (repo_id, sha). SHA must contain exactly 40 lowercase hexadecimal characters.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| repo_id | integer | FK | No |
| sha | text | — | No |
| developer_id | integer | FK | No |
| authored_at | timestamptz | — | No |
| message | text | — | No |

## files

UK (repo_id, path). A file record identifies a path inside a repository. Renames link two file records.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| repo_id | integer | FK | No |
| path | text | — | No |

## changes

UK (commit_id, file_id). additions >= 0 and deletions >= 0. The change_repo trigger enforces repository consistency.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| commit_id | integer | FK | No |
| file_id | integer | FK | No |
| previous_file_id | integer | FK | Yes |
| kind | text | — | No |
| additions | integer | — | No |
| deletions | integer | — | No |
| blob_sha | text | — | Yes |

## analysis_runs

UK (change_id, engine). threshold > 0. status: analyzed, unsupported, removed or unavailable.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | bigint | PK | No |
| change_id | integer | FK | No |
| engine | text | — | No |
| language | text | — | Yes |
| status | text | — | No |
| reason | text | — | Yes |
| threshold | integer | — | No |
| analyzed_at | timestamptz | — | No |

## measurements

UK (run_id, signature, line). line > 0 and score >= 1. Every valid result is stored, including low scores.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | bigint | PK | No |
| run_id | bigint | FK | No |
| function_name | text | — | No |
| signature | text | — | No |
| line | integer | — | No |
| score | integer | — | No |

## users

public.users: UK (email). Laravel stores a password hash. User accounts and Git authors have no direct FK.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | bigint | PK | No |
| name | varchar(255) | — | No |
| email | varchar(255) | UK | No |
| email_verified_at | timestamp | — | Yes |
| password | varchar(255) | — | No |
| remember_token | varchar(100) | — | Yes |
| created_at | timestamp | — | Yes |
| updated_at | timestamp | — | Yes |

## repository_members

Composite PK (repo_id, user_id). role: owner, editor or viewer. This association resolves User–Repository M:N.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| repo_id | integer | PK, FK | No |
| user_id | bigint | PK, FK | No |
| role | text | — | No |

## bugs

Trimmed title length: 1–160. reporter length: 1–80. New rows require reporter_user_id; old NULL rows are allowed.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| repo_id | integer | FK | No |
| title | text | — | No |
| details | text | — | No |
| reporter | text | — | No |
| reported_at | timestamptz | — | No |
| reporter_user_id | bigint | FK | Yes |

## bug_events

kind: assigned, progress or fixed. New rows require actor_user_id. A partial unique index permits one fix per bug.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| bug_id | integer | FK | No |
| at | timestamptz | — | No |
| kind | text | — | No |
| developer_id | integer | FK | Yes |
| commit_id | integer | FK | Yes |
| actor_user_id | bigint | FK | Yes |

## import_pages

Composite PK (repo_id, head_sha, page). page > 0. A checkpoint belongs to one repository and one import head.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| repo_id | integer | PK, FK | No |
| head_sha | text | PK | No |
| page | integer | PK | No |
| more | boolean | — | No |
| imported_at | timestamptz | — | No |

## source_blobs

Content cache keyed by SHA. changes.blob_sha is an application lookup, with no declared foreign key.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| sha | text | PK | No |
| content | text | — | No |

## scans

Legacy analysis only. Shared PK/FK permits at most one scan per change. The Lizard workflow uses analysis_runs.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| change_id | integer | PK, FK | No |
| scanned_at | timestamptz | — | No |
| skip_reason | text | — | Yes |

## warnings

Legacy warnings only. line > 0 and score >= 6. Current Lizard warnings derive from measurements and run thresholds.

| Column | PostgreSQL type | Key role | Nullable |
|---|---|---|---|
| id | integer | PK | No |
| change_id | integer | FK | No |
| function_name | text | — | No |
| line | integer | — | No |
| score | integer | — | No |

## Defaults and generated values

Surrogate id columns are generated identities in domain tables and Laravel auto-increment for users. imported_at, scanned_at, reported_at, bug_events.at and analyzed_at default to clock_timestamp(). bugs.details defaults to an empty string. Optional columns default to NULL unless specified otherwise. No other domain default is implied.

## Derived reporting

v_bug_current joins bugs, users, developer assignments, events and fixing commits. status, assignee, resolved_by, fixed_at, fix_sha and resolution_seconds are derived. Duration is epoch(fixed_at - reported_at), and hours divide seconds by 3600. v_measurement_history joins runs, measurements and v_file_history; warning is score > stored threshold. v_complexity_trend groups by repository, commit, SHA, authored_at and engine, with count, mean, maximum and warning counts. Unsupported status is separate from analyzed status. Zero measurements can also mean an analyzed file contains no detected functions.

## Integrity and historical semantics

13 preserve_history triggers protect repositories, developers, commits, files, changes, import_pages, scans, warnings, bugs, bug_events, source_blobs, analysis_runs and measurements. Memberships remain mutable. change_repo enforces repository agreement of current/prior file and commit. validate_event locks the bug and enforces chronology, actor permissions, contributor assignment, prior assignment for progress/fixed, same-repository fixing commit and no events after a fix. Partial unique index one_fix_per_bug is on bug_id where kind = fixed. All declared domain references use PostgreSQL default NO ACTION behavior; no cascading delete is implied.

Current Lizard warnings are derived, while the warnings table belongs to legacy scanning and preserves its original fixed score >= 6 rule. An engine/version provides a distinct analysis namespace. Historical comparison reads stored measurements; no separate function identity table exists. Signature-based matching across revisions is application logic.

## Normalization and modeling boundaries

Atomic columns give 1NF. Membership role depends on the full (repo_id,user_id) key; checkpoint metadata depends on (repo_id,head_sha,page), supporting 2NF. Repository metadata, developer identity, commits, file paths, revisions, runs, measurements and bug events store their own facts. Reports derive developer names, current assignment, bug status and aggregates through joins rather than copying them into every row, supporting 3NF. bugs.reporter is a preserved historical text snapshot alongside an account reference; developer and user identities are deliberately distinct.

There is no declared FK from changes.blob_sha to source_blobs.sha and no direct relationship between users and developers. A membership represents authorization; a commit developer represents Git authorship. Database structure permits a repository with zero memberships and a commit with zero imported changes. These are not represented as mandatory child collections.

## Sources

- db/schema.sql
- db/upgrade.sql
- database/migrations/0001_01_01_000000_create_users_table.php
- database/migrations/2026_11_03_000003_separate_analyzer_trends.php

This artifact documents the repository schema as inspected. It does not assert a fresh Supabase migration or runtime verification.
