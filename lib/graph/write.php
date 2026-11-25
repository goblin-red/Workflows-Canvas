<?php
declare(strict_types=1);

function graphBlockWrite(array $op, array &$ctx, array $materialStorage = []): array
{
    $scopeId = $ctx['projectId'];
    $rev = $ctx['rev'];
    $create = $op['op'] === 'block.create';
    $refs = ['root' => $ctx['graphRoot']] + ($ctx['graphBlockRefs'] ?? []);
    $row = $create ? null : graphRow('blocks', $scopeId, graphId($op['id'] ?? null, $refs));
    $id = (int) ($row['id'] ?? 0);
    $isRoot = $id === $ctx['graphRoot'];
    if ($isRoot) requireOwner($ctx['project']);

    if ($op['op'] === 'block.content') return graphSaveBlockContent($op, $row, $ctx, $isRoot);

    if ($op['op'] === 'block.delete') {
        if ($isRoot) throw new ApiError(t('server.graph.remove_root'), 'conflict');
        if (dbValue('SELECT id FROM arrows WHERE scope_id = ? AND (source_id = ? OR target_id = ?) LIMIT 1', [$scopeId, $id, $id])) {
            throw new ApiError(t('server.graph.remove_block_edges'), 'conflict');
        }
        graphGuard($scopeId, [$id]);
        dbRun('DELETE FROM blocks WHERE scope_id = ? AND id = ?', [$scopeId, $id]);
        return ['id' => $id, 'rev' => $rev, 'prev' => graphPrevious($row)];
    }

    $kind = graphText($op, 'kind', $row['kind'] ?? '', 32);
    if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind) || ($kind === 'project') !== $isRoot) {
        throw new ApiError(t('server.graph.block_kind'));
    }
    $values = [
        'kind' => $kind,
        'title' => graphText($op, 'title', $row['title'] ?? '', 512),
        'description' => graphText($op, 'description', $row['description'] ?? ''),
        'properties' => graphBlockProperties($op, $row, $kind, $materialStorage),
        'appearance' => graphJson($op, 'appearance', $row['appearance'] ?? '{}'),
    ];
    if ($create) {
        dbRun('INSERT INTO blocks (kind, title, description, properties, appearance, rev, scope_id) VALUES (?,?,?,?,?,?,?)',
            [...array_values($values), $rev, $scopeId]);
        $id = dbId();
        graphRememberRef($op, $ctx['graphBlockRefs'], $id);
    } else {
        if ($kind !== $row['kind'] || $values['title'] !== $row['title'] || $values['description'] !== $row['description']
            || (array_key_exists('properties', $op)
                && graphContentJson(json_decode($values['properties'])) !== graphContentJson(json_decode($row['properties'])))) {
            graphGuardBlockChange($scopeId, $id, $isRoot);
        }
        if ($kind !== $row['kind'] || array_key_exists('properties', $op)) {
            graphGuardCanvasType($row, $kind, $values['properties']);
        }
        dbRun('UPDATE blocks SET kind = ?, title = ?, description = ?, properties = ?, appearance = ?, rev = ?
                WHERE scope_id = ? AND id = ?', [...array_values($values), $rev, $scopeId, $id]);
    }
    return ['id' => $id, 'rev' => $rev, 'prev' => $row ? graphPrevious($row) : null];
}

function graphGuardBlockChange(int $scope, int $id, bool $isRoot): void
{
    if ($isRoot) activeGuardProject($scope, t('server.graph.guard_project'));
    else graphGuard($scope, [$id]);
}

/** Keep active canvas relationships valid when a card changes its type. */
function graphGuardCanvasType(array $row, string $kind, string $properties): void
{
    $previous = $row['kind'];
    $canvasTypes = ['block', 'decision', 'gateway', 'group', 'area', 'note', 'table'];
    if (!in_array($previous, $canvasTypes, true) || !in_array($kind, $canvasTypes, true)) return;

    $scope = (int) $row['scope_id'];
    $id = (int) $row['id'];
    if ($kind === 'block' && graphCanvasStarter($properties)
        && ($previous !== 'block' || !graphCanvasStarter($row['properties']))) {
        graphGuardCanvasStarter($scope, $id);
    }

    if ($kind === $previous) return;
    if (canLink($previous) && !canLink($kind) && dbValue(
        "SELECT id FROM arrows WHERE scope_id = ? AND kind = 'flow' AND (source_id = ? OR target_id = ?) LIMIT 1",
        [$scope, $id, $id]
    )) {
        throw new ApiError(t('server.graph.retype_flow'), 'conflict');
    }
    if (isContainer($previous) && !isContainer($kind) && dbValue(
        "SELECT id FROM arrows WHERE scope_id = ? AND kind = 'contains' AND source_id = ? LIMIT 1",
        [$scope, $id]
    )) {
        throw new ApiError(t('server.graph.retype_members'), 'conflict');
    }
    if ($previous !== 'decision') return;

    foreach (dbAll(
        "SELECT properties FROM arrows WHERE scope_id = ? AND kind = 'flow'
          AND ((direction = 'forward' AND source_id = ?) OR (direction = 'reverse' AND target_id = ?))",
        [$scope, $id, $id]
    ) as $edge) {
        $p = graphProperties($edge['properties']);
        $branch = $p['branchKey'] ?? $p['branch'] ?? '';
        $condition = $p['branchCondition'] ?? $p['condition'] ?? $p['cond'] ?? '';
        if ((is_string($branch) && !in_array($branch, ['', 'flow'], true))
            || $condition !== '' || propOn($p['branchDefault'] ?? $p['default'] ?? false)) {
            throw new ApiError(t('server.graph.retype_branch'), 'conflict');
        }
    }
}

function graphCanvasStarter(string $json): bool
{
    $properties = graphProperties($json);
    $effective = $properties + graphProperties($properties['legacyProps'] ?? null);
    return propOn($effective['start'] ?? false);
}

/** Called while the project row is locked. Memberships in frames are not scene placements. */
function graphGuardCanvasStarter(int $scope, int $id): void
{
    $siblings = dbAll(
        "SELECT DISTINCT b.id, b.properties FROM arrows p
          JOIN blocks parent ON parent.scope_id = p.scope_id AND parent.id = p.source_id
          JOIN arrows peer ON peer.scope_id = p.scope_id AND peer.source_id = p.source_id AND peer.kind = 'contains'
          JOIN blocks b ON b.scope_id = p.scope_id AND b.id = peer.target_id AND b.kind = 'block'
         WHERE p.scope_id = ? AND p.target_id = ? AND p.kind = 'contains'
           AND parent.kind IN ('project', 'folder', 'scheme') AND b.id <> ? ORDER BY b.id",
        [$scope, $id, $id]
    );
    foreach ($siblings as $sibling) {
        if (!graphCanvasStarter($sibling['properties'])) continue;
        $p = graphProperties($sibling['properties']);
        $no = (int) ($p['no'] ?? $p['legacyNo'] ?? $sibling['id']);
        throw new ApiError(t('server.props.starter_exists', ['no' => $no]), 'conflict');
    }
}

/** Keep server-owned material fields when clients replace the public property object. */
function graphBlockProperties(array $op, ?array $row, string $kind, array $storage): string
{
    $wasMaterial = $row !== null && graphAssetBlockKind($row['kind']);
    $isMaterial = graphAssetBlockKind($kind);
    $old = $row['properties'] ?? '{}';
    $writesProperties = array_key_exists('properties', $op);
    if ($wasMaterial && $isMaterial && !$writesProperties) return $old;

    $private = $wasMaterial ? array_intersect_key((array) json_decode($old), GRAPH_ASSET_PRIVATE_PROPERTIES) : [];
    if ($wasMaterial && !$isMaterial) {
        $path = graphAssetStoredPath($row);
        if ($private || ($path !== null && is_file($path)) || graphAssetLegacy((int) $row['scope_id'], (int) $row['id'])) {
            throw new ApiError(t('server.graph.material_kind'), 'conflict');
        }
    }

    $json = graphJson($op, 'properties', $old);
    if ($writesProperties) $json = graphBlockPropertiesContent($json, $old);
    if (!$isMaterial) return $json;

    $properties = (array) json_decode($json);
    if ($writesProperties && array_intersect_key($properties, GRAPH_ASSET_PRIVATE_PROPERTIES)) {
        throw new ApiError(t('server.graph.material_storage'));
    }
    // Previous non-material properties are public. They cannot become storage metadata on conversion.
    if (!$wasMaterial) $private = array_intersect_key($storage, GRAPH_ASSET_PRIVATE_PROPERTIES);
    $public = array_diff_key($properties, GRAPH_ASSET_PRIVATE_PROPERTIES);
    return json_encode((object) ($public + $private), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function graphArrowWrite(array $op, array &$ctx): array
{
    $scopeId = $ctx['projectId'];
    $rev = $ctx['rev'];
    $create = $op['op'] === 'arrow.create';
    $row = $create ? null : graphRow('arrows', $scopeId, graphId($op['id'] ?? null, $ctx['graphArrowRefs'] ?? []));
    $id = (int) ($row['id'] ?? 0);
    if ($op['op'] === 'arrow.delete') {
        graphGuard($scopeId, [(int) $row['source_id'], (int) $row['target_id']], $id);
        dbRun('DELETE FROM arrows WHERE scope_id = ? AND id = ?', [$scopeId, $id]);
        return ['id' => $id, 'rev' => $rev, 'prev' => graphPrevious($row)];
    }

    $refs = ['root' => $ctx['graphRoot']] + ($ctx['graphBlockRefs'] ?? []);
    $source = graphId(array_key_exists('sourceId', $op) ? $op['sourceId'] : ($row['source_id'] ?? null), $refs);
    $target = graphId(array_key_exists('targetId', $op) ? $op['targetId'] : ($row['target_id'] ?? null), $refs);
    graphRow('blocks', $scopeId, $source);
    graphRow('blocks', $scopeId, $target);

    $kind = graphText($op, 'kind', $row['kind'] ?? 'link');
    $direction = graphText($op, 'direction', $row['direction'] ?? 'forward');
    $directions = match ($kind) {
        'link' => ['forward', 'reverse', 'both', 'none'],
        'flow' => ['forward', 'reverse'],
        'contains' => ['forward'],
        default => [],
    };
    if (!in_array($direction, $directions, true)) throw new ApiError(t('server.graph.arrow_type_direction'));

    $structural = $create || $kind !== $row['kind'] || $source !== (int) $row['source_id'] || $target !== (int) $row['target_id'];
    if ($structural && $kind === 'contains') graphValidateContains($scopeId, $source, $target, $ctx['graphRoot'], $id);
    $sort = $row['sort'] ?? 0;
    if (array_key_exists('sort', $op)) {
        $sort = is_int($op['sort']) || is_string($op['sort']) ? filter_var(
            $op['sort'], FILTER_VALIDATE_INT, ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]
        ) : false;
        if ($sort === false) throw new ApiError(t('server.graph.arrow_order'));
    }
    $props = graphJson($op, 'properties', $row['properties'] ?? '{}');
    $appearance = graphJson($op, 'appearance', $row['appearance'] ?? '{}');
    if ($structural || $direction !== $row['direction'] || ($kind === 'flow' && (int) $sort !== (int) $row['sort'])
        || graphArrowPropsChanged($kind, $row['properties'], $props)) {
        $ends = $create ? [$source, $target] : [$source, $target, (int) $row['source_id'], (int) $row['target_id']];
        graphGuard($scopeId, $ends, $id);
    }

    $args = [$source, $target, $kind, $direction, $sort, $props, $appearance, $rev, $scopeId];
    if ($create) {
        dbRun('INSERT INTO arrows (source_id, target_id, kind, direction, sort, properties, appearance, rev, scope_id)
               VALUES (?,?,?,?,?,?,?,?,?)', $args);
        $id = dbId();
        graphRememberRef($op, $ctx['graphArrowRefs'], $id);
    } else {
        dbRun('UPDATE arrows SET source_id = ?, target_id = ?, kind = ?, direction = ?, sort = ?, properties = ?, appearance = ?, rev = ?
                WHERE scope_id = ? AND id = ?', [...$args, $id]);
    }
    return ['id' => $id, 'rev' => $rev, 'prev' => $row ? graphPrevious($row) : null];
}

function graphValidateContains(int $scopeId, int $source, int $target, int $root, int $except): void
{
    if ($target === $root) throw new ApiError(t('server.graph.root_parent'), 'conflict');
    if (dbValue(
        "SELECT id FROM arrows WHERE scope_id = ? AND source_id = ? AND target_id = ? AND kind = 'contains' AND id <> ? LIMIT 1",
        [$scopeId, $source, $target, $except]
    )) throw new ApiError(t('server.graph.duplicate_placement'), 'conflict');

    $seen = [];
    $stack = [$target];
    while ($stack) {
        $node = (int) array_pop($stack);
        if ($node === $source) throw new ApiError(t('server.graph.cycle'), 'conflict');
        if (isset($seen[$node])) continue;
        $seen[$node] = true;
        foreach (dbAll(
            "SELECT target_id FROM arrows WHERE scope_id = ? AND source_id = ? AND kind = 'contains' AND id <> ?",
            [$scopeId, $node, $except]
        ) as $child) $stack[] = $child['target_id'];
    }
}

function graphArrowPropsChanged(string $kind, string $before, string $after): bool
{
    $old = json_decode($before, true);
    $new = json_decode($after, true);
    if ($kind === 'contains') unset($old['placement'], $new['placement']);
    return $old !== $new;
}

function graphRememberRef(array $op, ?array &$refs, int $id): void
{
    if (!array_key_exists('ref', $op)) return;
    $ref = $op['ref'];
    if (!is_string($ref) || $ref === '' || strlen($ref) > 128 || is_numeric($ref) || $ref === 'root' || isset($refs[$ref])) {
        throw new ApiError(t('server.graph.temporary_reference'));
    }
    $refs[$ref] = $id;
}

function graphText(array $op, string $key, string $fallback, ?int $max = null): string
{
    if (!array_key_exists($key, $op)) return $fallback;
    $text = $op[$key];
    if (!is_string($text) || ($max !== null && mb_strlen($text) > $max)) throw new ApiError(t('server.graph.field_invalid', ['field' => $key]));
    return $text;
}

function graphJson(array $op, string $key, string $fallback): string
{
    if (!array_key_exists($key, $op)) return $fallback;
    $data = $op[$key];
    if (!is_array($data) || ($data !== [] && array_is_list($data))) throw new ApiError(t('server.graph.field_object', ['field' => $key]));
    if ($key === 'properties' && array_key_exists('placement', $data)) {
        $placement = $data['placement'];
        if (!is_array($placement) || ($placement !== [] && array_is_list($placement))) {
            throw new ApiError(t('server.graph.placement_object'));
        }
        $data['placement'] = (object) $placement;
    }
    return json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function graphPrevious(array $row): array
{
    $data = array_key_exists('source_id', $row) ? graphArrowShape($row) : graphBlockShape($row);
    $data['properties'] = graphPublicProperties($row, true);
    if (isset($data['properties']['content']) && !array_key_exists('source_id', $row)) {
        $data['properties']['content'] = graphContentDecodeProperties($row['properties'])->content;
    }
    $data['appearance'] = json_decode($row['appearance'], true);
    return $data;
}
