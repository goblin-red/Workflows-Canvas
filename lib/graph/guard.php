<?php
declare(strict_types=1);

/** Защищает схему действующего прогона в обоих форматах хранения. */
function graphGuard(int $scopeId, array $blocks, int $arrowId = 0): void
{
    if (!dbValue(
        "SELECT id FROM runs WHERE project_id = ? AND engine = 2 AND state IN ('running', 'paused') LIMIT 1",
        [$scopeId]
    )) return;

    $nativeRuns = [];
    if (dbValue('SELECT name FROM schema_migrations WHERE name = ?', ['036-run-graph.sql'])) {
        $nativeRuns = array_column(dbAll(
            "SELECT id, `no`, parent_block_id FROM runs WHERE project_id = ? AND engine = 2
              AND parent_block_id IS NOT NULL AND state IN ('running', 'paused') ORDER BY id",
            [$scopeId]
        ), null, 'parent_block_id');
    }

    $refs = dbAll('SELECT legacy_kind, legacy_id FROM graph_legacy_refs WHERE scope_id = ? AND arrow_id = ?', [$scopeId, $arrowId]);
    $seen = [];
    while ($blocks) {
        $id = (int) array_pop($blocks);
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        if (isset($nativeRuns[$id])) {
            $run = $nativeRuns[$id];
            $label = 'r' . (int) $run['no'];
            throw new ApiError(
                t('server.guard.message', ['what' => t('server.graph.guard_edit'), 'run' => $label]), 'conflict',
                ['code2' => 'active_run', 'active_run' => $label, 'run' => (int) $run['id'], 'parent' => $id]
            );
        }
        foreach (dbAll(
            'SELECT legacy_kind, legacy_id FROM graph_legacy_refs WHERE scope_id = ? AND block_id = ?',
            [$scopeId, $id]
        ) as $ref) $refs[] = $ref;
        foreach (dbAll(
            "SELECT source_id FROM arrows WHERE scope_id = ? AND target_id = ? AND kind = 'contains'",
            [$scopeId, $id]
        ) as $parent) $blocks[] = $parent['source_id'];
        if ($nativeRuns) {
            foreach (dbAll(
                "SELECT source_id, properties FROM arrows WHERE scope_id = ? AND target_id = ? AND kind = 'link'",
                [$scopeId, $id]
            ) as $link) {
                $properties = json_decode($link['properties'], true);
                if (in_array($properties['relation'] ?? '', ['asset', 'agent', 'role', 'gatewayTarget'], true)) {
                    $blocks[] = $link['source_id'];
                }
            }
        }
    }

    $folders = [];
    $what = t('server.graph.guard_edit');
    foreach ($refs as $ref) {
        $id = (int) $ref['legacy_id'];
        switch ($ref['legacy_kind']) {
            case 'folder':
                $folders[] = $id;
                break;
            case 'element':
                $folders[] = (int) dbValue('SELECT folder_id FROM elements WHERE project_id = ? AND id = ?', [$scopeId, $id]);
                break;
            case 'agent':
                activeGuardAgent($scopeId, $id, $what);
                break;
            case 'asset':
                activeGuardAsset($scopeId, $id, $what);
                break;
        }
    }
    if ($folders) activeGuardFolders($scopeId, $folders, $what);
}
