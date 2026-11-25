CREATE TABLE IF NOT EXISTS `ai_terminal_agents` (
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

CREATE TABLE IF NOT EXISTS `ai_terminal_tasks` (
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
