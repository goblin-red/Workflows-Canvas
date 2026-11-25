<?php
declare(strict_types=1);

const GRAPH_PREVIEW_ITEMS = 4;

/** GET graph.scene — данные сцены для холста без содержимого материалов. */
function graphSceneGet(): void
{
    $project = requireProject(false);
    $parent = input('parent');
    $legacyFolder = input('legacyFolder');
    if ($parent !== null && $legacyFolder !== null) throw new ApiError(t('server.graph.scene_address'));
    reply(graphSceneRead(
        (int) $project['id'],
        $parent === null ? null : graphId($parent),
        $legacyFolder === null ? null : graphId($legacyFolder)
    ));
}

/** Сцена и сводки читаются под одной ревизией. */
function graphSceneRead(int $scopeId, ?int $parent = null, ?int $legacyFolder = null): array
{
    // В SQLite обычная транзакция сохраняет один снимок без замка на запись.
    db()->beginTransaction();
    try {
        $lock = dbDriver() === 'sqlite' ? '' : ' LOCK IN SHARE MODE';
        $rev = dbValue('SELECT rev FROM projects WHERE id = ?' . $lock, [$scopeId]);
        if ($rev === null) throw new ApiError(t('server.project.not_found'), 'not_found');
        $root = graphRootId($scopeId);
        $center = $legacyFolder === null ? ($parent ?? $root) : graphLegacyFolderBlock($scopeId, $legacyFolder);
        $classicFolder = null;
        $blocks = $placements = $arrows = $previews = [];

        if ($center !== null) {
            [$rows, $edges] = graphScene($scopeId, $center, true);
            $blocks = array_map('graphBlockShape', $rows);
            $classicFolder = graphSceneLegacyFolder($scopeId, $blocks[0]);
            foreach ($edges as $edge) {
                $shape = graphArrowShape($edge);
                if ($edge['kind'] === 'contains') $placements[] = graphPlacementShape($shape);
                else $arrows[] = $shape;
            }
            foreach ($blocks as $block) {
                if (in_array($block['kind'], ['project', 'folder', 'scheme'], true)) {
                    $previews[$block['id']] = ['total' => 0, 'items' => []];
                }
            }
            if ($previews) $previews = graphScenePreviews($scopeId, $center, $previews);
        }

        $out = [
            'rootId' => $root, 'parentId' => $center, 'rev' => (int) $rev,
            'blocks' => $blocks, 'placements' => $placements, 'arrows' => $arrows, 'previews' => (object) $previews,
            'legacyFolder' => $classicFolder,
        ];
        if ($root === null || $center === $root) {
            $out['hasLegacyFolders'] = (bool) dbValue('SELECT 1 FROM folders WHERE project_id = ? LIMIT 1', [$scopeId]);
        }
        db()->commit();
        return $out;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/** У перенесённой схемы и её папки-контейнера один классический адрес. */
function graphSceneLegacyFolder(int $scopeId, array $block): ?int
{
    if (!in_array($block['kind'], ['folder', 'scheme'], true)) return null;
    $id = $block['id'];
    $marker = $block['properties']->legacyMigrationKey ?? null;
    $containerFolder = $block['kind'] === 'folder' && is_string($marker)
        && preg_match('/\Afolder-container:([1-9][0-9]*)\z/D', $marker, $match) ? (int) $match[1] : null;
    $folder = dbValue(
        "SELECT f.id FROM graph_legacy_refs r JOIN folders f ON f.id = r.legacy_id AND f.project_id = r.scope_id
          WHERE r.scope_id = ? AND r.legacy_kind = 'folder'
            AND (r.block_id = ? OR (r.legacy_id = ? AND EXISTS (
                SELECT 1 FROM arrows a WHERE a.scope_id = r.scope_id AND a.kind = 'contains'
                  AND a.source_id = ? AND a.target_id = r.block_id)))
          ORDER BY CASE WHEN r.block_id = ? THEN 0 ELSE 1 END, f.id LIMIT 1",
        [$scopeId, $id, $containerFolder, $id, $id]
    );
    return $folder === null ? null : (int) $folder;
}

/** Прямые папки, схемы и проекты. Карточки отсортированы по размещению, заголовки ограничены на стороне БД. */
function graphScenePreviews(int $scopeId, int $center, array $previews): array
{
    // OFFSET исключает пятый и следующие элементы без оконных функций MySQL 8.
    $offset = GRAPH_PREVIEW_ITEMS - 1;
    $rows = dbAll(
        "SELECT totals.parent_id, totals.total, child.id, child.kind, child.title
           FROM (
               SELECT a.source_id AS parent_id, COUNT(*) AS total
                 FROM arrows a
                 JOIN blocks p ON p.scope_id = a.scope_id AND p.id = a.source_id
                 JOIN blocks b ON b.scope_id = a.scope_id AND b.id = a.target_id
                WHERE a.scope_id = ? AND a.kind = 'contains'
                  AND p.kind IN ('project','folder','scheme') AND b.kind IN ('project','folder','scheme')
                  AND (a.source_id = ? OR EXISTS (
                      SELECT 1 FROM arrows scene
                       WHERE scene.scope_id = a.scope_id AND scene.kind = 'contains'
                         AND scene.source_id = ? AND scene.target_id = a.source_id
                  ))
                GROUP BY a.source_id
           ) totals
           JOIN arrows visible ON visible.scope_id = ? AND visible.source_id = totals.parent_id AND visible.kind = 'contains'
           JOIN blocks child ON child.scope_id = visible.scope_id AND child.id = visible.target_id
          WHERE child.kind IN ('project','folder','scheme')
            AND NOT EXISTS (
                SELECT 1 FROM arrows earlier JOIN blocks item ON item.scope_id = earlier.scope_id AND item.id = earlier.target_id
                 WHERE earlier.scope_id = visible.scope_id AND earlier.source_id = visible.source_id AND earlier.kind = 'contains'
                   AND item.kind IN ('project','folder','scheme')
                   AND (earlier.sort < visible.sort OR (earlier.sort = visible.sort AND earlier.id < visible.id))
                 LIMIT 1 OFFSET $offset
            )
          ORDER BY totals.parent_id, visible.sort, visible.id",
        [$scopeId, $center, $center, $scopeId]
    );
    foreach ($rows as $row) {
        $id = (int) $row['parent_id'];
        $previews[$id]['total'] = (int) $row['total'];
        $previews[$id]['items'][] = ['id' => (int) $row['id'], 'kind' => $row['kind'], 'title' => $row['title']];
    }
    return $previews;
}
