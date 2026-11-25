BEGIN IMMEDIATE;

CREATE TABLE IF NOT EXISTS "blocks" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "scope_id" INTEGER NOT NULL,
  "kind" TEXT NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "description" TEXT NOT NULL DEFAULT '',
  "properties" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("properties")),
  "appearance" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("appearance")),
  "rev" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_blocks_scope" FOREIGN KEY ("scope_id") REFERENCES "projects" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS "blocks__uq_blocks_scope_id" ON "blocks" ("scope_id","id");
CREATE TRIGGER IF NOT EXISTS "tr_blocks_updated_at" AFTER UPDATE ON "blocks" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "blocks" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE IF NOT EXISTS "arrows" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "scope_id" INTEGER NOT NULL,
  "source_id" INTEGER NOT NULL,
  "target_id" INTEGER NOT NULL,
  "kind" TEXT NOT NULL CHECK ("kind" IN ('link','flow','contains')),
  "direction" TEXT NOT NULL CHECK ("direction" IN ('forward','reverse','both','none')),
  "sort" INTEGER NOT NULL DEFAULT 0,
  "properties" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("properties")),
  "appearance" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("appearance")),
  "rev" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_arrows_source" FOREIGN KEY ("scope_id","source_id") REFERENCES "blocks" ("scope_id","id") ON DELETE CASCADE,
  CONSTRAINT "fk_arrows_target" FOREIGN KEY ("scope_id","target_id") REFERENCES "blocks" ("scope_id","id") ON DELETE CASCADE,
  CONSTRAINT "ck_arrows_direction" CHECK (("kind" = 'link' AND "direction" IN ('forward','reverse','both','none')) OR ("kind" = 'flow' AND "direction" IN ('forward','reverse')) OR ("kind" = 'contains' AND "direction" = 'forward' AND "source_id" <> "target_id"))
);
CREATE UNIQUE INDEX IF NOT EXISTS "arrows__uq_arrows_scope_id" ON "arrows" ("scope_id","id");
CREATE INDEX IF NOT EXISTS "arrows__ix_arrows_source" ON "arrows" ("scope_id","source_id","kind","target_id");
CREATE INDEX IF NOT EXISTS "arrows__ix_arrows_target" ON "arrows" ("scope_id","target_id","kind","source_id");
CREATE TRIGGER IF NOT EXISTS "tr_arrows_updated_at" AFTER UPDATE ON "arrows" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "arrows" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE IF NOT EXISTS "graph_legacy_refs" (
  "scope_id" INTEGER NOT NULL,
  "legacy_kind" TEXT NOT NULL CHECK ("legacy_kind" IN ('project','folder','element','agent','asset')),
  "legacy_id" INTEGER NOT NULL,
  "block_id" INTEGER DEFAULT NULL,
  "arrow_id" INTEGER DEFAULT NULL,
  PRIMARY KEY ("scope_id","legacy_kind","legacy_id"),
  CONSTRAINT "fk_graph_legacy_block" FOREIGN KEY ("scope_id","block_id") REFERENCES "blocks" ("scope_id","id") ON DELETE CASCADE,
  CONSTRAINT "fk_graph_legacy_arrow" FOREIGN KEY ("scope_id","arrow_id") REFERENCES "arrows" ("scope_id","id") ON DELETE CASCADE,
  CONSTRAINT "ck_graph_legacy_target" CHECK (("block_id" IS NULL) <> ("arrow_id" IS NULL)),
  CONSTRAINT "ck_graph_legacy_kind" CHECK ("legacy_kind" IN ('project','folder','element','agent','asset') AND ("legacy_kind" <> 'project' OR ("legacy_id" = "scope_id" AND "block_id" IS NOT NULL)))
);
CREATE INDEX IF NOT EXISTS "graph_legacy_refs__ix_graph_legacy_block" ON "graph_legacy_refs" ("scope_id","block_id");
CREATE INDEX IF NOT EXISTS "graph_legacy_refs__ix_graph_legacy_arrow" ON "graph_legacy_refs" ("scope_id","arrow_id");

-- Один корень и одна запись карты на существующую область.
INSERT INTO blocks (scope_id, kind, title, description, properties, appearance, rev)
SELECT p.id, 'project', p.title, '', '{}', '{}', p.rev
  FROM projects p
 WHERE NOT EXISTS (SELECT 1 FROM blocks b WHERE b.scope_id = p.id AND b.kind = 'project');

INSERT INTO graph_legacy_refs (scope_id, legacy_kind, legacy_id, block_id)
SELECT b.scope_id, 'project', b.scope_id, b.id
  FROM blocks b
 WHERE b.kind = 'project'
   AND NOT EXISTS (
       SELECT 1 FROM graph_legacy_refs r
        WHERE r.scope_id = b.scope_id AND r.legacy_kind = 'project' AND r.legacy_id = b.scope_id
   );
COMMIT;
