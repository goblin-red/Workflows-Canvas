-- SQLite пересобирает таблицы для nullable legacy FK и новых ограничений.
PRAGMA foreign_keys = OFF;
BEGIN IMMEDIATE;
CREATE TEMP TABLE run_graph_seq AS SELECT name, seq FROM sqlite_sequence WHERE name IN ('runs','run_steps','run_marks');
CREATE TABLE "runs_036" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "folder_id" INTEGER DEFAULT NULL,
  "no" INTEGER NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "state" TEXT NOT NULL DEFAULT 'running' CHECK ("state" IN ('running','paused','stopped','done','failed')),
  "lead_agent_id" INTEGER DEFAULT NULL,
  "started_by" INTEGER DEFAULT NULL,
  "work_dir" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "work_url" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "agent_dir" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "summary" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tokens_in" INTEGER DEFAULT NULL,
  "tokens_out" INTEGER DEFAULT NULL,
  "stop_at" TEXT DEFAULT NULL,
  "seen_at" TEXT DEFAULT NULL,
  "started_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "finished_at" TEXT DEFAULT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "engine" INTEGER NOT NULL DEFAULT 1,
  "driver" TEXT NOT NULL DEFAULT 'manual' CHECK ("driver" IN ('manual','utility','lead')),
  "judge" TEXT NOT NULL DEFAULT 'human' CHECK ("judge" IN ('human','formal')),
  "jev" TEXT NOT NULL DEFAULT 'off' CHECK ("jev" IN ('off','advisor','judge')),
  "version" INTEGER NOT NULL DEFAULT 0,
  "show_pause" REAL NOT NULL DEFAULT 0.0,
  "next_move_at" TEXT DEFAULT NULL,
  "wait_for" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "parent_block_id" INTEGER DEFAULT NULL,
  "graph_snapshot" TEXT DEFAULT NULL,
  CONSTRAINT "fk_runs_folder" FOREIGN KEY ("folder_id") REFERENCES "folders" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_runs_lead" FOREIGN KEY ("lead_agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_runs_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_runs_user" FOREIGN KEY ("started_by") REFERENCES "users" ("id") ON DELETE SET NULL,
  CONSTRAINT "ck_runs_scene" CHECK (("folder_id" IS NULL) <> ("parent_block_id" IS NULL)),
  CONSTRAINT "ck_runs_snapshot" CHECK (("parent_block_id" IS NULL AND "graph_snapshot" IS NULL) OR ("parent_block_id" IS NOT NULL AND "graph_snapshot" IS NOT NULL AND json_valid("graph_snapshot")))
);
INSERT INTO "runs_036" ("id", "project_id", "folder_id", "no", "title", "state", "lead_agent_id", "started_by", "work_dir", "work_url", "agent_dir", "summary", "tokens_in", "tokens_out", "stop_at", "seen_at", "started_at", "finished_at", "rev", "engine", "driver", "judge", "jev", "version", "show_pause", "next_move_at", "wait_for") SELECT "id", "project_id", "folder_id", "no", "title", "state", "lead_agent_id", "started_by", "work_dir", "work_url", "agent_dir", "summary", "tokens_in", "tokens_out", "stop_at", "seen_at", "started_at", "finished_at", "rev", "engine", "driver", "judge", "jev", "version", "show_pause", "next_move_at", "wait_for" FROM "runs";
DROP TABLE "runs";
ALTER TABLE "runs_036" RENAME TO "runs";
CREATE UNIQUE INDEX "runs__uq_runs_no" ON "runs" ("project_id","no");
CREATE INDEX "runs__ix_runs_folder" ON "runs" ("folder_id","started_at");
CREATE INDEX "runs__ix_runs_rev" ON "runs" ("project_id","rev");
CREATE INDEX "runs__fk_runs_lead" ON "runs" ("lead_agent_id");
CREATE INDEX "runs__fk_runs_user" ON "runs" ("started_by");
CREATE INDEX "runs__ix_runs_parent" ON "runs" ("project_id","parent_block_id","state");
CREATE TABLE "run_steps_036" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "run_id" INTEGER NOT NULL,
  "element_id" INTEGER DEFAULT NULL,
  "element_no" INTEGER NOT NULL,
  "element_title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "attempt" INTEGER NOT NULL DEFAULT 1,
  "state" TEXT NOT NULL DEFAULT 'issued' CHECK ("state" IN ('issued','running','submitted','accepted','returned','failed','cancelled')),
  "via_edge_id" INTEGER DEFAULT NULL,
  "chosen_edge_id" INTEGER DEFAULT NULL,
  "agent_id" INTEGER DEFAULT NULL,
  "spec_hash" TEXT DEFAULT NULL,
  "note" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "result" TEXT DEFAULT NULL,
  "error" TEXT DEFAULT NULL,
  "ran_on" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "who" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "lead_self" INTEGER DEFAULT NULL,
  "session" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tokens_in" INTEGER DEFAULT NULL,
  "tokens_out" INTEGER DEFAULT NULL,
  "tokens_shared" INTEGER DEFAULT NULL,
  "notes" TEXT DEFAULT NULL CHECK ("notes" is null or json_valid("notes")),
  "opened_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "started_at" TEXT DEFAULT NULL,
  "submitted_at" TEXT DEFAULT NULL,
  "finished_at" TEXT DEFAULT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "vars" TEXT DEFAULT NULL,
  "verdict" TEXT DEFAULT NULL,
  "delivered_run_id" INTEGER DEFAULT NULL,
  "block_id" INTEGER DEFAULT NULL,
  "block_kind" TEXT COLLATE NOCASE DEFAULT NULL,
  "via_arrow_id" INTEGER DEFAULT NULL,
  "chosen_arrow_id" INTEGER DEFAULT NULL,
  CONSTRAINT "fk_steps_agent" FOREIGN KEY ("agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_steps_chosen" FOREIGN KEY ("chosen_edge_id") REFERENCES "elements" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_steps_element" FOREIGN KEY ("element_id") REFERENCES "elements" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_steps_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_steps_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_steps_via" FOREIGN KEY ("via_edge_id") REFERENCES "elements" ("id") ON DELETE SET NULL,
  CONSTRAINT "ck_steps_graph_refs" CHECK ("block_id" IS NULL OR ("element_id" IS NULL AND "via_edge_id" IS NULL AND "chosen_edge_id" IS NULL AND "block_kind" IS NOT NULL))
);
INSERT INTO "run_steps_036" ("id", "project_id", "run_id", "element_id", "element_no", "element_title", "attempt", "state", "via_edge_id", "chosen_edge_id", "agent_id", "spec_hash", "note", "result", "error", "ran_on", "who", "lead_self", "session", "tokens_in", "tokens_out", "tokens_shared", "notes", "opened_at", "started_at", "submitted_at", "finished_at", "rev", "vars", "verdict", "delivered_run_id") SELECT "id", "project_id", "run_id", "element_id", "element_no", "element_title", "attempt", "state", "via_edge_id", "chosen_edge_id", "agent_id", "spec_hash", "note", "result", "error", "ran_on", "who", "lead_self", "session", "tokens_in", "tokens_out", "tokens_shared", "notes", "opened_at", "started_at", "submitted_at", "finished_at", "rev", "vars", "verdict", "delivered_run_id" FROM "run_steps";
DROP TABLE "run_steps";
ALTER TABLE "run_steps_036" RENAME TO "run_steps";
CREATE UNIQUE INDEX "run_steps__uq_steps_attempt" ON "run_steps" ("run_id","element_no","attempt");
CREATE INDEX "run_steps__ix_steps_rev" ON "run_steps" ("project_id","rev");
CREATE INDEX "run_steps__ix_steps_element" ON "run_steps" ("element_id");
CREATE INDEX "run_steps__ix_steps_agent" ON "run_steps" ("agent_id");
CREATE INDEX "run_steps__ix_steps_state" ON "run_steps" ("run_id","state");
CREATE INDEX "run_steps__fk_steps_via" ON "run_steps" ("via_edge_id");
CREATE INDEX "run_steps__fk_steps_chosen" ON "run_steps" ("chosen_edge_id");
CREATE INDEX "run_steps__ix_steps_run_block" ON "run_steps" ("run_id","block_id");
CREATE TABLE "run_marks_036" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "run_id" INTEGER NOT NULL,
  "edge_id" INTEGER DEFAULT NULL,
  "from_step_id" INTEGER NOT NULL,
  "pass" INTEGER NOT NULL DEFAULT 1,
  "taken_by_step_id" INTEGER DEFAULT NULL,
  "taken_at" TEXT DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "arrow_id" INTEGER DEFAULT NULL,
  CONSTRAINT "fk_marks_by" FOREIGN KEY ("taken_by_step_id") REFERENCES "run_steps" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_marks_edge" FOREIGN KEY ("edge_id") REFERENCES "elements" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_marks_from" FOREIGN KEY ("from_step_id") REFERENCES "run_steps" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_marks_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_marks_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE,
  CONSTRAINT "ck_marks_graph_edge" CHECK (("edge_id" IS NULL) <> ("arrow_id" IS NULL))
);
INSERT INTO "run_marks_036" ("id", "project_id", "run_id", "edge_id", "from_step_id", "pass", "taken_by_step_id", "taken_at", "created_at") SELECT "id", "project_id", "run_id", "edge_id", "from_step_id", "pass", "taken_by_step_id", "taken_at", "created_at" FROM "run_marks";
DROP TABLE "run_marks";
ALTER TABLE "run_marks_036" RENAME TO "run_marks";
CREATE UNIQUE INDEX "run_marks__uq_marks_once" ON "run_marks" ("run_id","edge_id","from_step_id");
CREATE INDEX "run_marks__ix_marks_free" ON "run_marks" ("run_id","taken_by_step_id");
CREATE INDEX "run_marks__ix_marks_project" ON "run_marks" ("project_id");
CREATE INDEX "run_marks__fk_marks_edge" ON "run_marks" ("edge_id");
CREATE INDEX "run_marks__fk_marks_from" ON "run_marks" ("from_step_id");
CREATE INDEX "run_marks__fk_marks_by" ON "run_marks" ("taken_by_step_id");
CREATE UNIQUE INDEX "run_marks__uq_marks_arrow_once" ON "run_marks" ("run_id","arrow_id","from_step_id");
UPDATE sqlite_sequence SET seq = MAX(seq, COALESCE((SELECT old.seq FROM run_graph_seq old WHERE old.name = sqlite_sequence.name), 0)) WHERE name IN ('runs','run_steps','run_marks');
INSERT INTO sqlite_sequence (name, seq) SELECT old.name, old.seq FROM run_graph_seq old WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence current WHERE current.name = old.name);
DROP TABLE run_graph_seq;
COMMIT;
PRAGMA foreign_keys = ON;
