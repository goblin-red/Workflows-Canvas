CREATE TABLE IF NOT EXISTS "ai_terminal_agents" (
  "user_id" INTEGER NOT NULL,
  "provider" TEXT COLLATE NOCASE NOT NULL DEFAULT 'builtin',
  "name" TEXT COLLATE NOCASE DEFAULT NULL,
  "scope" TEXT COLLATE NOCASE NOT NULL DEFAULT 'scheme',
  "scope_project_id" INTEGER DEFAULT NULL,
  "scope_parent_id" INTEGER DEFAULT NULL,
  "scope_folder_id" INTEGER DEFAULT NULL,
  "invite_scope" TEXT COLLATE NOCASE DEFAULT NULL,
  "invite_project_id" INTEGER DEFAULT NULL,
  "invite_parent_id" INTEGER DEFAULT NULL,
  "invite_folder_id" INTEGER DEFAULT NULL,
  "invite_hash" TEXT DEFAULT NULL,
  "invite_expires_at" INTEGER DEFAULT NULL,
  "token_hash" TEXT DEFAULT NULL,
  "last_seen_at" INTEGER DEFAULT NULL,
  PRIMARY KEY ("user_id"),
  CONSTRAINT "fk_terminal_agent_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS "ai_terminal_agents__uq_terminal_agent_invite" ON "ai_terminal_agents" ("invite_hash");
CREATE UNIQUE INDEX IF NOT EXISTS "ai_terminal_agents__uq_terminal_agent_token" ON "ai_terminal_agents" ("token_hash");


CREATE TABLE IF NOT EXISTS "ai_terminal_tasks" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" INTEGER NOT NULL,
  "kind" TEXT COLLATE NOCASE NOT NULL,
  "project_id" INTEGER DEFAULT NULL,
  "parent_id" INTEGER DEFAULT NULL,
  "folder_id" INTEGER DEFAULT NULL,
  "state" TEXT COLLATE NOCASE NOT NULL DEFAULT 'queued',
  "messages" TEXT DEFAULT NULL CHECK ("messages" IS NULL OR json_valid("messages")),
  "response" TEXT DEFAULT NULL CHECK ("response" IS NULL OR json_valid("response")),
  "lease_hash" TEXT DEFAULT NULL,
  "deadline_at" INTEGER NOT NULL,
  CONSTRAINT "fk_terminal_task_agent" FOREIGN KEY ("user_id") REFERENCES "ai_terminal_agents" ("user_id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "ai_terminal_tasks__ix_terminal_task_queue" ON "ai_terminal_tasks" ("user_id","state","id");
CREATE INDEX IF NOT EXISTS "ai_terminal_tasks__ix_terminal_task_expiry" ON "ai_terminal_tasks" ("deadline_at");
