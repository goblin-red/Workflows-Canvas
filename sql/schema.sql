-- ───────────────────────────────────────────────────────────────
--  Гоблин — схема базы целиком.
--  Снята с живой базы (SHOW CREATE TABLE) и соответствует миграциям 001–036:
--  добавляя миграцию, обновляйте и этот файл.
--
--  Иерархия: проект → папка (холст) → [группа] → [область] → элемент
--            → вторичное: ассеты и свойства.
--
--  Два идентификатора и только два:
--    id   — строка таблицы, ею адресуется всё в API;
--    no   — человеческий номер объекта внутри проекта, с 10.
--
--  Правила, общие для всей базы:
--    • у каждой таблицы проекта есть project_id с внешним ключом CASCADE;
--    • полиморфных «owner_type + owner_id» нет нигде;
--    • rev есть у строк верхнего уровня: folders, elements, agents,
--      assets, runs, run_steps;
--    • время ставит только сервер, NOW(3);
--    • закрытые списки — ENUM, списки из config.txt — VARCHAR.
--
--  Накатывается на пустую базу: mariadb -uroot goblin_v2 < sql/schema.sql
-- ───────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Люди ──

CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `access` longtext NOT NULL DEFAULT '{}',
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `last_login_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `lang` char(2) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'en',
  `files_server` tinyint(1) NOT NULL DEFAULT 0,
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `next_no` int(10) unsigned NOT NULL DEFAULT 10,
  `next_run_no` int(10) unsigned NOT NULL DEFAULT 1,
  `guest_write` tinyint(1) NOT NULL DEFAULT 1,
  `ai_confirm` tinyint(1) NOT NULL DEFAULT 1,
  `strict_checks` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `seen_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_key` (`url_key`),
  KEY `ix_projects_owner` (`owner_id`),
  CONSTRAINT `fk_projects_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Граф ──

CREATE TABLE `blocks` (
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

CREATE TABLE `arrows` (
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

CREATE TABLE `graph_legacy_refs` (
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

-- ── Структура ──

CREATE TABLE `folders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `work_dir` varchar(1024) NOT NULL DEFAULT '',
  `work_url` varchar(1024) NOT NULL DEFAULT '',
  `share_scheme` tinyint(1) NOT NULL DEFAULT 0,
  `run_env` varchar(16) NOT NULL DEFAULT '',
  `role_scheme` varchar(24) NOT NULL DEFAULT 'solo',
  `style` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`style`)),
  `camera` longtext DEFAULT NULL CHECK (`camera` is null or json_valid(`camera`)),
  `content_rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_folders_rev` (`project_id`,`rev`),
  KEY `ix_folders_tree` (`parent_id`,`sort`),
  CONSTRAINT `fk_folders_parent` FOREIGN KEY (`parent_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_folders_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Агенты проекта ──

CREATE TABLE `agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `role` varchar(16) NOT NULL DEFAULT 'worker',
  `cli` varchar(32) NOT NULL DEFAULT '',
  `model` varchar(64) NOT NULL DEFAULT '',
  `effort` varchar(32) NOT NULL DEFAULT '',
  `permission` varchar(32) NOT NULL DEFAULT '',
  `sandbox` varchar(32) NOT NULL DEFAULT '',
  `close_after` enum('keep','close') NOT NULL DEFAULT 'keep',
  `style` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`style`)),
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_agents_rev` (`project_id`,`rev`),
  KEY `ix_agents_role` (`project_id`,`role`),
  CONSTRAINT `fk_agents_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Схема ──

CREATE TABLE `elements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `folder_id` bigint(20) unsigned NOT NULL,
  `no` int(10) unsigned NOT NULL,
  `type` enum('block','decision','gateway','arrow','group','area','note','table','link') NOT NULL,
  `title` varchar(512) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `from_id` bigint(20) unsigned DEFAULT NULL,
  `to_id` bigint(20) unsigned DEFAULT NULL,
  `branch` enum('flow','yes','no') DEFAULT NULL,
  `back` tinyint(1) NOT NULL DEFAULT 0,
  `target_folder_id` bigint(20) unsigned DEFAULT NULL,
  `style` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`style`)),
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_elements_no` (`project_id`,`no`),
  KEY `ix_elements_rev` (`project_id`,`rev`),
  KEY `ix_elements_folder` (`folder_id`),
  KEY `ix_elements_from` (`from_id`),
  KEY `ix_elements_to` (`to_id`),
  KEY `ix_elements_target` (`target_folder_id`),
  KEY `ix_elements_agent` (`agent_id`),
  KEY `ix_elements_folder_type` (`folder_id`,`type`),
  CONSTRAINT `fk_elements_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_elements_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_elements_from` FOREIGN KEY (`from_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_elements_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_elements_target` FOREIGN KEY (`target_folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_elements_to` FOREIGN KEY (`to_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_elements_branch` CHECK (`type` = 'arrow' or `branch` is null and `back` = 0),
  CONSTRAINT `ck_elements_gate` CHECK (`type` = 'gateway' or `target_folder_id` is null),
  CONSTRAINT `ck_elements_agent` CHECK (`type` = 'block' or `agent_id` is null),
  CONSTRAINT `ck_elements_arrow` CHECK (`type` in ('arrow','link') = (`from_id` is not null and `to_id` is not null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `members` (
  `container_id` bigint(20) unsigned NOT NULL,
  `element_id` bigint(20) unsigned NOT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`container_id`,`element_id`),
  KEY `ix_members_element` (`element_id`),
  CONSTRAINT `fk_members_container` FOREIGN KEY (`container_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_members_element` FOREIGN KEY (`element_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `props` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `element_id` bigint(20) unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `type` enum('text','number','bool','list','json') NOT NULL DEFAULT 'text',
  `value` mediumtext NOT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_props` (`element_id`,`name`),
  KEY `ix_props_lookup` (`name`,`value`(191)),
  CONSTRAINT `fk_props_element` FOREIGN KEY (`element_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Материалы ──

CREATE TABLE `assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'file',
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(4096) DEFAULT NULL,
  `file_key` char(48) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `mime` varchar(96) NOT NULL DEFAULT '',
  `bytes` bigint(20) unsigned DEFAULT NULL,
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assets_file` (`file_key`),
  KEY `ix_assets_rev` (`project_id`,`rev`),
  KEY `ix_assets_uri` (`project_id`,`uri`(191)),
  CONSTRAINT `fk_assets_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_assets_source` CHECK (`uri` is not null or `file_key` is not null or `body` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Прогон ──

CREATE TABLE `runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `folder_id` bigint(20) unsigned DEFAULT NULL,
  `no` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `state` enum('running','paused','stopped','done','failed') NOT NULL DEFAULT 'running',
  `lead_agent_id` bigint(20) unsigned DEFAULT NULL,
  `started_by` bigint(20) unsigned DEFAULT NULL,
  `work_dir` varchar(1024) NOT NULL DEFAULT '',
  `work_url` varchar(1024) NOT NULL DEFAULT '',
  `agent_dir` varchar(1024) NOT NULL DEFAULT '',
  `summary` varchar(500) NOT NULL DEFAULT '',
  `tokens_in` bigint(20) unsigned DEFAULT NULL,
  `tokens_out` bigint(20) unsigned DEFAULT NULL,
  `stop_at` datetime(3) DEFAULT NULL,
  `seen_at` datetime(3) DEFAULT NULL,
  `started_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `finished_at` datetime(3) DEFAULT NULL,
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `engine` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `driver` enum('manual','utility','lead') NOT NULL DEFAULT 'manual',
  `judge` enum('human','formal') NOT NULL DEFAULT 'human',
  `jev` enum('off','advisor','judge') NOT NULL DEFAULT 'off',
  `version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `show_pause` decimal(4,1) NOT NULL DEFAULT 0.0,
  `next_move_at` datetime(3) DEFAULT NULL,
  `wait_for` varchar(255) NOT NULL DEFAULT '',
  `parent_block_id` bigint(20) unsigned DEFAULT NULL,
  `graph_snapshot` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_runs_no` (`project_id`,`no`),
  KEY `ix_runs_folder` (`folder_id`,`started_at`),
  KEY `ix_runs_rev` (`project_id`,`rev`),
  KEY `fk_runs_lead` (`lead_agent_id`),
  KEY `fk_runs_user` (`started_by`),
  CONSTRAINT `fk_runs_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_runs_lead` FOREIGN KEY (`lead_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_runs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_runs_user` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  KEY `ix_runs_parent` (`project_id`,`parent_block_id`,`state`),
  CONSTRAINT `ck_runs_scene` CHECK ((`folder_id` IS NULL) <> (`parent_block_id` IS NULL)),
  CONSTRAINT `ck_runs_snapshot` CHECK ((`parent_block_id` IS NULL AND `graph_snapshot` IS NULL) OR (`parent_block_id` IS NOT NULL AND `graph_snapshot` IS NOT NULL AND json_valid(`graph_snapshot`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `run_steps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned NOT NULL,
  `element_id` bigint(20) unsigned DEFAULT NULL,
  `element_no` int(10) unsigned NOT NULL,
  `element_title` varchar(512) NOT NULL DEFAULT '',
  `attempt` smallint(5) unsigned NOT NULL DEFAULT 1,
  `state` enum('issued','running','submitted','accepted','returned','failed','cancelled') NOT NULL DEFAULT 'issued',
  `via_edge_id` bigint(20) unsigned DEFAULT NULL,
  `chosen_edge_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `spec_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `note` varchar(500) NOT NULL DEFAULT '',
  `result` text DEFAULT NULL,
  `error` text DEFAULT NULL,
  `ran_on` varchar(120) NOT NULL DEFAULT '',
  `who` varchar(60) NOT NULL DEFAULT '',
  `lead_self` tinyint(1) DEFAULT NULL,
  `session` varchar(120) NOT NULL DEFAULT '',
  `tokens_in` bigint(20) unsigned DEFAULT NULL,
  `tokens_out` bigint(20) unsigned DEFAULT NULL,
  `tokens_shared` bigint(20) unsigned DEFAULT NULL,
  `notes` longtext DEFAULT NULL CHECK (`notes` is null or json_valid(`notes`)),
  `opened_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `started_at` datetime(3) DEFAULT NULL,
  `submitted_at` datetime(3) DEFAULT NULL,
  `finished_at` datetime(3) DEFAULT NULL,
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `vars` longtext DEFAULT NULL,
  `verdict` longtext DEFAULT NULL,
  `delivered_run_id` bigint(20) unsigned DEFAULT NULL,
  `block_id` bigint(20) unsigned DEFAULT NULL,
  `block_kind` varchar(24) DEFAULT NULL,
  `via_arrow_id` bigint(20) unsigned DEFAULT NULL,
  `chosen_arrow_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_steps_attempt` (`run_id`,`element_no`,`attempt`),
  KEY `ix_steps_rev` (`project_id`,`rev`),
  KEY `ix_steps_element` (`element_id`),
  KEY `ix_steps_agent` (`agent_id`),
  KEY `ix_steps_state` (`run_id`,`state`),
  KEY `fk_steps_via` (`via_edge_id`),
  KEY `fk_steps_chosen` (`chosen_edge_id`),
  CONSTRAINT `fk_steps_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_steps_chosen` FOREIGN KEY (`chosen_edge_id`) REFERENCES `elements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_steps_element` FOREIGN KEY (`element_id`) REFERENCES `elements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_steps_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_steps_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_steps_via` FOREIGN KEY (`via_edge_id`) REFERENCES `elements` (`id`) ON DELETE SET NULL,
  KEY `ix_steps_run_block` (`run_id`,`block_id`),
  CONSTRAINT `ck_steps_graph_refs` CHECK (`block_id` IS NULL OR (`element_id` IS NULL AND `via_edge_id` IS NULL AND `chosen_edge_id` IS NULL AND `block_kind` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `run_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned NOT NULL,
  `step_id` bigint(20) unsigned NOT NULL,
  `ref` varchar(32) NOT NULL,
  `provider` varchar(64) NOT NULL DEFAULT '',
  `tool` varchar(64) NOT NULL DEFAULT '',
  `model` varchar(64) NOT NULL DEFAULT '',
  `request` longtext DEFAULT NULL CHECK (`request` is null or json_valid(`request`)),
  `job_id` varchar(128) NOT NULL DEFAULT '',
  `state` enum('sent','done','failed','rejected') NOT NULL DEFAULT 'sent',
  `response_asset_id` bigint(20) unsigned DEFAULT NULL,
  `paid` tinyint(1) NOT NULL DEFAULT 1,
  `error` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_ref` (`step_id`,`ref`),
  KEY `ix_jobs_job` (`run_id`,`job_id`),
  KEY `fk_jobs_project` (`project_id`),
  KEY `fk_jobs_response` (`response_asset_id`),
  CONSTRAINT `fk_jobs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_response` FOREIGN KEY (`response_asset_id`) REFERENCES `assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_step` FOREIGN KEY (`step_id`) REFERENCES `run_steps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `run_marks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned NOT NULL,
  `edge_id` bigint(20) unsigned DEFAULT NULL,
  `from_step_id` bigint(20) unsigned NOT NULL,
  `pass` int(10) unsigned NOT NULL DEFAULT 1,
  `taken_by_step_id` bigint(20) unsigned DEFAULT NULL,
  `taken_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `arrow_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marks_once` (`run_id`,`edge_id`,`from_step_id`),
  KEY `ix_marks_free` (`run_id`,`taken_by_step_id`),
  KEY `ix_marks_project` (`project_id`),
  KEY `fk_marks_edge` (`edge_id`),
  KEY `fk_marks_from` (`from_step_id`),
  KEY `fk_marks_by` (`taken_by_step_id`),
  CONSTRAINT `fk_marks_by` FOREIGN KEY (`taken_by_step_id`) REFERENCES `run_steps` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_marks_edge` FOREIGN KEY (`edge_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_marks_from` FOREIGN KEY (`from_step_id`) REFERENCES `run_steps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_marks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_marks_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_marks_arrow_once` (`run_id`,`arrow_id`,`from_step_id`),
  CONSTRAINT `ck_marks_graph_edge` CHECK ((`edge_id` IS NULL) <> (`arrow_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `run_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned NOT NULL,
  `step_id` bigint(20) unsigned DEFAULT NULL,
  `element_no` int(10) unsigned DEFAULT NULL,
  `attempt` smallint(5) unsigned DEFAULT NULL,
  `at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `kind` varchar(24) NOT NULL,
  `actor` enum('lead','worker','human','script','judge') NOT NULL DEFAULT 'script',
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` longtext DEFAULT NULL,
  `meta` longtext DEFAULT NULL,
  `took_ms` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_run` (`run_id`,`id`),
  KEY `idx_events_step` (`step_id`),
  KEY `idx_events_kind` (`run_id`,`kind`),
  KEY `idx_events_pulse` (`project_id`,`kind`,`id`),
  CONSTRAINT `fk_events_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_events_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asset_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `asset_id` bigint(20) unsigned NOT NULL,
  `role` enum('spec','code','input','reference','cover','attachment','result','preview') NOT NULL DEFAULT 'attachment',
  `output` varchar(64) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `element_id` bigint(20) unsigned DEFAULT NULL,
  `folder_id` bigint(20) unsigned DEFAULT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `step_id` bigint(20) unsigned DEFAULT NULL,
  `made_by_run` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_links_element` (`element_id`,`sort`),
  KEY `ix_links_folder` (`folder_id`),
  KEY `ix_links_run` (`run_id`),
  KEY `ix_links_step` (`step_id`),
  KEY `ix_links_asset` (`asset_id`),
  KEY `fk_links_project` (`project_id`),
  KEY `idx_links_made_by_run` (`made_by_run`),
  CONSTRAINT `fk_links_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_element` FOREIGN KEY (`element_id`) REFERENCES `elements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_step` FOREIGN KEY (`step_id`) REFERENCES `run_steps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_links_owner` CHECK ((`element_id` is not null) + (`folder_id` is not null) + (`run_id` is not null) + (`step_id` is not null) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Пропуска ──

CREATE TABLE `tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope` enum('session','admin','project','run','step','agent') NOT NULL,
  `tail` varchar(8) NOT NULL DEFAULT '',
  `label` varchar(64) NOT NULL DEFAULT '',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `step_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `last_used_at` datetime(3) DEFAULT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tokens_hash` (`token_hash`),
  KEY `ix_tokens_project` (`project_id`,`scope`),
  KEY `ix_tokens_run` (`run_id`),
  KEY `ix_tokens_step` (`step_id`),
  KEY `ix_tokens_user` (`user_id`),
  KEY `fk_tokens_agent` (`agent_id`),
  KEY `fk_tokens_author` (`created_by`),
  CONSTRAINT `fk_tokens_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tokens_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tokens_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tokens_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tokens_step` FOREIGN KEY (`step_id`) REFERENCES `run_steps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_tokens_scope` CHECK (`scope` = 'session' and `user_id` is not null and `project_id` is null and `run_id` is null and `step_id` is null or `scope` = 'admin' and `user_id` is null and `project_id` is null and `run_id` is null and `step_id` is null or `scope` = 'project' and `project_id` is not null and `run_id` is null and `step_id` is null or `scope` = 'agent' and `project_id` is not null and `agent_id` is not null and `run_id` is null and `step_id` is null or `scope` = 'run' and `project_id` is not null and `run_id` is not null and `step_id` is null or `scope` = 'step' and `project_id` is not null and `run_id` is not null and `step_id` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Заготовки ──

-- Каталог: разделы с паспортами (lib/templates/catalog.php). Заготовка в каталоге — in_catalog = 1.
CREATE TABLE `template_categories` (
  `key` varchar(32) NOT NULL,
  `title` varchar(64) NOT NULL,
  `icon` varchar(32) NOT NULL DEFAULT '',
  `about` varchar(512) NOT NULL DEFAULT '',
  `passport` longtext NOT NULL DEFAULT '{}',
  `i18n` longtext NOT NULL DEFAULT '{}',
  `sort` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`key`),
  KEY `ix_categories_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Разделы — как в живой базе на 30.09: паспорт и перевод (i18n.en), чтобы свежая установка
-- показывала каталог и по-английски. Правят их в админке; посев — снимок, а не источник правды.
INSERT IGNORE INTO template_categories (`key`, title, icon, about, sort, passport, i18n) VALUES
  ('sites', 'Sites and landing pages', 'globe', 'Landing pages, product pages, multi-page sites and finishing existing ones', 10, '{"skeleton":"Brief → texts → images → page build → check (images in place, one h1, form) → decision; no — rebuild","questions":["What do we sell and to whom?","What action should the visitor take?","Do you have your own texts and photos?","Which service should make the images?"],"tune":["brief","page sections","image style","rebuild rounds"],"pitfalls":["Link images with relative paths next to the page","Texts go in a separate file, the build takes them word for word"]}', '{}'),
  ('photo', 'Photo', 'image', 'Generation, retouching, background removal, series of product cards', 20, '{"skeleton":"Source from in/ or generation → processing (background, size, series) → scripted build (PIL) → check of sizes and edges → decision","questions":["Do you have source photos?","For which platform and which sizes?","What style?","Which service should generate or edit?"],"tune":["style and subjects","sizes","margins and captions"],"pitfalls":["Text on the image is added by a script, not by the generator","Check sizes with a script, not by eye"]}', '{}'),
  ('video', 'Video', 'film', 'Videos from images, cutting, voiced clips, animation', 30, '{"skeleton":"Script → frames and voice-over in parallel → ffmpeg assembly → ffprobe check (duration, streams, size) → decision","questions":["What is the video about and how long?","Do you have source video or images?","Which format: horizontal or vertical?","Do you need a voice-over, and which voice?"],"tune":["number of frames and length","frame format","voice"],"pitfalls":["drawtext is not in every ffmpeg build — draw the titles as PNG and overlay them","Cut and join with re-encoding to one format"]}', '{}'),
  ('texts', 'Texts and content', 'text', 'Articles, descriptions, translations, editing and content plans', 40, '{"skeleton":"Topic or source → draft → script check (size, structure, lengths) → decision, no — redo → editing","questions":["What is the text about and for whom?","What length?","What tone?","Is there a source text or a list?"],"tune":["topic and audience","length limits","tone and language"],"pitfalls":["Count the length with a script — the model writes shorter than promised","Check every item of the batch, not the average"]}', '{}'),
  ('social', 'Social media and marketing', 'megaphone', 'Posts with images, ad creatives, series of publications', 50, '{"skeleton":"Brief or offer → texts and images in parallel → layout or assembly of publications → check","questions":["For which brand and social network?","Which rubrics or offer?","How many images?","Which service should make the images?"],"tune":["brand, rubrics, offer","number of images","creative size"],"pitfalls":["Make the images from the plan, not from the finished texts — the branches run in parallel","The headline goes on the image by a script with line breaks by width"]}', '{}'),
  ('docs', 'Documents and reports', 'file', 'Reports, proposals, presentations and summaries from data', 60, '{"skeleton":"Brief or data → calculations and charts in parallel → text → HTML layout (A4 print) → check of required parts","questions":["Which document and for whom?","Which data or brief?","What must be in the document?"],"tune":["brief or data","sections","design"],"pitfalls":["Take the figures in the conclusions from the calculations file","Price, terms, contacts — only from the brief"]}', '{}'),
  ('data', 'Data and APIs', 'database', 'Requests to services, exports, tables and summaries', 70, '{"skeleton":"Parameters or a table → requests or parsing (in parallel if there are several sources) → cleaning or a summary → check by the same script","questions":["Which data and from where?","What counts as a problem or as normal?","In what form do you need the result?"],"tune":["sources and parameters","cleaning rules","result format"],"pitfalls":["curl always with a timeout","Re-check with the same script as the first one"]}', '{}'),
  ('audio', 'Audio and voice-over', 'music', 'Voice-over of a text, podcasts, music and sound tracks', 80, '{"skeleton":"Text → split into phrases → voice-over → ffmpeg joining or mixing (loudnorm) → duration check","questions":["Which text?","Which voice and language?","Do you need music?","Which service should voice it?"],"tune":["voice","pauses","volume and music"],"pitfalls":["All pieces to one sample rate before joining","The voice louder than the background by 10–15 dB"]}', '{}'),
  ('code', 'Code and automation', 'code', 'Scripts, mini apps, checks and routine automation', 90, '{"skeleton":"Requirements → code and tests in parallel (tests from the requirements) → test run or syntax check → decision, no — fix the code","questions":["What should the program do?","Language and limits (no libraries?)","Which edge cases matter?"],"tune":["requirements","language","fix rounds"],"pitfalls":["Tests from the requirements, not from the code","Check JS syntax with node --check before the browser"]}', '{}'),
  ('basics', 'Goblin basics', 'book', 'Learning schemes: a chain, a fork, a loop, parallel branches, a check', 100, '{"skeleton":"Learning schemes: a chain, a decision with branches and an any-merge, a loop with back and max_attempts, parallel branches with an all-merge","questions":["Which mechanism to show?","What should the blocks do?"],"tune":["decision conditions","round limits","topic"],"pitfalls":["After a decision — join any, after parallel branches — join all","The blocks of a loop have max_attempts"]}', '{}');

CREATE TABLE `templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `family` enum('scheme','flow') NOT NULL DEFAULT 'scheme',
  `category` varchar(64) NOT NULL DEFAULT '',
  `about` varchar(512) NOT NULL DEFAULT '',
  `body` longtext NOT NULL,
  `passport` longtext NOT NULL DEFAULT '{}',
  `i18n` longtext NOT NULL DEFAULT '{}',
  `in_catalog` tinyint(1) NOT NULL DEFAULT 0,
  `checked_at` datetime(3) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `builtin` tinyint(1) NOT NULL DEFAULT 0,
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_templates_key` (`key`),
  KEY `ix_templates_shelf` (`family`,`sort`,`id`),
  KEY `fk_templates_owner` (`owner_id`),
  KEY `ix_templates_catalog` (`in_catalog`,`category`,`sort`),
  CONSTRAINT `fk_templates_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Встроенный ИИ ──

CREATE TABLE `ai_terminal_agents` (
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(16) NOT NULL DEFAULT 'builtin',
  `name` varchar(80) DEFAULT NULL,
  `scope` varchar(16) NOT NULL DEFAULT 'scheme',
  `scope_project_id` bigint(20) unsigned DEFAULT NULL,
  `scope_parent_id` bigint(20) unsigned DEFAULT NULL,
  `scope_folder_id` bigint(20) unsigned DEFAULT NULL,
  `invite_scope` varchar(16) DEFAULT NULL,
  `invite_project_id` bigint(20) unsigned DEFAULT NULL,
  `invite_parent_id` bigint(20) unsigned DEFAULT NULL,
  `invite_folder_id` bigint(20) unsigned DEFAULT NULL,
  `invite_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `invite_expires_at` bigint(20) unsigned DEFAULT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_seen_at` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_terminal_agent_invite` (`invite_hash`),
  UNIQUE KEY `uq_terminal_agent_token` (`token_hash`),
  CONSTRAINT `fk_terminal_agent_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_terminal_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(32) NOT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `folder_id` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'queued',
  `messages` longtext DEFAULT NULL CHECK (`messages` IS NULL OR json_valid(`messages`)),
  `response` longtext DEFAULT NULL CHECK (`response` IS NULL OR json_valid(`response`)),
  `lease_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `deadline_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_terminal_task_queue` (`user_id`,`state`,`id`),
  KEY `ix_terminal_task_expiry` (`deadline_at`),
  CONSTRAINT `fk_terminal_task_agent` FOREIGN KEY (`user_id`) REFERENCES `ai_terminal_agents` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_chats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('assistant','constructor') NOT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `project_key` varchar(32) NOT NULL DEFAULT '',
  `folder_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `token_id` bigint(20) unsigned DEFAULT NULL,
  `requester` varchar(80) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL DEFAULT 'New chat',
  `goal` text DEFAULT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'open',
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_chats_project` (`project_id`,`updated_at`),
  KEY `ix_chats_user` (`user_id`,`updated_at`),
  KEY `ix_chats_requester` (`requester`,`created_at`),
  KEY `fk_chats_token` (`token_id`),
  KEY `ix_chats_folder` (`folder_id`,`updated_at`),
  CONSTRAINT `fk_chats_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chats_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chats_token` FOREIGN KEY (`token_id`) REFERENCES `tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chats_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `body` mediumtext NOT NULL,
  `think` mediumtext DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_messages_chat` (`chat_id`,`id`),
  CONSTRAINT `fk_messages_chat` FOREIGN KEY (`chat_id`) REFERENCES `ai_chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `kind` enum('reply','questionnaire','diagram','play') NOT NULL DEFAULT 'reply',
  `request_id` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `state` enum('queued','running','proposal','applied','done','cancelled','failed') NOT NULL DEFAULT 'queued',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `token_id` bigint(20) unsigned DEFAULT NULL,
  `context` longtext DEFAULT NULL CHECK (`context` is null or json_valid(`context`)),
  `response` longtext DEFAULT NULL CHECK (`response` is null or json_valid(`response`)),
  `ops` longtext DEFAULT NULL CHECK (`ops` is null or json_valid(`ops`)),
  `preview` longtext DEFAULT NULL CHECK (`preview` is null or json_valid(`preview`)),
  `message_id` bigint(20) unsigned DEFAULT NULL,
  `error` text DEFAULT NULL,
  `model` varchar(80) NOT NULL DEFAULT '',
  `tokens_prompt` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tokens_completion` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tokens_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tokens_cached` bigint(20) unsigned NOT NULL DEFAULT 0,
  `usage_known` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `started_at` datetime(3) DEFAULT NULL,
  `finished_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_jobs_request` (`chat_id`,`request_id`),
  KEY `ix_ai_jobs_chat` (`chat_id`,`created_at`),
  KEY `ix_ai_jobs_user` (`user_id`,`created_at`),
  KEY `ix_ai_jobs_state` (`state`),
  KEY `fk_ai_jobs_token` (`token_id`),
  KEY `fk_ai_jobs_message` (`message_id`),
  CONSTRAINT `fk_ai_jobs_chat` FOREIGN KEY (`chat_id`) REFERENCES `ai_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_jobs_message` FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_jobs_token` FOREIGN KEY (`token_id`) REFERENCES `tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_jobs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_calls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `service` enum('deepseek','jev') NOT NULL,
  `what` varchar(24) NOT NULL DEFAULT '',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `model` varchar(80) NOT NULL DEFAULT '',
  `tokens_in` int(10) unsigned NOT NULL DEFAULT 0,
  `tokens_out` int(10) unsigned NOT NULL DEFAULT 0,
  `ms` int(10) unsigned NOT NULL DEFAULT 0,
  `ok` tinyint(1) NOT NULL DEFAULT 1,
  `error` varchar(300) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ix_api_calls_at` (`created_at`),
  KEY `ix_api_calls_user` (`user_id`,`created_at`),
  KEY `ix_api_calls_project` (`project_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Служебные ──

CREATE TABLE `journal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `rev` bigint(20) unsigned NOT NULL DEFAULT 0,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `op_scope` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operation_id` varchar(64) DEFAULT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `via` enum('editor','api','assistant','constructor','tool','system','legacy') NOT NULL DEFAULT 'api',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `token_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `label` varchar(64) NOT NULL DEFAULT '',
  `warnings` longtext DEFAULT NULL CHECK (`warnings` is null or json_valid(`warnings`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_op` (`project_id`,`op_scope`,`operation_id`),
  KEY `ix_journal_project` (`project_id`,`id`),
  KEY `ix_journal_time` (`project_id`,`created_at`),
  KEY `ix_journal_agent` (`agent_id`,`created_at`),
  KEY `fk_journal_run` (`run_id`),
  KEY `fk_journal_user` (`user_id`),
  KEY `fk_journal_token` (`token_id`),
  CONSTRAINT `fk_journal_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_journal_run` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_token` FOREIGN KEY (`token_id`) REFERENCES `tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_ops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_id` bigint(20) unsigned NOT NULL,
  `n` smallint(5) unsigned NOT NULL,
  `op` varchar(40) NOT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `target_no` int(10) unsigned DEFAULT NULL,
  `target_ref` varchar(40) NOT NULL DEFAULT '',
  `folder_id` bigint(20) unsigned DEFAULT NULL,
  `args` longtext DEFAULT NULL CHECK (`args` is null or json_valid(`args`)),
  `prev` longtext DEFAULT NULL CHECK (`prev` is null or json_valid(`prev`)),
  `result` longtext DEFAULT NULL CHECK (`result` is null or json_valid(`result`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_ops` (`journal_id`,`n`),
  KEY `ix_journal_ops_target` (`target_id`),
  KEY `ix_journal_ops_folder` (`folder_id`),
  CONSTRAINT `fk_journal_ops_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_ops_journal` FOREIGN KEY (`journal_id`) REFERENCES `journal` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `deletions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `entity` enum('folder','element','agent','asset','run') NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `no` int(10) unsigned DEFAULT NULL,
  `folder_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(512) NOT NULL DEFAULT '',
  `rev` bigint(20) unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_deletions_rev` (`project_id`,`rev`),
  KEY `ix_deletions_entity` (`project_id`,`entity`,`entity_id`),
  CONSTRAINT `fk_deletions_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `visits` (
  `visitor` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `url_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `hits` bigint(20) unsigned NOT NULL DEFAULT 0,
  `first_seen` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `last_seen` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`visitor`,`url_key`),
  KEY `ix_visits_project` (`project_id`),
  KEY `ix_visits_user` (`user_id`,`last_seen`),
  KEY `ix_visits_time` (`visitor`,`last_seen`),
  CONSTRAINT `fk_visits_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Документация ──

CREATE TABLE `documentation_pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lang` char(2) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'en',
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `slug` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title` varchar(512) NOT NULL,
  `summary_html` mediumtext NOT NULL,
  `body_html` longtext NOT NULL,
  `keywords` longtext NOT NULL DEFAULT '[]' CHECK (json_valid(`keywords`)),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `status` enum('proposed','approved','implemented') DEFAULT NULL,
  `reviewed_at` date DEFAULT NULL,
  `source_note` text DEFAULT NULL,
  `references_json` longtext DEFAULT NULL CHECK (`references_json` is null or json_valid(`references_json`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documentation_slug` (`lang`,`slug`),
  KEY `ix_documentation_tree` (`parent_id`,`sort_order`,`id`),
  CONSTRAINT `fk_documentation_parent` FOREIGN KEY (`parent_id`) REFERENCES `documentation_pages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documentation_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_page_id` bigint(20) unsigned NOT NULL,
  `target_page_id` bigint(20) unsigned NOT NULL,
  `label` varchar(512) NOT NULL DEFAULT '',
  `resource_type` enum('related','php','html','css','javascript','database') NOT NULL DEFAULT 'related',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documentation_link` (`source_page_id`,`target_page_id`),
  KEY `ix_documentation_outgoing` (`source_page_id`,`sort_order`,`id`),
  KEY `ix_documentation_incoming` (`target_page_id`,`source_page_id`),
  CONSTRAINT `fk_documentation_link_source` FOREIGN KEY (`source_page_id`) REFERENCES `documentation_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documentation_link_target` FOREIGN KEY (`target_page_id`) REFERENCES `documentation_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documentation_callouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `page_id` bigint(20) unsigned NOT NULL,
  `entry_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` enum('future_plan','agent_correction') NOT NULL,
  `body_html` mediumtext NOT NULL,
  `author_name` varchar(255) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `approval` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` varchar(255) NOT NULL DEFAULT '',
  `reviewed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_callout_key` (`page_id`,`entry_key`),
  KEY `ix_doc_callout_page` (`page_id`,`sort_order`,`id`),
  KEY `ix_doc_callout_kind` (`kind`,`page_id`),
  CONSTRAINT `fk_doc_callout_page` FOREIGN KEY (`page_id`) REFERENCES `documentation_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schema_migrations` (
  `name` varchar(128) NOT NULL,
  `applied_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `took_ms` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Новая база уже на уровне всех миграций.
INSERT INTO schema_migrations (name) VALUES
  ('001-v2.sql'),
  ('002-templates.sql'),
  ('003-table.sql'),
  ('004-agent-token.sql'),
  ('005-cover-from-run.sql'),
  ('006-run-events.sql'),
  ('007-engine.sql'),
  ('008-link.sql'),
  ('009-lead-driver.sql'),
  ('010-result-text.sql'),
  ('011-run-env.sql'),
  ('012-ai-think.sql'),
  ('013-ai-chat-folder.sql'),
  ('014-documentation.sql'),
  ('015-documentation-link-types.sql'),
  ('016-documentation-callouts.sql'),
  ('017-documentation-workflow.sql'),
  ('018-role-scheme.sql'),
  ('019-lead-seen.sql'),
  ('020-run-events.sql'),
  ('021-catalog.sql'),
  ('022-docs-lang.sql'),
  ('023-project-lang.sql'),
  ('024-catalog-i18n.sql'),
  ('025-user-access.sql'),
  ('026-run-events-run-fk.sql'),
  ('027-run-steps-who.sql'),
  ('028-run-steps-lead-self.sql'),
  ('029-runs-agent-dir.sql'),
  ('030-projects-seen-at.sql'),
  ('031-run-steps-delivered.sql'),
  ('032-folders-drop-lead-seen.sql'),
  ('033-api-calls.sql'),
  ('034-projects-files-server.sql'),
  ('035-graph.sql'),
  ('036-run-graph.sql'),
  ('037-ai-terminal-agent.sql');
