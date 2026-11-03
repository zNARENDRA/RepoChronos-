SET LOCAL search_path TO repochronos, public, pg_catalog;
CREATE TABLE repository_members (
 repo_id integer REFERENCES repositories, user_id bigint REFERENCES public.users,
 role text NOT NULL CHECK(role IN ('owner','editor','viewer')), PRIMARY KEY(repo_id,user_id)
);
CREATE TABLE source_blobs (
 sha text PRIMARY KEY, content text NOT NULL
);
CREATE TABLE analysis_runs (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 change_id integer NOT NULL REFERENCES changes,
 engine text NOT NULL,
 language text,
 status text NOT NULL CHECK(status IN ('analyzed','unsupported','removed','unavailable')),
 reason text,
 threshold integer NOT NULL CHECK(threshold>0),
 analyzed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(change_id,engine)
);
CREATE TABLE measurements (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 run_id bigint NOT NULL REFERENCES analysis_runs,
 function_name text NOT NULL,
 signature text NOT NULL,
 line integer NOT NULL CHECK(line>0),
 score integer NOT NULL CHECK(score>=1),
 UNIQUE(run_id,signature,line)
);
ALTER TABLE bugs ADD COLUMN reporter_user_id bigint REFERENCES public.users;
ALTER TABLE bugs ADD CONSTRAINT new_reporter_required CHECK(reporter_user_id IS NOT NULL) NOT VALID;
ALTER TABLE bug_events ADD COLUMN actor_user_id bigint REFERENCES public.users;
ALTER TABLE bug_events ADD CONSTRAINT new_actor_required CHECK(actor_user_id IS NOT NULL) NOT VALID;
ALTER TABLE bug_events DROP CONSTRAINT bug_events_kind_check;
ALTER TABLE bug_events DROP CONSTRAINT bug_events_check;
ALTER TABLE bug_events ADD CHECK(kind IN ('assigned','progress','fixed'));
ALTER TABLE bug_events ADD CHECK(
(kind='assigned' AND developer_id IS NOT NULL AND commit_id IS NULL) OR
(kind='progress' AND developer_id IS NULL AND commit_id IS NULL) OR
(kind='fixed' AND developer_id IS NULL AND commit_id IS NOT NULL));

CREATE OR REPLACE FUNCTION check_bug_event() RETURNS trigger LANGUAGE plpgsql
SET search_path = repochronos, public, pg_catalog AS $$
DECLARE report bugs;
BEGIN
 SELECT * INTO STRICT report FROM bugs WHERE id=NEW.bug_id FOR UPDATE;
 IF NEW.actor_user_id IS NOT NULL AND NOT EXISTS(SELECT FROM repository_members WHERE repo_id=report.repo_id AND user_id=NEW.actor_user_id AND role IN ('owner','editor')) THEN
  RAISE EXCEPTION 'Repository write permission required.' USING ERRCODE='23514';
 END IF;
 IF EXISTS(SELECT FROM bug_events WHERE bug_id=report.id AND kind='fixed') THEN
  RAISE EXCEPTION 'This bug is already fixed.' USING ERRCODE='23514';
 END IF;
 IF NEW.at < report.reported_at OR EXISTS(SELECT FROM bug_events WHERE bug_id=report.id AND at>NEW.at) THEN
  RAISE EXCEPTION 'Event predates history.' USING ERRCODE='23514';
 END IF;
 IF NEW.kind='assigned' AND NOT EXISTS(SELECT FROM commits WHERE repo_id=report.repo_id AND developer_id=NEW.developer_id) THEN
  RAISE EXCEPTION 'Choose a contributor from this repository.' USING ERRCODE='23514';
 END IF;
 IF NEW.kind IN ('fixed','progress') AND NOT EXISTS(SELECT FROM bug_events WHERE bug_id=report.id AND kind='assigned') THEN
  RAISE EXCEPTION 'Assign the bug first.' USING ERRCODE='23514';
 END IF;
 IF NEW.kind='fixed' AND NOT EXISTS(SELECT FROM commits WHERE repo_id=report.repo_id AND id=NEW.commit_id) THEN
  RAISE EXCEPTION 'Fixing commit must belong to this repository.' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;

CREATE VIEW v_bug_current AS
SELECT b.*, reporter.name AS reporter_name, assignee.display_name AS assignee,
 resolver.name AS resolved_by, fx.at AS fixed_at, fix.sha AS fix_sha,
 CASE WHEN fx.id IS NOT NULL THEN 'Fixed'
      WHEN last_event.kind='progress' THEN 'In progress'
      WHEN a.id IS NOT NULL THEN 'Assigned' ELSE 'Open' END AS status,
 extract(epoch FROM fx.at-b.reported_at) AS resolution_seconds
FROM bugs b
LEFT JOIN public.users reporter ON reporter.id=b.reporter_user_id
LEFT JOIN LATERAL(SELECT * FROM bug_events WHERE bug_id=b.id AND kind='assigned' ORDER BY at DESC,id DESC LIMIT 1) a ON true
LEFT JOIN developers assignee ON assignee.id=a.developer_id
LEFT JOIN LATERAL(SELECT * FROM bug_events WHERE bug_id=b.id ORDER BY at DESC,id DESC LIMIT 1) last_event ON true
LEFT JOIN bug_events fx ON fx.bug_id=b.id AND fx.kind='fixed'
LEFT JOIN commits fix ON fix.id=fx.commit_id
LEFT JOIN public.users resolver ON resolver.id=fx.actor_user_id;

CREATE VIEW v_measurement_history AS
SELECT h.*, r.id AS run_id,r.engine,r.language,r.status AS analysis_status,r.threshold,r.analyzed_at,
 m.id AS measurement_id,m.function_name,m.signature,m.line,m.score,(m.score>r.threshold) AS warning
FROM analysis_runs r JOIN v_file_history h ON h.change_id=r.change_id
LEFT JOIN measurements m ON m.run_id=r.id;

CREATE VIEW v_complexity_trend AS
SELECT repo_id, commit_id,sha,authored_at, count(measurement_id) AS functions,
 round(avg(score),2) AS mean_complexity, max(score) AS max_complexity,
 count(*) FILTER(WHERE warning) AS warnings
FROM v_measurement_history WHERE analysis_status='analyzed'
GROUP BY repo_id,commit_id,sha,authored_at;

DO $$
DECLARE rel text;
BEGIN
 FOREACH rel IN ARRAY ARRAY['source_blobs','analysis_runs','measurements'] LOOP
 EXECUTE format('CREATE TRIGGER preserve_history BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION reject_history_edit()',rel);
 END LOOP;
END $$;
-- Domain data is accessed only through the PHP server, never Supabase anonymous APIs.
REVOKE ALL ON SCHEMA repochronos FROM PUBLIC;
DO $$
DECLARE role_name text;
BEGIN
 FOREACH role_name IN ARRAY ARRAY['anon','authenticated'] LOOP
 IF EXISTS(SELECT FROM pg_roles WHERE rolname=role_name) THEN
 EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA repochronos FROM %I',role_name);
 EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA repochronos FROM %I',role_name);
 END IF;
 END LOOP;
END $$;
