<?php
declare(strict_types=1);

/** API размещений использует ту же строку contains, что и совместимый API стрелок. */
function graphPlacementWrite(array $op, array &$ctx): array
{
    $create = $op['op'] === 'placement.create';
    $delete = $op['op'] === 'placement.delete';
    $fields = ['op', 'scopeId', 'project', 'opId', 'opScope', 'ifRev', 'ifFolderRev', 'via', 'label'];
    if ($create) $fields[] = 'ref';
    else $fields[] = 'id';
    if (!$delete) array_push($fields, 'parentId', 'blockId', 'sort', 'placement', 'appearance');
    if (array_diff(array_keys($op), $fields)) throw new ApiError(t('server.graph.placement_field'));

    $row = $create ? null : graphRow('arrows', $ctx['projectId'], graphId($op['id'] ?? null, $ctx['graphArrowRefs'] ?? []));
    if ($row !== null && $row['kind'] !== 'contains') throw new ApiError(t('server.graph.placement_not_found'), 'not_found');

    $write = ['op' => $create ? 'arrow.create' : ($delete ? 'arrow.delete' : 'arrow.update')];
    if ($row !== null) $write['id'] = (int) $row['id'];
    if ($create && array_key_exists('ref', $op)) $write['ref'] = $op['ref'];
    foreach (['parentId' => 'sourceId', 'blockId' => 'targetId', 'sort' => 'sort'] as $field => $name) {
        if (array_key_exists($field, $op)) $write[$name] = $op[$field];
    }
    if ($create) {
        $write['kind'] = 'contains';
        $write['direction'] = 'forward';
    }
    if (array_key_exists('placement', $op) || array_key_exists('appearance', $op)) {
        // Остальные свойства contains могли прийти из переноса или совместимого API.
        $properties = (array) json_decode($row['properties'] ?? '{}');
        $patch = array_key_exists('placement', $op) ? $op['placement'] : [];
        if (!is_array($patch) || ($patch !== [] && array_is_list($patch))) {
            throw new ApiError(t('server.graph.placement_object'));
        }
        if (array_key_exists('appearance', $op)) {
            if (array_key_exists('appearance', $patch)) throw new ApiError(t('server.graph.appearance_once'));
            $patch['appearance'] = $op['appearance'];
        }
        $geometry = (array) ($properties['placement'] ?? []);
        $properties['placement'] = graphPlacementPatch($geometry, $patch);
        $write['properties'] = $properties;
    }
    $out = graphArrowWrite($write, $ctx);
    $out['prev'] = $row === null ? null : graphPlacementShape(graphArrowShape($row));
    return $out;
}

/** Координаты правятся по полям. Объект appearance заменяет оформление этого размещения. */
function graphPlacementPatch(array $old, array $patch): array
{
    foreach ($patch as $name => $value) {
        if ($name === 'appearance') {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new ApiError(t('server.graph.appearance_object'));
            }
            $old[$name] = (object) $value;
            continue;
        }
        if (!in_array($name, ['x', 'y', 'width', 'height'], true) || (!is_int($value) && !is_float($value))
            || !is_finite($value) || (in_array($name, ['width', 'height'], true) && $value <= 0)) {
            throw new ApiError(t('server.graph.placement_geometry'));
        }
        $old[$name] = $value;
    }
    return $old;
}

/** Форма отдельного размещения из строки публичного графа. */
function graphPlacementShape(array $arrow): array
{
    $placement = (array) ($arrow['properties']->placement ?? []);
    $appearance = (object) ($placement['appearance'] ?? $arrow['appearance']);
    unset($placement['appearance']);
    return [
        'id' => $arrow['id'],
        'parentId' => $arrow['sourceId'],
        'blockId' => $arrow['targetId'],
        'sort' => $arrow['sort'],
        'placement' => (object) $placement,
        'appearance' => $appearance,
    ];
}
