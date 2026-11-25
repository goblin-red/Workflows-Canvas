<?php
declare(strict_types=1);

/**
 * POST graph.template.apply: template (ключ или ID), parent (ID или ref, без него корень), name (новое название).
 * opId и ifRev передаются как в остальных графовых правках.
 * Обычный ответ graphRunBatch: {ok, rev, results:[{id, parentId, placementId, rev}], repeat?}.
 * id — блок схемы, placementId — связь для построения пути от выбранного родителя.
 */
function graphTemplateApply(array $op, array &$ctx): array
{
    $key = $op['template'] ?? '';
    if (!is_string($key) && !is_int($key)) throw new ApiError(t('server.template.none', ['key' => '']), 'not_found');
    $row = templateRow((string) $key, lang());
    $body = json_decode((string) $row['body'], true);
    $items = $body['elements'] ?? null;
    if (!$items) throw new ApiError(t('server.template.empty'), 'not_found');
    if (!is_array($items) || !array_is_list($items)) throw new ApiError(t('server.graph.template.body'));

    $parent = graphId($op['parent'] ?? 'root', ['root' => $ctx['graphRoot']] + ($ctx['graphBlockRefs'] ?? []));
    graphRow('blocks', $ctx['projectId'], $parent);
    $folder = graphTemplateMap($body, 'folder');
    $style = graphTemplateMap($folder, 'style');
    $description = graphText($folder, 'description', (string) $row['about']);
    $settings = [
        'roleScheme' => folderRoleScheme(graphText($folder, 'roleScheme', '')),
        'runEnv' => folderRunEnv(graphText($folder, 'runEnv', '')),
        'shareScheme' => !empty($folder['shareScheme']),
    ];
    $create = [
        'op' => 'block.create', 'kind' => 'scheme', 'title' => graphText($op, 'name', $row['title'], 512),
        'description' => $description, 'properties' => $settings, 'appearance' => $style,
    ];
    if (array_key_exists('ref', $op)) $create['ref'] = $op['ref'];
    $scheme = graphTemplateWrite($create, $ctx)['id'];
    $rootPlacement = graphTemplatePlace($parent, $scheme, 0, [], $ctx);

    // Ссылки тела разрешаются только внутри текущего экземпляра заготовки.
    $byRef = $blocks = [];
    foreach ($items as $position => $item) {
        if (!is_array($item)) throw new ApiError(t('server.graph.template.item'));
        $type = requireKind(graphText($item, 'type', 'block'));
        $ref = graphText($item, 'ref', '');
        if ($ref !== '') {
            if (array_key_exists($ref, $byRef)) throw new ApiError(t('server.graph.template.duplicate_ref', ['ref' => $ref]));
            $byRef[$ref] = $position;
        }
        if ($type === 'arrow' || $type === 'link') continue;
        $appearance = graphTemplateMap($item, 'style');
        $geometry = array_intersect_key($appearance, array_flip(['x', 'y', 'width', 'height', 'z']));
        if (canLink($type)) $geometry['z'] ??= 1;
        $props = graphTemplateMap($item, 'props');
        $title = graphText($item, 'title', '', 512);
        $block = graphTemplateWrite([
            'op' => 'block.create', 'kind' => $type, 'title' => $title,
            'description' => graphText($item, 'description', ''),
            'appearance' => array_diff_key($appearance, $geometry),
            'properties' => $props ? ['legacyProps' => $props] : [],
        ], $ctx)['id'];
        $blocks[$position] = ['id' => $block, 'kind' => $type, 'geometry' => $geometry];
        // Как на старом холсте: элемент остаётся виден в схеме и в своих группах.
        graphTemplatePlace($scheme, $block, $position, $geometry, $ctx);

        $spec = graphText($item, 'spec', '');
        if ($spec !== '') {
            graphTemplateWrite([
                'op' => 'graph.asset.add', 'block' => $block, 'role' => 'spec', 'text' => $spec,
                'title' => mb_substr(ta('agents.template.spec_title', ['title' => $title !== '' ? $title : $ref]), 0, 512),
            ], $ctx);
        }
    }

    foreach ($items as $position => $item) {
        $memberships = $item['in'] ?? [];
        if (!is_array($memberships) || !array_is_list($memberships)) throw new ApiError(t('server.graph.template.groups'));
        if (!isset($blocks[$position])) {
            if ($memberships) throw new ApiError(t('server.members.arrow_link'));
            if (graphText($item, 'spec', '') !== '') throw new ApiError(t('server.graph.template.spec_block'));
            graphTemplateArrow($item, $position, $byRef, $blocks, $row['family'], $ctx);
            continue;
        }

        $child = $blocks[$position];
        $seen = [];
        foreach ($memberships as $ref) {
            $group = graphTemplateBlock($ref, $byRef, $blocks);
            if (!canContain($group['kind'], $child['kind'])) throw new ApiError(t('server.graph.template.group_ref'));
            if (isset($seen[$group['id']])) continue;
            $seen[$group['id']] = true;
            graphTemplatePlace($group['id'], $child['id'], $position, $child['geometry'], $ctx);
        }
    }

    return ['id' => $scheme, 'parentId' => $parent, 'placementId' => $rootPlacement, 'rev' => $ctx['rev']];
}

function graphTemplateMap(array $row, string $key): array
{
    $value = $row[$key] ?? [];
    if (!is_array($value) || ($value && array_is_list($value))) throw new ApiError(t('server.graph.template.field', ['key' => $key]));
    return $value;
}

function graphTemplateBlock(mixed $ref, array $byRef, array $blocks): array
{
    $position = is_string($ref) && $ref !== '' ? ($byRef[$ref] ?? null) : null;
    if ($position === null || !isset($blocks[$position])) throw new ApiError(t('server.graph.template.block_ref'), 'not_found');
    return $blocks[$position];
}

function graphTemplatePlace(int $parent, int $child, int $sort, array $geometry, array &$ctx): int
{
    return graphTemplateWrite([
        'op' => 'arrow.create', 'kind' => 'contains', 'sourceId' => $parent, 'targetId' => $child, 'sort' => $sort,
        'properties' => $geometry ? ['placement' => $geometry] : [],
    ], $ctx)['id'];
}

function graphTemplateArrow(array $item, int $sort, array $byRef, array $blocks, string $family, array &$ctx): void
{
    $from = graphTemplateBlock($item['from'] ?? null, $byRef, $blocks);
    $to = graphTemplateBlock($item['to'] ?? null, $byRef, $blocks);
    $appearance = graphTemplateMap($item, 'style');
    $props = graphTemplateMap($item, 'props');
    $title = graphText($item, 'title', '', 512);
    $properties = ['title' => $title, 'description' => graphText($item, 'description', '')];
    if ($props) $properties['legacyProps'] = $props;
    $kind = $item['type'] === 'arrow' ? 'flow' : 'link';
    $branch = graphText($item, 'branch', 'flow') ?: 'flow';
    $back = !empty($item['back']);
    $direction = graphText($item, 'direction', 'forward');

    // В демонстрационных диаграммах каталога bothWays задавал оба наконечника.
    // В исполняемых переходах остаётся одно направление.
    if (!array_key_exists('direction', $item) && !empty($appearance['bothWays'])
        && ($kind === 'link' || ($family === 'scheme' && $branch === 'flow' && !$back))) {
        $kind = 'link';
        $direction = 'both';
    }
    if ($kind === 'flow') {
        if (!canLink($from['kind']) || !canLink($to['kind'])) throw new ApiError(t('server.graph.template.edge_steps'));
        if (!in_array($branch, ['flow', 'yes', 'no'], true)) throw new ApiError(t('server.element.branch_values'));
        $source = $direction === 'reverse' ? $to : $from;
        if ($branch !== 'flow' && $source['kind'] !== 'decision') throw new ApiError(t('server.element.branch_decision_only'));
        $label = $branch !== 'flow' ? mb_strtoupper(t('agents.go.branch.' . $branch)) : ($back ? t('editor.canvas.one_more_round') : '');
        $properties += [
            'branchKey' => $branch,
            'branchLabel' => implode(' · ', array_filter([$label, trim($title)], 'strlen')),
            'back' => $back,
        ];
    } else {
        $properties['label'] = $title;
    }
    graphTemplateWrite([
        'op' => 'arrow.create', 'kind' => $kind, 'direction' => $direction,
        'sourceId' => $from['id'], 'targetId' => $to['id'], 'sort' => $sort,
        'appearance' => $appearance, 'properties' => $properties,
    ], $ctx);
}

/** Вложенные действия используют те же проверки графа, что и отдельные команды API. */
function graphTemplateWrite(array $op, array &$ctx): array
{
    graphValidateSecrets($op);
    return match ($op['op']) {
        'block.create' => graphBlockWrite($op, $ctx),
        'arrow.create' => graphArrowWrite($op, $ctx),
        'graph.asset.add' => graphAssetAdd($op, $ctx),
    };
}
