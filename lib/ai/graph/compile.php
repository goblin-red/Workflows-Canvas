<?php
declare(strict_types=1);

const AI_GRAPH_COMMANDS_LIMIT = 30;

const AI_GRAPH_COMMAND_FIELDS = [
    'block.add' => ['ref', 'kind', 'title', 'description', 'placement'],
    'block.edit' => ['block', 'title', 'description'],
    'arrow.add' => ['source', 'target', 'kind', 'direction', 'label', 'branchKey'],
    'arrow.remove' => ['arrow'],
];

const AI_GRAPH_BLOCK_KINDS = ['block', 'decision', 'gateway', 'group', 'area', 'note', 'table', 'folder'];

function aiGraphCompile(array $scene, mixed $commands): array
{
    if (!is_array($commands) || !array_is_list($commands) || count($commands) > AI_GRAPH_COMMANDS_LIMIT) {
        throw new ApiError(t('server.graph_assistant.commands'));
    }
    $parent = $scene['parent']['id'];
    $children = array_fill_keys(array_column($scene['blocks'], 'id'), true);
    $arrows = array_column($scene['arrows'], null, 'id');
    $refs = [];
    $ops = [];
    foreach ($commands as $index => $command) {
        try {
            $ops = array_merge($ops, aiGraphCompileCommand($command, $parent, $children, $arrows, $refs));
        } catch (ApiError $error) {
            throw new ApiError(t('server.graph_assistant.command_error', [
                'number' => $index + 1, 'error' => $error->getMessage(),
            ]), $error->errCode);
        }
    }
    graphValidateSecrets($ops);
    return $ops;
}

function aiGraphCompileCommand(mixed $command, int $parent, array $children, array &$arrows, array &$refs): array
{
    $kind = is_array($command) ? ($command['command'] ?? null) : null;
    if (!is_string($kind) || !isset(AI_GRAPH_COMMAND_FIELDS[$kind])) {
        throw new ApiError(t('server.graph_assistant.command_unknown'));
    }
    if (array_diff(array_keys($command), ['command', ...AI_GRAPH_COMMAND_FIELDS[$kind]])) {
        throw new ApiError(t('server.graph_assistant.command_fields'));
    }

    if ($kind === 'block.add') {
        $ref = $command['ref'] ?? null;
        if (!is_string($ref) || !preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $ref) || $ref === 'root' || isset($refs[$ref])) {
            throw new ApiError(t('server.graph_assistant.block_ref'));
        }
        $blockKind = $command['kind'] ?? null;
        if (!in_array($blockKind, AI_GRAPH_BLOCK_KINDS, true)) throw new ApiError(t('server.graph_assistant.block_kind'));
        $refs[$ref] = true;
        $block = ['op' => 'block.create', 'ref' => $ref, 'kind' => $blockKind, 'title' => aiGraphCommandText($command, 'title', 512)]
            + (array_key_exists('description', $command) ? ['description' => aiGraphCommandText($command, 'description', 4000)] : []);
        $place = ['op' => 'arrow.create', 'sourceId' => $parent, 'targetId' => $ref, 'kind' => 'contains'];
        if (array_key_exists('placement', $command)) {
            $place['properties'] = ['placement' => aiGraphPlacement($command['placement'])];
        }
        return [$block, $place];
    }

    if ($kind === 'block.edit') {
        $id = aiGraphBlockRef($command['block'] ?? null, $children, $refs);
        $op = ['op' => 'block.update', 'id' => $id];
        foreach (['title' => 512, 'description' => 4000] as $key => $limit) {
            if (array_key_exists($key, $command)) $op[$key] = aiGraphCommandText($command, $key, $limit);
        }
        if (count($op) === 2) throw new ApiError(t('server.graph_assistant.edit_fields'));
        return [$op];
    }

    if ($kind === 'arrow.remove') {
        $id = $command['arrow'] ?? null;
        if (!is_int($id) || !isset($arrows[$id]) || $arrows[$id]['kind'] === 'contains') {
            throw new ApiError(t('server.graph_assistant.arrow_scope'), 'scope');
        }
        unset($arrows[$id]);
        return [['op' => 'arrow.delete', 'id' => $id]];
    }

    $source = aiGraphBlockRef($command['source'] ?? null, [$parent => true] + $children, $refs);
    $target = aiGraphBlockRef($command['target'] ?? null, [$parent => true] + $children, $refs);
    $arrowKind = $command['kind'] ?? 'link';
    $direction = $command['direction'] ?? 'forward';
    if (!in_array($arrowKind, ['flow', 'link'], true) || !is_string($direction)) {
        throw new ApiError(t('server.graph_assistant.link_kind'));
    }
    $op = ['op' => 'arrow.create', 'sourceId' => $source, 'targetId' => $target,
        'kind' => $arrowKind, 'direction' => $direction];
    if (array_key_exists('label', $command)) {
        $field = $arrowKind === 'flow' ? 'branchLabel' : 'title';
        $op['properties'][$field] = aiGraphCommandText($command, 'label', 512);
    }
    if (array_key_exists('branchKey', $command)) {
        $key = $command['branchKey'];
        if ($arrowKind !== 'flow' || !is_string($key) || !preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $key)) {
            throw new ApiError(t('server.graph_assistant.branch_key'));
        }
        $op['properties']['branchKey'] = $key;
    }
    return [$op];
}

function aiGraphBlockRef(mixed $id, array $allowed, array $refs): int|string
{
    if (is_int($id) && isset($allowed[$id])) return $id;
    if (is_string($id) && isset($refs[$id])) return $id;
    throw new ApiError(t('server.graph_assistant.block_scope'), 'scope');
}

function aiGraphCommandText(array $command, string $key, int $limit): string
{
    $text = $command[$key] ?? null;
    if (!is_string($text) || mb_strlen($text) > $limit || aiGraphUnsafeText($text)) {
        throw new ApiError(t('server.graph_assistant.field_value', ['field' => $key]));
    }
    return $text;
}

function aiGraphPlacement(mixed $data): array
{
    if (!is_array($data) || $data === [] || array_diff(array_keys($data), ['x', 'y', 'width', 'height'])) {
        throw new ApiError(t('server.graph_assistant.placement_fields'));
    }
    foreach ($data as $key => $value) {
        $size = in_array($key, ['width', 'height'], true);
        if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value)
            || abs($value) > 100000 || ($size && $value <= 0)) {
            throw new ApiError(t('server.graph_assistant.placement_geometry'));
        }
    }
    return $data;
}
