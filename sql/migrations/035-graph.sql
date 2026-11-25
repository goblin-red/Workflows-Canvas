CREATE TABLE IF NOT EXISTS `blocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title` varchar(512) NOT NULL DEFAULT '',
  `description` longtext NOT NULL DEFAULT '',
  `properties` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`properties`)),
  `appearance` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`appearance`)),
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blocks_scope_id` (`scope_id`,`id`),
  CONSTRAINT `fk_blocks_scope` FOREIGN KEY (`scope_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arrows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `kind` enum('link','flow','contains') NOT NULL,
  `direction` enum('forward','reverse','both','none') NOT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `properties` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`properties`)),
  `appearance` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`appearance`)),
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_arrows_scope_id` (`scope_id`,`id`),
  KEY `ix_arrows_source` (`scope_id`,`source_id`,`kind`,`target_id`),
  KEY `ix_arrows_target` (`scope_id`,`target_id`,`kind`,`source_id`),
  CONSTRAINT `fk_arrows_source` FOREIGN KEY (`scope_id`,`source_id`) REFERENCES `blocks` (`scope_id`,`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arrows_target` FOREIGN KEY (`scope_id`,`target_id`) REFERENCES `blocks` (`scope_id`,`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_arrows_direction` CHECK ((`kind` = 'link' AND `direction` IN ('forward','reverse','both','none')) OR (`kind` = 'flow' AND `direction` IN ('forward','reverse')) OR (`kind` = 'contains' AND `direction` = 'forward' AND `source_id` <> `target_id`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `graph_legacy_refs` (
  `scope_id` bigint(20) unsigned NOT NULL,
  `legacy_kind` enum('project','folder','element','agent','asset') NOT NULL,
  `legacy_id` bigint(20) unsigned NOT NULL,
  `block_id` bigint(20) unsigned DEFAULT NULL,
  `arrow_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`scope_id`,`legacy_kind`,`legacy_id`),
  KEY `ix_graph_legacy_block` (`scope_id`,`block_id`),
  KEY `ix_graph_legacy_arrow` (`scope_id`,`arrow_id`),
  CONSTRAINT `fk_graph_legacy_block` FOREIGN KEY (`scope_id`,`block_id`) REFERENCES `blocks` (`scope_id`,`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_graph_legacy_arrow` FOREIGN KEY (`scope_id`,`arrow_id`) REFERENCES `arrows` (`scope_id`,`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_graph_legacy_target` CHECK ((`block_id` IS NULL) <> (`arrow_id` IS NULL)),
  CONSTRAINT `ck_graph_legacy_kind` CHECK (`legacy_kind` IN ('project','folder','element','agent','asset') AND (`legacy_kind` <> 'project' OR (`legacy_id` = `scope_id` AND `block_id` IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
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
