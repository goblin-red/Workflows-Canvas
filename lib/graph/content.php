<?php
declare(strict_types=1);

/** POST block.content. Shared dispatch checks the block's scope and root ownership. */
function graphBlockContent(array $op, array &$ctx): array
{
    return graphBlockWrite($op, $ctx);
}

function graphSaveBlockContent(array $op, array $row, array &$ctx, bool $isRoot): array
{
    $id = (int) $row['id'];
    $scope = $ctx['projectId'];
    $rev = $ctx['rev'];
    $content = graphNormalizeBlockContent($op['content'] ?? null);
    $properties = graphContentDecodeProperties($row['properties']);
    if (!property_exists($properties, 'content')
        || graphContentJson($properties->content) !== graphContentJson($content)) {
        graphGuardBlockChange($scope, $id, $isRoot);
    }
    $properties->content = $content;
    dbRun('UPDATE blocks SET properties = ?, rev = ? WHERE scope_id = ? AND id = ?',
        [graphContentJson($properties), $rev, $scope, $id]);
    return ['id' => $id, 'rev' => $rev, 'prev' => graphPrevious($row)];
}

/** A common property edit keeps the content unless it explicitly submits a replacement list. */
function graphBlockPropertiesContent(string $next, string $old): string
{
    $properties = graphContentDecodeProperties($next);
    if (property_exists($properties, 'content')) {
        $properties->content = graphNormalizeBlockContent($properties->content);
    } else {
        $before = graphContentDecodeProperties($old);
        if (!property_exists($before, 'content')) return $next;
        $properties->content = $before->content;
    }
    return graphContentJson($properties);
}

function graphContentDecodeProperties(string $json): stdClass
{
    $value = json_decode($json);
    if ($value instanceof stdClass) return $value;
    // Some early writers used an empty array for an empty property set.
    if ($value === []) return new stdClass();
    throw new ApiError(t('server.graph.properties_object'));
}

/** content is an ordered list; a row id is caller-supplied and unique within the block. */
function graphNormalizeBlockContent(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) throw new ApiError(t('server.graph.content_list'));
    $seen = [];
    $content = [];
    foreach ($value as $record) {
        $fields = $record instanceof stdClass ? (array) $record : $record;
        if (!is_array($fields) || array_diff(array_keys($fields), ['id', 'kind', 'label', 'value'])) {
            throw new ApiError(t('server.graph.content_fields'));
        }
        $id = $fields['id'] ?? null;
        if (!is_string($id) || trim($id) === '' || isset($seen[$id])) {
            throw new ApiError(t('server.graph.content_id'));
        }
        $kind = $fields['kind'] ?? null;
        if (!in_array($kind, ['heading', 'text', 'markdown', 'data'], true)) {
            throw new ApiError(t('server.graph.content_kind'));
        }
        $normalized = ['id' => $id, 'kind' => $kind];
        if (array_key_exists('label', $fields)) {
            if (!is_string($fields['label'])) throw new ApiError(t('server.graph.content_label'));
            $normalized['label'] = $fields['label'];
        }
        if (!array_key_exists('value', $fields) || ($kind !== 'data' && !is_string($fields['value']))) {
            throw new ApiError(t('server.graph.content_value'));
        }
        $normalized['value'] = $fields['value'];
        $json = graphContentJson($normalized);
        // The standard secret checker walks arrays. JSON objects must also be checked at every depth.
        graphValidateSecrets(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $seen[$id] = true;
        $content[] = $normalized;
    }
    return $content;
}

function graphContentJson(mixed $value): string
{
    try {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiError(t('server.graph.content_json'));
    }
}

/**
 * Restore JSON objects in content before runBatch hashes and journals the operations.
 * The standard request decoder uses associative arrays and conflates {} and [].
 */
function graphContentRequestOperations(array $ops): array
{
    $keys = [];
    foreach ($ops as $index => $op) {
        if (($op['op'] ?? null) === 'block.content') {
            $keys[$index] = 'content';
        } elseif (in_array($op['op'] ?? null, ['block.create', 'block.update'], true)
            && is_array($op['properties'] ?? null) && array_key_exists('content', $op['properties'])) {
            $keys[$index] = 'properties';
        }
    }
    if (!$keys) return $ops;

    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = str_starts_with($type, 'multipart/form-data') ? ($_POST['payload'] ?? '') : file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw) : null;
    $original = $body instanceof stdClass ? (array) ($body->ops ?? [$body]) : [];
    foreach ($keys as $index => $field) {
        $source = $original[$index] ?? null;
        if (!$source instanceof stdClass) continue;
        if ($field === 'content' && property_exists($source, 'content')) {
            $ops[$index]['content'] = graphNormalizeBlockContent($source->content);
        } elseif ($field === 'properties' && ($source->properties ?? null) instanceof stdClass
            && property_exists($source->properties, 'content')) {
            $ops[$index]['properties']['content'] = graphNormalizeBlockContent($source->properties->content);
        }
    }
    return $ops;
}
