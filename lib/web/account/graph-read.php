<?php
declare(strict_types=1);

/** Чтение для кабинета и админки во время перехода между двумя форматами. */
function webGraphAvailable(): bool
{
    static $available;
    return $available ??= (bool) dbValue("SELECT 1 FROM schema_migrations WHERE name = '035-graph.sql'");
}

function webGraphRunsAvailable(): bool
{
    static $available;
    return $available ??= webGraphAvailable() && (bool) dbValue("SELECT 1 FROM schema_migrations WHERE name = '036-run-graph.sql'");
}

/** Предполагает псевдоним p у projects. */
function webGraphRootJoin(): string
{
    return webGraphAvailable() ? "
        LEFT JOIN graph_legacy_refs root_ref ON root_ref.scope_id = p.id
            AND root_ref.legacy_kind = 'project' AND root_ref.legacy_id = p.id
        LEFT JOIN blocks root_block ON root_block.scope_id = p.id AND root_block.id = root_ref.block_id
            AND root_block.kind = 'project'" : '';
}

function webGraphProjectTitle(): string
{
    return webGraphAvailable() ? 'COALESCE(root_block.title, p.title)' : 'p.title';
}

function webGraphRootColumn(): string
{
    return webGraphAvailable() ? 'root_block.id' : 'NULL';
}

/** Прирост к старым счётчикам. Существующая карта исключает двойной учёт перенесённых объектов. */
function webGraphCountColumns(bool $includeFlow = false): string
{
    if (!webGraphAvailable()) return '0 AS graph_folders, 0 AS graph_elements, 0 AS graph_assets';
    $flow = $includeFlow ? "OR a.kind = 'flow'" : '';
    return "
        (SELECT COUNT(*) FROM blocks b WHERE b.scope_id = p.id AND b.kind IN ('folder','scheme')
          AND NOT EXISTS (SELECT 1 FROM graph_legacy_refs g JOIN folders f ON f.id = g.legacy_id AND f.project_id = g.scope_id
                           WHERE g.scope_id = b.scope_id AND g.block_id = b.id AND g.legacy_kind = 'folder')) AS graph_folders,
        ((SELECT COUNT(*) FROM blocks b WHERE b.scope_id = p.id
             AND b.kind NOT IN ('project','folder','scheme','agent','role','asset','material')
             AND NOT EXISTS (SELECT 1 FROM graph_legacy_refs g JOIN elements e ON e.id = g.legacy_id AND e.project_id = g.scope_id
                              WHERE g.scope_id = b.scope_id AND g.block_id = b.id AND g.legacy_kind = 'element'))
         + (SELECT COUNT(*) FROM arrows a WHERE a.scope_id = p.id
             AND ((a.kind = 'link' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.properties, '$.relation')), '') IN ('','null')) $flow)
             AND NOT EXISTS (SELECT 1 FROM graph_legacy_refs g JOIN elements e ON e.id = g.legacy_id AND e.project_id = g.scope_id
                              WHERE g.scope_id = a.scope_id AND g.arrow_id = a.id AND g.legacy_kind = 'element'))) AS graph_elements,
        (SELECT COUNT(*) FROM blocks b WHERE b.scope_id = p.id AND b.kind IN ('asset','material')
          AND NOT EXISTS (SELECT 1 FROM graph_legacy_refs g JOIN assets a ON a.id = g.legacy_id AND a.project_id = g.scope_id
                           WHERE g.scope_id = b.scope_id AND g.block_id = b.id AND g.legacy_kind = 'asset')) AS graph_assets";
}

/** Рассчитывает название и адрес только для уже отобранных по правам проектов. */
function accountGraphProjects(array $projects): array
{
    if (!$projects || !webGraphAvailable()) return $projects;
    $ids = array_column($projects, 'id');
    $places = implode(',', array_fill(0, count($ids), '?'));
    $graph = array_column(dbAll(
        'SELECT p.id, ' . webGraphProjectTitle() . ' AS display_title, ' . webGraphRootColumn() . ' AS root_id, '
        . webGraphCountColumns(true) . ' FROM projects p ' . webGraphRootJoin() . " WHERE p.id IN ($places)",
        $ids
    ), null, 'id');
    return array_map(static function (array $p) use ($graph): array {
        return $p + ($graph[$p['id']] ?? []);
    }, $projects);
}

/** Один адрес для PHP и JSON админки. Для старых прогонов и элементов нужен классический экран. */
function webEditorUrl(string $key, ?int $block = null, ?int $folder = null, ?int $run = null, bool $view = false, ?int $element = null): string
{
    $graphDefault = !empty(config()['graph_editor']);
    $hash = ['p' => $key, 'b' => null, 'f' => $folder, 'e' => $element, 'run' => $run];
    $query = [];
    $page = 'index.php';
    if ($element === null && $block !== null && ($graphDefault || $run !== null)) {
        $hash['b'] = $block;
        $page = $graphDefault ? 'index.php' : 'graph.php';
    } elseif ($graphDefault) {
        $query['classic'] = 1;
    }
    if ($view) $query['view'] = 1;
    return $page . ($query ? '?' . http_build_query($query) : '') . '#' . http_build_query($hash);
}

/** Псевдонимы r, f и p совпадают в выборках истории кабинета и админки. */
function webGraphRunParent(): string
{
    return webGraphRunsAvailable() ? 'r.parent_block_id' : 'NULL';
}

function webGraphRunJoin(): string
{
    return webGraphRunsAvailable()
        ? 'LEFT JOIN blocks run_block ON run_block.scope_id = r.project_id AND run_block.id = r.parent_block_id'
        : '';
}

function webGraphRunTitle(): string
{
    return webGraphRunsAvailable() ? 'COALESCE(run_block.title, f.name, r.title)' : 'COALESCE(f.name, r.title)';
}

function webGraphRunUrl(array $row): string
{
    return webEditorUrl((string) $row['url_key'], isset($row['parent_block_id']) ? (int) $row['parent_block_id'] : null,
        isset($row['folder_id']) ? (int) $row['folder_id'] : null, (int) $row['id']);
}

function webGraphFolderId(int $scope, int $folder): ?int
{
    if (!webGraphAvailable()) return null;
    $id = dbValue("SELECT block_id FROM graph_legacy_refs
                    WHERE scope_id = ? AND legacy_kind = 'folder' AND legacy_id = ?", [$scope, $folder]);
    return $id !== null ? (int) $id : null;
}
