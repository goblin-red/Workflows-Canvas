-- Канонические ссылки сохранены вместе со снимком схемы прогона.
ALTER TABLE `runs`
  MODIFY COLUMN `folder_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `parent_block_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `graph_snapshot` longtext DEFAULT NULL,
  ADD KEY `ix_runs_parent` (`project_id`,`parent_block_id`,`state`),
  ADD CONSTRAINT `ck_runs_scene` CHECK ((`folder_id` IS NULL) <> (`parent_block_id` IS NULL)),
  ADD CONSTRAINT `ck_runs_snapshot` CHECK ((`parent_block_id` IS NULL AND `graph_snapshot` IS NULL) OR (`parent_block_id` IS NOT NULL AND `graph_snapshot` IS NOT NULL AND json_valid(`graph_snapshot`)));

ALTER TABLE `run_steps`
  ADD COLUMN `block_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `block_kind` varchar(24) DEFAULT NULL,
  ADD COLUMN `via_arrow_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `chosen_arrow_id` bigint(20) unsigned DEFAULT NULL,
  ADD KEY `ix_steps_run_block` (`run_id`,`block_id`),
  ADD CONSTRAINT `ck_steps_graph_refs` CHECK (`block_id` IS NULL OR (`element_id` IS NULL AND `via_edge_id` IS NULL AND `chosen_edge_id` IS NULL AND `block_kind` IS NOT NULL));

ALTER TABLE `run_marks`
  MODIFY COLUMN `edge_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `arrow_id` bigint(20) unsigned DEFAULT NULL,
  ADD UNIQUE KEY `uq_marks_arrow_once` (`run_id`,`arrow_id`,`from_step_id`),
  ADD CONSTRAINT `ck_marks_graph_edge` CHECK ((`edge_id` IS NULL) <> (`arrow_id` IS NULL));
