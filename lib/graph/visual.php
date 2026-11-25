<?php
declare(strict_types=1);

const GRAPH_VISUAL_HIERARCHY = ['project', 'folder', 'scheme'];

/** Classic browser presentation. The source records still belong to the graph. */
function graphVisualProject(): void
{
    if (trim(request()['project']) === '' && !caller()['project_id']) {
        reply(['projects' => graphVisualProjectList(), 'me' => projectMe(), 'agentsRemote' => agentsRemote()]);
    }
    reply(graphVisualSnapshot(static function (array $project, ?array $root): array {
        $scope = (int) $project['id'];
        $rootId = $root === null ? null : (int) $root['id'];
        $folders = $rootId === null ? [] : graphVisualFolders($scope, $rootId);
        $defaultParentId = $rootId === null ? null : graphVisualDefaultParent($scope, $rootId, $folders);
        [$agents, $roles] = $rootId === null ? [[], []] : graphVisualAgents($scope);
        $out = [
            'project' => graphVisualProjectShape($project, $root) + ['defaultParentId' => $defaultParentId],
            'me' => projectMe(),
            'folders' => array_values($folders),
            'agents' => $agents,
            'roles' => $roles,
            'rootId' => $rootId,
            'defaultParentId' => $defaultParentId,
            'rev' => (int) $project['rev'],
        ];
        if (!empty(input('full'))) {
            $scheme = [];
            if ($rootId !== null && $defaultParentId === $rootId) {
                [$scheme[$rootId]] = graphVisualScheme($scope, $rootId);
                $out['folders'][] = graphVisualFolderShape($root, null, $rootId);
            }
            foreach ($folders as $folder) {
                [$scheme[$folder['id']]] = graphVisualScheme($scope, $folder['id']);
            }
            $out['scheme'] = (object) $scheme;
        }
        return $out;
    }));
}

function graphVisualScene(): void
{
    $parent = input('parent');
    $legacyFolder = input('legacyFolder');
    if ($parent !== null && $legacyFolder !== null) throw new ApiError(t('server.graph.scene_address'));
    reply(graphVisualSnapshot(static function (array $project, ?array $root) use ($parent, $legacyFolder): array {
        $scope = (int) $project['id'];
        $rootId = $root === null ? null : (int) $root['id'];
        $parentId = match (true) {
            $legacyFolder !== null => graphLegacyFolderBlock($scope, graphId($legacyFolder)),
            $parent !== null => graphId($parent),
            $rootId !== null => graphVisualDefaultParent($scope, $rootId, graphVisualFolders($scope, $rootId)),
            default => null,
        };
        $folder = null;
        $scheme = [];
        if ($parentId !== null) {
            $center = $rootId === $parentId ? $root : graphRow('blocks', $scope, $parentId);
            $location = graphVisualIncoming($scope, $parentId);
            $folder = graphVisualFolderShape($center, $location, $rootId);
            $folder['_graph']['legacyFolder'] = graphVisualLegacyFolder($scope, $folder);
            [$scheme, $folder['assets']] = graphVisualScheme($scope, $parentId);
            $folder['count'] = count(array_filter($scheme, static fn(array $item): bool => isset($item['_graph']['blockId'])));
        }
        return [
            'project' => graphVisualProjectShape($project, $root),
            'folder' => $folder,
            'scheme' => $scheme,
            'rootId' => $rootId,
            'rev' => (int) $project['rev'],
            'legacyFolder' => $folder['_graph']['legacyFolder'] ?? null,
        ];
    }));
}

/** Capture graph and shared project metadata at the same project revision. */
function graphVisualSnapshot(callable $read): array
{
    $scope = (int) requireProject(false)['id'];
    db()->beginTransaction();
    try {
        $lock = dbDriver() === 'sqlite' ? '' : ' LOCK IN SHARE MODE';
        $project = dbRow('SELECT * FROM projects WHERE id = ?' . $lock, [$scope]);
        if ($project === null) throw new ApiError(t('server.project.not_found'), 'not_found');
        $rootId = graphRootId($scope);
        $root = $rootId === null ? null : graphRow('blocks', $scope, $rootId);
        $result = $read($project, $root);
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

/** Same shared account/project fields as project.get, with the graph title and root. */
function graphVisualProjectShape(array $project, ?array $root): array
{
    $shape = projectShape($project);
    if ($root !== null) $shape['title'] = (string) $root['title'];
    return $shape + [
        'rootId' => $root === null ? null : (int) $root['id'],
        'agentsRemote' => agentsRemote(), 'filesLimit' => filesLimit(),
    ];
}

/** Listing does not claim the guest projects or change their visited timestamps. */
function graphVisualProjectList(): array
{
    $keys = guestKeys();
    $marks = implode(',', array_fill(0, count($keys), '?'));
    $user = caller()['user_id'];
    $join = '';
    if ($user) {
        $join = 'LEFT JOIN (SELECT project_id, MAX(last_seen) AS last_seen FROM visits
                            WHERE user_id = ? AND project_id IS NOT NULL GROUP BY project_id) seen ON seen.project_id = p.id';
        $where = 'p.owner_id = ? OR seen.project_id IS NOT NULL';
        $seen = 'seen.last_seen';
        $params = [$user, $user];
        if ($keys) {
            $where .= " OR (p.owner_id IS NULL AND p.url_key IN ($marks))";
            array_push($params, ...$keys);
        }
    } else {
        if (!$keys) return [];
        $where = "p.url_key IN ($marks)";
        $seen = 'p.seen_at';
        $params = $keys;
    }
    $rows = dbAll("SELECT p.*, $seen AS last_seen, GREATEST(p.updated_at, COALESCE($seen, p.updated_at)) AS touched,
                   b.id AS root_id, b.title AS root_title,
                   (SELECT COUNT(*) FROM blocks f WHERE f.scope_id = p.id AND f.kind IN ('folder','scheme')) AS folders
              FROM projects p
              LEFT JOIN graph_legacy_refs r ON r.scope_id = p.id AND r.legacy_kind = 'project' AND r.legacy_id = p.id
              LEFT JOIN blocks b ON b.scope_id = p.id AND b.id = r.block_id
              $join WHERE $where ORDER BY touched DESC, p.id DESC LIMIT 200", $params);
    return array_map(static fn(array $row): array => array_replace(projectShape($row), [
        'rootId' => $row['root_id'] === null ? null : (int) $row['root_id'],
        'title' => $row['root_title'] ?? $row['title'],
        'folders' => (int) $row['folders'], 'seen' => (string) ($row['last_seen'] ?: $row['updated_at']),
        'touched' => (string) $row['touched'],
    ]), $rows);
}

/** Build the original rail from graph containers. The old rail displays one path per folder. */
function graphVisualFolders(int $scope, int $root): array
{
    $rows = dbAll("SELECT b.*, a.id AS placement_id, a.source_id AS parent_id, a.sort AS placement_sort,
                   a.properties AS placement_properties, a.appearance AS placement_appearance,
                   (SELECT COUNT(*) FROM arrows c JOIN blocks item ON item.scope_id = c.scope_id AND item.id = c.target_id
                     WHERE c.scope_id = b.scope_id AND c.kind = 'contains' AND c.source_id = b.id
                       AND item.kind NOT IN ('project','folder','scheme','agent','role','asset','material')) AS child_count
              FROM arrows a JOIN blocks b ON b.scope_id = a.scope_id AND b.id = a.target_id
              WHERE a.scope_id = ? AND a.kind = 'contains' AND b.kind IN ('folder','scheme')
              ORDER BY a.sort, a.id", [$scope]);
    $children = [];
    foreach ($rows as $row) $children[(int) $row['parent_id']][] = $row;
    $folders = [];
    $visit = static function (int $parent) use (&$visit, &$folders, $children, $root): void {
        foreach ($children[$parent] ?? [] as $row) {
            $id = (int) $row['id'];
            if (isset($folders[$id])) continue;
            $folders[$id] = graphVisualFolderShape($row, $row, $root);
            $visit($id);
        }
    };
    $visit($root);
    return $folders;
}

/** With no explicit address, prefer a workflow over the hierarchy's container. */
function graphVisualDefaultParent(int $scope, int $root, array $folders): int
{
    foreach ($folders as $folder) {
        if ($folder['_graph']['kind'] === 'scheme') return $folder['id'];
    }
    $rootHasCards = dbValue("SELECT 1 FROM arrows a JOIN blocks b ON b.scope_id = a.scope_id AND b.id = a.target_id
        WHERE a.scope_id = ? AND a.source_id = ? AND a.kind = 'contains'
          AND b.kind NOT IN ('project','folder','scheme','agent','role','asset','material') LIMIT 1", [$scope, $root]);
    if ($rootHasCards) return $root;
    foreach ($folders as $folder) {
        if ($folder['count'] > 0) return $folder['id'];
    }
    return array_key_first($folders) ?? $root;
}

function graphVisualIncoming(int $scope, int $block): ?array
{
    return dbRow("SELECT id AS placement_id, source_id AS parent_id, sort AS placement_sort,
                         properties AS placement_properties, appearance AS placement_appearance
                    FROM arrows WHERE scope_id = ? AND target_id = ? AND kind = 'contains'
                   ORDER BY sort, id LIMIT 1", [$scope, $block]);
}

function graphVisualLegacyFolder(int $scope, array $folder): ?int
{
    $marker = $folder['_graph']['properties']->legacyMigrationKey ?? null;
    $containerLegacyId = $folder['_graph']['kind'] === 'folder' && is_string($marker)
        && preg_match('/\Afolder-container:([1-9][0-9]*)\z/D', $marker, $match) ? (int) $match[1] : null;
    $id = $folder['id'];
    $legacy = dbValue("SELECT r.legacy_id FROM graph_legacy_refs r
        WHERE r.scope_id = ? AND r.legacy_kind = 'folder'
          AND (r.block_id = ? OR (r.legacy_id = ? AND EXISTS (
               SELECT 1 FROM arrows a WHERE a.scope_id = r.scope_id AND a.kind = 'contains'
                 AND a.source_id = ? AND a.target_id = r.block_id)))
        ORDER BY CASE WHEN r.block_id = ? THEN 0 ELSE 1 END, r.legacy_id LIMIT 1",
        [$scope, $id, $containerLegacyId, $id, $id]);
    return $legacy === null ? null : (int) $legacy;
}

function graphVisualFolderShape(array $block, ?array $location, ?int $root): array
{
    $shape = graphBlockShape($block);
    $id = $shape['id'];
    $parent = isset($location['parent_id']) ? (int) $location['parent_id'] : null;
    $properties = $shape['properties'];
    $placementAppearance = graphVisualPlacementAppearance($location);
    return [
        'id' => $id, 'name' => $shape['title'], 'parent' => $parent === $root ? null : $parent,
        'sort' => (int) ($location['placement_sort'] ?? 0), 'workDir' => (string) ($properties->workDir ?? ''),
        'workUrl' => (string) ($properties->workUrl ?? ''), 'shareScheme' => propOn($properties->shareScheme ?? false),
        'runEnv' => (string) ($properties->runEnv ?? ''), 'roleScheme' => (string) ($properties->roleScheme ?? 'solo'),
        'style' => (object) array_replace((array) $shape['appearance'], (array) $placementAppearance,
            isset($properties->preparedRun) ? ['preparedRun' => (int) $properties->preparedRun] : []),
        'camera' => $properties->camera ?? null, 'contentRev' => (int) ($properties->contentRev ?? $shape['rev']),
        'rev' => $shape['rev'], 'count' => (int) ($block['child_count'] ?? 0),
        '_graph' => ['blockId' => $id, 'placementId' => isset($location['placement_id']) ? (int) $location['placement_id'] : null,
            'parentId' => $parent, 'kind' => $shape['kind'], 'properties' => $properties,
            'appearance' => $shape['appearance'], 'placementAppearance' => $placementAppearance],
    ];
}

function graphVisualPlacementAppearance(?array $row): object
{
    if ($row === null) return new stdClass();
    $properties = graphProperties($row['placement_properties']);
    return (object) ($properties['placement']['appearance'] ?? graphProperties($row['placement_appearance']));
}

function graphVisualAgents(int $scope): array
{
    $roles = $roleLinks = [];
    foreach (dbAll("SELECT id, title, properties FROM blocks WHERE scope_id = ? AND kind = 'role' ORDER BY id", [$scope]) as $row) {
        $id = (int) $row['id'];
        $roles[$id] = ['id' => $id, 'role' => graphProperties($row['properties'])['role'] ?? $row['title']];
    }
    $rows = dbAll("SELECT a.id, a.source_id, a.target_id, a.properties
                     FROM arrows a JOIN blocks b ON b.id = a.target_id AND b.scope_id = a.scope_id
                    WHERE a.scope_id = ? AND a.kind = 'link' AND b.kind = 'role' ORDER BY a.sort, a.id", [$scope]);
    foreach ($rows as $row) {
        if ((graphProperties($row['properties'])['relation'] ?? null) !== 'role') continue;
        $roleLinks[(int) $row['source_id']] ??= ['arrowId' => (int) $row['id'], 'blockId' => (int) $row['target_id']];
    }
    $agents = [];
    foreach (dbAll("SELECT * FROM blocks WHERE scope_id = ? AND kind = 'agent' ORDER BY title, id", [$scope]) as $row) {
        $shape = graphBlockShape($row);
        $properties = $shape['properties'];
        $roleLink = $roleLinks[$shape['id']] ?? null;
        $agents[] = [
            'id' => $shape['id'], 'name' => $shape['title'],
            'role' => $roles[$roleLink['blockId'] ?? 0]['role'] ?? $properties->role ?? 'worker',
            'cli' => $properties->cli ?? '', 'model' => $properties->model ?? '',
            'effort' => $properties->effort ?? '', 'permission' => $properties->permission ?? '',
            'sandbox' => $properties->sandbox ?? '', 'closeAfter' => $properties->closeAfter ?? 'keep',
            'created' => $row['created_at'], 'style' => $shape['appearance'], 'used' => new stdClass(), 'rev' => $shape['rev'],
            '_graph' => ['blockId' => $shape['id'], 'properties' => $properties, 'appearance' => $shape['appearance'],
                'roleLink' => $roleLink],
        ];
    }
    return [$agents, array_values($roles)];
}

function graphVisualScheme(int $scope, int $parent): array
{
    $placed = dbAll("SELECT b.*, a.id AS placement_id, a.source_id AS parent_id, a.sort AS placement_sort,
                    a.properties AS placement_properties, a.appearance AS placement_appearance
                    FROM arrows a JOIN blocks b ON b.scope_id = a.scope_id AND b.id = a.target_id
                    WHERE a.scope_id = ? AND a.kind = 'contains' AND a.source_id = ? ORDER BY a.sort, a.id",
        [$scope, $parent]);
    $edges = dbAll("SELECT a.* FROM arrows a WHERE a.scope_id = ? AND (
                     (a.kind <> 'contains' AND (a.source_id = ? OR a.target_id = ?))
                     OR EXISTS (SELECT 1 FROM arrows p WHERE p.scope_id = a.scope_id
                                 AND p.kind = 'contains' AND p.source_id = ?
                                 AND (p.target_id = a.source_id OR (a.kind <> 'contains' AND p.target_id = a.target_id))))
                    AND NOT (a.kind = 'contains' AND a.source_id = ?) ORDER BY a.sort, a.id",
        [$scope, $parent, $parent, $parent, $parent]);

    $local = [];
    $blocks = [];
    foreach ($placed as $row) {
        if (in_array($row['kind'], GRAPH_VISUAL_HIERARCHY, true)) continue;
        $id = (int) $row['id'];
        // Root service records with a location are deliberately placed cards.
        $geometry = graphProperties($row['placement_properties'])['placement'] ?? [];
        if (in_array($row['kind'], ['asset', 'material', 'agent', 'role'], true) && !isset($geometry['x'], $geometry['y'])) {
            continue;
        }
        $blocks[$id] = $row;
        $local[$id] = ['id' => (int) $row['placement_id'], 'folder' => $parent];
    }
    $outside = [];
    foreach ($edges as $edge) {
        $shape = graphArrowShape($edge);
        foreach ([$shape['sourceId'], $shape['targetId']] as $id) {
            if ($id !== $parent && !isset($blocks[$id])) $outside[$id] = true;
        }
    }
    $outsideBlocks = graphVisualOutsideBlocks($scope, array_keys($outside));
    $memberships = $memberOf = $assets = $assignments = $targets = $links = [];
    foreach ($edges as $edge) {
        $shape = graphArrowShape($edge);
        $from = $shape['sourceId'];
        $to = $shape['targetId'];
        $properties = $shape['properties'];
        $relation = $properties->relation ?? '';
        if ($shape['kind'] === 'contains') {
            if (isset($blocks[$from]) && in_array($blocks[$from]['kind'], ['group', 'area'], true)) {
                $memberships[$from][] = ['blockId' => $to, 'placementId' => $shape['id']];
                $memberOf[$to][] = ['blockId' => $from, 'placementId' => $shape['id']];
            }
            continue;
        }
        if ($shape['kind'] === 'link' && $relation !== '') {
            $end = $blocks[$to] ?? $outsideBlocks[$to] ?? null;
            if ($relation === 'asset' && $end !== null && graphAssetBlockKind($end['kind'])) {
                $assets[$from][] = graphVisualAsset($shape, $end);
            } elseif ($relation === 'agent' && $end !== null && $end['kind'] === 'agent') {
                $assignments[$from] ??= ['arrowId' => $shape['id'], 'blockId' => $to];
            } elseif ($relation === 'gatewayTarget' && $end !== null) {
                $targets[$from] ??= ['arrowId' => $shape['id'], 'blockId' => $to];
            }
            continue;
        }
        if (in_array($shape['kind'], ['flow', 'arrow'], true) && isset($local[$from], $local[$to])) {
            $links[] = graphVisualConnection($shape, $parent, $local, $blocks);
        } elseif ($shape['kind'] === 'link' && (isset($local[$from]) || isset($local[$to]))) {
            $fromRow = $blocks[$from] ?? $outsideBlocks[$from] ?? null;
            $toRow = $blocks[$to] ?? $outsideBlocks[$to] ?? null;
            $places = $local + graphVisualOutsidePlaces([$fromRow, $toRow]);
            if (isset($places[$from], $places[$to])) {
                $links[] = graphVisualConnection($shape, $parent, $places, [$from => $fromRow, $to => $toRow]);
            }
        }
    }

    $out = [];
    foreach ($blocks as $id => $row) {
        $shape = graphVisualNode($row, $parent);
        $shape['_graph']['memberships'] = $memberships[$id] ?? [];
        $shape['_graph']['memberOf'] = $memberOf[$id] ?? [];
        if (isset($memberOf[$id])) $shape['in'] = array_map(static fn(array $item): int => $local[$item['blockId']]['id'], $memberOf[$id]);
        if (isset($assignments[$id])) {
            $shape['agent'] = $assignments[$id]['blockId'];
            $shape['_graph']['assignment'] = $assignments[$id];
        }
        if (isset($targets[$id])) {
            $shape['target'] = $targets[$id]['blockId'];
            $shape['_graph']['gatewayTarget'] = $targets[$id];
        }
        $shape['assets'] = $assets[$id] ?? [];
        foreach ($shape['assets'] as $asset) {
            if ($asset['role'] === 'spec' && $asset['text']) $shape['hasSpec'] = true;
        }
        $out[] = $shape;
    }
    return [array_merge($out, $links), $assets[$parent] ?? []];
}

/** A link may end outside the current scene. Resolve its labels without querying old elements. */
function graphVisualOutsideBlocks(int $scope, array $ids): array
{
    $out = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $rows = dbAll("SELECT b.*, p.id AS placement_id, p.source_id AS parent_id
                        FROM blocks b
                        LEFT JOIN arrows p ON p.id = (
                            SELECT a.id FROM arrows a JOIN blocks c ON c.id = a.source_id AND c.scope_id = a.scope_id
                             WHERE a.scope_id = b.scope_id AND a.target_id = b.id AND a.kind = 'contains'
                               AND c.kind IN ('project','folder','scheme')
                             ORDER BY a.sort, a.id LIMIT 1)
                        WHERE b.scope_id = ? AND b.id IN ($marks)", [$scope, ...$chunk]);
        foreach ($rows as $row) $out[(int) $row['id']] = $row;
    }
    return $out;
}

function graphVisualOutsidePlaces(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (isset($row['placement_id'], $row['parent_id']) && !in_array($row['kind'], GRAPH_VISUAL_HIERARCHY, true)) {
            $out[(int) $row['id']] = ['id' => (int) $row['placement_id'], 'folder' => (int) $row['parent_id']];
        }
    }
    return $out;
}

function graphVisualNode(array $row, int $parent): array
{
    $block = graphBlockShape($row);
    $properties = $block['properties'];
    if (graphAssetBlockKind($block['kind'])) unset($properties->body);
    $props = array_replace((array) ($properties->legacyProps ?? []), (array) $properties);
    unset($props['legacyProps'], $props['legacyNo'], $props['legacyMigrationKey'], $props['content']);
    $geometry = graphProperties($row['placement_properties'])['placement'] ?? [];
    $placementAppearance = graphVisualPlacementAppearance($row);
    $style = array_replace((array) $block['appearance'], array_intersect_key($geometry, ['z' => true]),
        (array) $placementAppearance, array_intersect_key($geometry, array_flip(['x', 'y', 'width', 'height'])));
    $type = in_array($block['kind'], ['block', 'decision', 'gateway', 'group', 'area', 'note', 'table'], true)
        ? $block['kind'] : 'block';
    $spec = false;
    foreach ($properties->content ?? [] as $item) {
        if (($item->kind ?? '') === 'markdown' && trim((string) ($item->value ?? '')) !== '') {
            $spec = true;
            break;
        }
    }
    return [
        'id' => (int) $row['placement_id'], 'no' => graphVisualNumber($block), 'type' => $type,
        'folder' => $parent, 'title' => $block['title'], 'description' => $block['description'],
        'props' => (object) $props, 'style' => (object) $style, 'hasSpec' => $spec, 'rev' => $block['rev'],
        '_graph' => ['blockId' => $block['id'], 'placementId' => (int) $row['placement_id'], 'parentId' => $parent,
            'kind' => $block['kind'], 'properties' => $properties, 'appearance' => $block['appearance'],
            'placementAppearance' => $placementAppearance, 'placement' => (object) $geometry],
    ];
}

function graphVisualNumber(array $block): int
{
    $properties = $block['properties'];
    return (int) ($properties->no ?? $properties->legacyNo ?? $block['id']);
}

function graphVisualConnection(array $edge, int $parent, array $placement, array $blocks): array
{
    $p = $edge['properties'];
    $from = $edge['sourceId'];
    $to = $edge['targetId'];
    if ($edge['direction'] === 'reverse') [$from, $to] = [$to, $from];
    $type = $edge['kind'] === 'link' ? 'link' : 'arrow';
    $out = [
        'id' => $edge['id'], 'no' => graphVisualNumber($edge), 'type' => $type, 'folder' => $parent,
        'title' => (string) ($p->title ?? $p->label ?? ''), 'description' => (string) ($p->description ?? ''),
        'from' => $placement[$from]['id'], 'to' => $placement[$to]['id'],
        'fromFolder' => $placement[$from]['folder'], 'toFolder' => $placement[$to]['folder'],
        'branch' => (string) ($p->branchKey ?? $p->branch ?? 'flow'), 'back' => propOn($p->back ?? false),
        'style' => $edge['appearance'], 'props' => (object) array_replace((array) ($p->legacyProps ?? []), (array) $p),
        'rev' => $edge['rev'],
        '_graph' => ['arrowId' => $edge['id'], 'kind' => $edge['kind'], 'properties' => $p, 'appearance' => $edge['appearance'],
            'direction' => $edge['direction'], 'sourceId' => $edge['sourceId'], 'targetId' => $edge['targetId']],
    ];
    if ($type === 'link') {
        foreach (['from' => $from, 'to' => $to] as $side => $id) {
            $block = graphBlockShape($blocks[$id]);
            $out[$side . 'No'] = graphVisualNumber($block);
            $out[$side . 'Type'] = $block['kind'];
            $out[$side . 'Title'] = $block['title'];
        }
    }
    return $out;
}

/** Public material metadata and link identity, with no storage keys or server paths. */
function graphVisualAsset(array $edge, array $block): array
{
    $properties = graphProperties($block['properties']);
    $link = $edge['properties'];
    $id = (int) $block['id'];
    $mime = (string) ($properties['mime'] ?? '');
    $kind = (string) ($properties['kind'] ?? 'file');
    if (str_starts_with($mime, 'image/')) $kind = 'image';
    if (str_starts_with($mime, 'video/')) $kind = 'video';
    $uri = (string) ($properties['uri'] ?? '');
    $external = preg_match('#\Ahttps?://[^\s]+\z#iD', $uri) && filter_var($uri, FILTER_VALIDATE_URL) !== false;
    $materialProperties = graphPublicProperties($block);
    unset($materialProperties->body);
    return [
        'asset' => $id, 'link' => $edge['id'], 'role' => (string) ($link->role ?? 'attachment'),
        'fromRun' => isset($link->madeByRun) ? (int) $link->madeByRun : null,
        'output' => (string) ($link->output ?? ''), 'kind' => $kind, 'title' => $block['title'],
        'mime' => $mime, 'bytes' => isset($properties['bytes']) ? (int) $properties['bytes'] : null,
        'text' => is_string($properties['body'] ?? null) && trim($properties['body']) !== '',
        'file' => ($properties['fileStorage'] ?? '') === 'graph' || !empty($properties['fileKey']),
        'uri' => $external ? $uri : '',
        '_graph' => ['blockId' => $id, 'materialId' => $id, 'arrowId' => $edge['id'], 'properties' => $link,
            'appearance' => $edge['appearance'], 'materialProperties' => $materialProperties],
    ];
}
