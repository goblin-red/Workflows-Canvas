<?php
declare(strict_types=1);

/** Перенос из открытого в браузере классического проекта. */
function graphImportLegacy(): void
{
    if (!in_array(caller()['scope'], ['session', 'guest'], true)) {
        throw new ApiError(t('server.token.wrong_op', ['op' => 'graph.importLegacy']), 'scope');
    }
    $project = requireProject(false);
    requireWrite($project);
    require_once __DIR__ . '/../graph_migration/migrate.php';
    $result = (new GraphMigration(db(), (int) $project['id']))->run(true, null, authorizeImport: static function (?array $locked): void {
        if ($locked === null) throw new ApiError(t('server.project.not_found'), 'not_found');
        requireWrite($locked);
    });
    if (!$result['ok']) {
        throw new ApiError(t('server.graph.legacy_import'), 'conflict', ['code2' => 'legacy_import_invalid']);
    }
    if (!$result['imported']) unset($GLOBALS['goblin_single']);
    reply(['imported' => $result['imported'], 'rootId' => $result['rootId'], 'rev' => $result['rev']]);
}

function graphRootId(int $scopeId): ?int
{
    $id = dbValue(
        "SELECT block_id FROM graph_legacy_refs WHERE scope_id = ? AND legacy_kind = 'project' AND legacy_id = ?",
        [$scopeId, $scopeId]
    );
    return $id === null ? null : (int) $id;
}

/** Вызывается при первой правке под замком проекта. */
function graphEnsureRoot(int $scopeId, int $rev): int
{
    $id = graphRootId($scopeId);
    if ($id !== null) return $id;

    dbRun(
        "INSERT INTO blocks (scope_id, kind, title, description, properties, appearance, rev)
         SELECT id, 'project', title, '', '{}', '{}', ? FROM projects WHERE id = ?",
        [$rev, $scopeId]
    );
    $id = dbId();
    dbRun(
        "INSERT INTO graph_legacy_refs (scope_id, legacy_kind, legacy_id, block_id) VALUES (?, 'project', ?, ?)",
        [$scopeId, $scopeId, $id]
    );
    return $id;
}

function graphBlockShape(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'scopeId' => (int) $row['scope_id'],
        'kind' => $row['kind'],
        'title' => $row['title'],
        'description' => $row['description'],
        'properties' => graphPublicProperties($row),
        'appearance' => (object) json_decode($row['appearance']),
        'rev' => (int) $row['rev'],
    ];
}

function graphPublicProperties(array $row, bool $asArray = false): array|object
{
    $properties = (array) json_decode($row['properties'], $asArray);
    if (in_array($row['kind'], ['asset', 'material'], true)) {
        unset($properties['uri'], $properties['fileKey'], $properties['fileStorage'], $properties['storageKey']);
    }
    return $asArray ? $properties : (object) $properties;
}

function graphArrowShape(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'scopeId' => (int) $row['scope_id'],
        'sourceId' => (int) $row['source_id'],
        'targetId' => (int) $row['target_id'],
        'kind' => $row['kind'],
        'direction' => $row['direction'],
        'sort' => (int) $row['sort'],
        'properties' => (object) json_decode($row['properties']),
        'appearance' => (object) json_decode($row['appearance']),
        'rev' => (int) $row['rev'],
    ];
}

function graphRow(string $table, int $scopeId, int $id): array
{
    $row = dbRow("SELECT * FROM $table WHERE scope_id = ? AND id = ?", [$scopeId, $id]);
    if (!$row) throw new ApiError($table === 'blocks' ? t('server.graph.block_not_found') : t('server.graph.arrow_not_found'), 'not_found');
    return $row;
}

/** Положительный целый ID или известная ссылка на созданный в этой пачке объект нужного типа. */
function graphId(mixed $value, array $refs = []): int
{
    if (is_string($value) && isset($refs[$value])) return $refs[$value];
    $id = (is_int($value) || is_string($value)) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($id === false || $id < 1) throw new ApiError(t('server.graph.invalid_id'), 'not_found');
    return $id;
}
