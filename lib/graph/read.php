<?php
declare(strict_types=1);

/**
 * graph.get принимает project и один адрес сцены: parent (ID блока) или legacyFolder (ID старой папки).
 * Без адреса открывает корень. Ответ содержит rootId, parentId, rev, blocks и arrows.
 * view=scene оставляет метаданные материалов. Их полную карточку возвращает block.get с параметром id.
 */
function graphGet(): void
{
    $project = requireProject(false);
    $parent = input('parent');
    $legacyFolder = input('legacyFolder');
    if ($parent !== null && $legacyFolder !== null) {
        throw new ApiError(t('server.graph.scene_address'));
    }
    reply(graphRead(
        $project,
        $parent === null ? null : graphId($parent),
        false,
        legacyFolder: $legacyFolder === null ? null : graphId($legacyFolder),
        summary: input('view') === 'scene'
    ));
}

function graphNeighbors(): void
{
    reply(graphRead(requireProject(false), graphId(input('block')), true));
}

/** graph.projects с пустым project открывает список, с ключом — карточку по старой ссылке. */
function graphProjects(): void
{
    $caller = caller();
    $joins = '';
    $order = '';
    if ($caller['project_id'] || trim(request()['project']) !== '') {
        $project = requireProject(false);
        $where = 'p.id = ?';
        $args = [(int) $project['id']];
    } else {
        $keys = is_string(input('keys', '')) ? guestKeys() : [];
        $known = $keys ? 'p.url_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')' : '';
        $userId = $caller['user_id'];
        if ($userId) {
            // Как в «Мои проекты»: свои, посещённые и гостевые ключи, которые браузер передал после входа.
            // Право увидеть переданные карточки не требует менять их владельца при чтении.
            $joins = 'LEFT JOIN (
                SELECT project_id, MAX(last_seen) AS last_seen
                  FROM visits WHERE user_id = ? AND project_id IS NOT NULL GROUP BY project_id
            ) seen ON seen.project_id = p.id';
            $where = 'p.owner_id = ? OR seen.project_id IS NOT NULL';
            if ($known !== '') $where .= " OR (p.owner_id IS NULL AND $known)";
            $args = [$userId, $userId, ...$keys];
            $order = 'ORDER BY GREATEST(p.updated_at, COALESCE(seen.last_seen, p.updated_at)) DESC, p.id DESC LIMIT 200';
        } else {
            if (!$keys) reply(['projects' => []]);
            $where = $known;
            $args = $keys;
            $order = 'ORDER BY GREATEST(p.updated_at, COALESCE(p.seen_at, p.updated_at)) DESC, p.id DESC';
        }
    }

    // Карточки доступны и до миграции 035, и для проектов без заполненной карты корней.
    if (dbValue("SELECT 1 FROM schema_migrations WHERE name = '035-graph.sql'")) {
        $columns = 'b.id AS root_id, COALESCE(b.title, p.title) AS title, b.appearance';
        $joins .= " LEFT JOIN graph_legacy_refs r ON r.scope_id = p.id AND r.legacy_kind = 'project' AND r.legacy_id = p.id
                    LEFT JOIN blocks b ON b.scope_id = p.id AND b.id = r.block_id AND b.kind = 'project'";
    } else {
        $columns = 'NULL AS root_id, p.title, NULL AS appearance';
    }
    $rows = dbAll("SELECT p.url_key, p.rev, $columns FROM projects p $joins WHERE $where $order", $args);
    reply(['projects' => array_map(static fn(array $row): array => [
        'key' => $row['url_key'],
        'rootId' => $row['root_id'] === null ? null : (int) $row['root_id'],
        'title' => $row['title'],
        'appearance' => (object) json_decode($row['appearance'] ?? '{}'),
        'rev' => (int) $row['rev'],
    ], $rows)]);
}

/** Ревизия для опроса редактором и передачи в ifRev. */
function graphRev(): void
{
    $project = requireProject(false);
    reply(['rev' => (int) $project['rev']]);
}

function graphBlockGet(): void
{
    $project = requireProject(false);
    $row = graphRow('blocks', (int) $project['id'], graphId(input('id')));
    reply(['block' => graphBlockShape($row)]);
}

/** Снимок родителя, его прямых детей, размещений и связей внутри сцены. */
function graphRead(array $project, ?int $center, bool $neighbors, ?int $legacyFolder = null, bool $summary = false): array
{
    $scopeId = (int) $project['id'];
    // Обычная транзакция чтения даёт один снимок и оставляет SQLite доступной писателям в WAL.
    db()->beginTransaction();
    try {
        $projectLock = dbDriver() === 'sqlite' ? '' : ' LOCK IN SHARE MODE';
        $rev = dbValue('SELECT rev FROM projects WHERE id = ?' . $projectLock, [$scopeId]);
        if ($rev === null) throw new ApiError(t('server.project.not_found'), 'not_found');
        $root = graphRootId($scopeId);
        $parent = $legacyFolder === null ? ($center ?? $root) : graphLegacyFolderBlock($scopeId, $legacyFolder);
        $blocks = $arrows = [];
        if ($parent !== null) {
            if ($neighbors) {
                $blocks[] = graphRow('blocks', $scopeId, $parent);
                $blocks = array_merge($blocks, dbAll(
                    'SELECT * FROM blocks WHERE scope_id = ? AND id <> ? AND id IN (
                        SELECT target_id FROM arrows WHERE scope_id = ? AND source_id = ?
                        UNION
                        SELECT source_id FROM arrows WHERE scope_id = ? AND target_id = ?
                     ) ORDER BY id',
                    [$scopeId, $parent, $scopeId, $parent, $scopeId, $parent]
                ));
                $arrows = dbAll(
                    'SELECT * FROM arrows WHERE scope_id = ? AND (source_id = ? OR target_id = ?) ORDER BY sort, id',
                    [$scopeId, $parent, $parent]
                );
            } else {
                [$blocks, $arrows] = graphScene($scopeId, $parent, $summary);
            }
        }
        $out = [
            'rootId' => $root, 'parentId' => $parent, 'rev' => (int) $rev,
            'blocks' => array_map('graphBlockShape', $blocks), 'arrows' => array_map('graphArrowShape', $arrows),
        ];
        db()->commit();
        return $out;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/** Старый ID ведёт на холст схемы или на папку без схемы, включая переносы ранней версии. */
function graphLegacyFolderBlock(int $scopeId, int $legacyFolder, bool $required = true): ?int
{
    $id = dbValue(
        "SELECT b.id FROM graph_legacy_refs r JOIN blocks b ON b.id = r.block_id AND b.scope_id = r.scope_id
          WHERE r.scope_id = ? AND r.legacy_kind = 'folder' AND r.legacy_id = ? AND b.kind IN ('folder','scheme')",
        [$scopeId, $legacyFolder]
    );
    if ($id === null && $required) throw new ApiError(t('server.not_found.folder'), 'not_found');
    return $id === null ? null : (int) $id;
}

function graphScene(int $scopeId, int $parent, bool $summary): array
{
    // Содержимое материала не передаётся из БД в PHP при чтении сцены.
    $columns = $summary ? "
        b.id, b.scope_id, b.kind, b.title, b.appearance, b.rev,
        CASE WHEN b.kind IN ('asset', 'material') THEN '' ELSE b.description END AS description,
        CASE WHEN b.kind IN ('asset', 'material')
             THEN JSON_REMOVE(b.properties, '$.body', '$.uri', '$.fileKey')
             ELSE b.properties END AS properties" : 'b.*';
    $parentBlock = dbRow("SELECT $columns FROM blocks b WHERE b.scope_id = ? AND b.id = ?", [$scopeId, $parent]);
    if (!$parentBlock) throw new ApiError(t('server.graph.block_not_found'), 'not_found');
    $children = dbAll(
        "SELECT $columns FROM arrows a JOIN blocks b ON b.scope_id = a.scope_id AND b.id = a.target_id
          WHERE a.scope_id = ? AND a.source_id = ? AND a.kind = 'contains'
          ORDER BY a.sort, a.id",
        [$scopeId, $parent]
    );
    $arrows = dbAll(
        "SELECT a.* FROM arrows a
          WHERE a.scope_id = ? AND (
              (a.kind = 'contains' AND a.source_id = ?)
              OR (a.kind <> 'contains'
                  AND (a.source_id = ? OR EXISTS (
                      SELECT 1 FROM arrows s WHERE s.scope_id = a.scope_id
                       AND s.source_id = ? AND s.target_id = a.source_id AND s.kind = 'contains'
                  ))
                  AND (a.target_id = ? OR EXISTS (
                      SELECT 1 FROM arrows e WHERE e.scope_id = a.scope_id
                       AND e.source_id = ? AND e.target_id = a.target_id AND e.kind = 'contains'
                  )))
          )
          ORDER BY a.sort, a.id",
        [$scopeId, $parent, $parent, $parent, $parent, $parent]
    );
    return [array_merge([$parentBlock], $children), $arrows];
}
