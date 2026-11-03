-- Run once, inside a transaction. All application objects live in this schema.
CREATE SCHEMA repochronos;
SET LOCAL search_path TO repochronos, pg_catalog;

CREATE TABLE repositories (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  source_key text NOT NULL UNIQUE,
  full_name text NOT NULL
);
CREATE TABLE developers (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  identity_key text NOT NULL UNIQUE,
  display_name text NOT NULL
);
CREATE TABLE commits (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  repo_id integer NOT NULL REFERENCES repositories,
  sha text NOT NULL CHECK (sha ~ '^[a-f0-9]{40}$'),
  developer_id integer NOT NULL REFERENCES developers,
  authored_at timestamptz NOT NULL,
  message text NOT NULL,
  UNIQUE (repo_id, sha)
);
CREATE INDEX commits_by_time ON commits(repo_id, authored_at DESC);
CREATE TABLE files (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  repo_id integer NOT NULL REFERENCES repositories,
  path text NOT NULL,
  UNIQUE (repo_id, path)
);
CREATE TABLE changes (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  commit_id integer NOT NULL REFERENCES commits,
  file_id integer NOT NULL REFERENCES files,
  previous_file_id integer REFERENCES files,
  kind text NOT NULL,
  additions integer NOT NULL CHECK (additions >= 0),
  deletions integer NOT NULL CHECK (deletions >= 0),
  blob_sha text,
  UNIQUE (commit_id, file_id)
);
CREATE TABLE import_pages (
  repo_id integer NOT NULL REFERENCES repositories,
  head_sha text NOT NULL,
  page integer NOT NULL CHECK (page > 0),
  more boolean NOT NULL,
  imported_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (repo_id, head_sha, page)
);
CREATE TABLE scans (
  change_id integer PRIMARY KEY REFERENCES changes,
  scanned_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  skip_reason text
);
CREATE TABLE warnings (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  change_id integer NOT NULL REFERENCES scans,
  function_name text NOT NULL,
  line integer NOT NULL CHECK (line > 0),
  score integer NOT NULL CHECK (score >= 6)
);
CREATE TABLE bugs (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  repo_id integer NOT NULL REFERENCES repositories,
  title text NOT NULL CHECK (length(trim(title)) BETWEEN 1 AND 160),
  details text NOT NULL DEFAULT '',
  reporter text NOT NULL CHECK (length(trim(reporter)) BETWEEN 1 AND 80),
  reported_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE bug_events (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  bug_id integer NOT NULL REFERENCES bugs,
  at timestamptz NOT NULL DEFAULT clock_timestamp(),
  kind text NOT NULL CHECK (kind IN ('assigned','fixed')),
  developer_id integer REFERENCES developers,
  commit_id integer REFERENCES commits,
  CHECK ((kind = 'assigned' AND developer_id IS NOT NULL AND commit_id IS NULL)
      OR (kind = 'fixed' AND developer_id IS NULL AND commit_id IS NOT NULL))
);
CREATE UNIQUE INDEX one_fix_per_bug ON bug_events(bug_id) WHERE kind = 'fixed';
CREATE INDEX bug_event_order ON bug_events(bug_id, id DESC);

CREATE FUNCTION check_change_repo() RETURNS trigger LANGUAGE plpgsql
SET search_path = repochronos, pg_catalog AS $$
DECLARE rid integer;
BEGIN
  SELECT repo_id INTO rid FROM commits WHERE id = NEW.commit_id;
  IF EXISTS (SELECT FROM files WHERE id IN (NEW.file_id, NEW.previous_file_id) AND repo_id <> rid) THEN
    RAISE EXCEPTION 'File and commit must belong to the same repository.' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER change_repo BEFORE INSERT ON changes FOR EACH ROW EXECUTE FUNCTION check_change_repo();

CREATE FUNCTION check_bug_event() RETURNS trigger LANGUAGE plpgsql
SET search_path = repochronos, pg_catalog AS $$
DECLARE report bugs;
BEGIN
  SELECT * INTO STRICT report FROM bugs WHERE id = NEW.bug_id FOR UPDATE;
  IF EXISTS (SELECT FROM bug_events WHERE bug_id = NEW.bug_id AND kind = 'fixed') THEN
    RAISE EXCEPTION 'This bug is already fixed.' USING ERRCODE='23514';
  END IF;
  IF NEW.at < report.reported_at OR EXISTS (SELECT FROM bug_events WHERE bug_id=report.id AND at>NEW.at) THEN
    RAISE EXCEPTION 'A bug event cannot predate its history.' USING ERRCODE='23514';
  END IF;
  IF NEW.kind='assigned' AND NOT EXISTS (SELECT FROM commits WHERE repo_id=report.repo_id AND developer_id=NEW.developer_id) THEN
    RAISE EXCEPTION 'Choose a contributor from this repository.' USING ERRCODE='23514';
  END IF;
  IF NEW.kind='fixed' THEN
    IF NOT EXISTS (SELECT FROM bug_events WHERE bug_id=report.id AND kind='assigned') THEN
      RAISE EXCEPTION 'Assign the bug before recording a fix.' USING ERRCODE='23514';
    END IF;
    IF NOT EXISTS (SELECT FROM commits WHERE repo_id=report.repo_id AND id=NEW.commit_id) THEN
      RAISE EXCEPTION 'Choose a fixing commit from this repository.' USING ERRCODE='23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER validate_event BEFORE INSERT ON bug_events FOR EACH ROW EXECUTE FUNCTION check_bug_event();

CREATE FUNCTION reject_history_edit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'History is append-only (%).', TG_TABLE_NAME USING ERRCODE='23514';
END $$;
DO $$
DECLARE rel text;
BEGIN
  FOREACH rel IN ARRAY ARRAY['repositories','developers','commits','files','changes','import_pages','scans','warnings','bugs','bug_events']
  LOOP
    EXECUTE format('CREATE TRIGGER preserve_history BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION reject_history_edit()', rel);
  END LOOP;
END $$;

CREATE VIEW v_file_history AS
SELECT c.repo_id, ch.id AS change_id, c.id AS commit_id, c.sha, c.message, c.authored_at,
       d.id AS developer_id, d.display_name, f.path, old.path AS previous_path, ch.kind, ch.additions, ch.deletions
FROM changes ch JOIN commits c ON c.id=ch.commit_id JOIN developers d ON d.id=c.developer_id
JOIN files f ON f.id=ch.file_id LEFT JOIN files old ON old.id=ch.previous_file_id;

CREATE VIEW v_bug_status AS
SELECT b.*, assignee.display_name AS assignee, fix.sha AS fix_sha, fx.at AS fixed_at,
       CASE WHEN fx.id IS NOT NULL THEN 'Fixed' WHEN a.id IS NOT NULL THEN 'Assigned' ELSE 'Open' END AS status,
       extract(epoch FROM (fx.at-b.reported_at)) AS resolution_seconds
FROM bugs b
LEFT JOIN LATERAL (SELECT id, developer_id FROM bug_events WHERE bug_id=b.id AND kind='assigned' ORDER BY id DESC LIMIT 1) a ON true
LEFT JOIN developers assignee ON assignee.id=a.developer_id
LEFT JOIN bug_events fx ON fx.bug_id=b.id AND fx.kind='fixed'
LEFT JOIN commits fix ON fix.id=fx.commit_id;

CREATE VIEW v_daily_activity AS
SELECT repo_id, (authored_at AT TIME ZONE 'UTC')::date AS day, count(*)::integer AS commits
FROM commits GROUP BY repo_id, (authored_at AT TIME ZONE 'UTC')::date;

CREATE VIEW v_contributors AS
SELECT c.repo_id, d.id, d.display_name, count(*)::integer AS commits
FROM commits c JOIN developers d ON d.id=c.developer_id GROUP BY c.repo_id,d.id;
