<?php
declare(strict_types=1);

/** Представление одной прежней папки в формате команд помощника графа. */
function aiAgentLegacyScene(int $projectId, int $folderId): array
{
    $folder = folderRow($folderId, $projectId);
    $rows = dbAll("SELECT id, type AS kind, title, SUBSTR(COALESCE(description, ''), 1, 2048) AS description
                    FROM elements WHERE project_id = ? AND folder_id = ? AND type NOT IN ('arrow', 'link')
                    ORDER BY `no`, id LIMIT " . (AI_GRAPH_MAX_BLOCKS + 1), [$projectId, $folderId]);
    $truncated = count($rows) > AI_GRAPH_MAX_BLOCKS;
    $blocks = array_map(static function (array $row): array {
        return aiGraphSceneBlock($row) + ($row['description'] === '' ? [] : ['description' => aiGraphSafeText($row['description'], 240)]);
    }, array_slice($rows, 0, AI_GRAPH_MAX_BLOCKS));

    $arrows = [];
    if ($blocks) {
        $ids = array_column($blocks, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $links = dbAll("SELECT id, type, title, from_id, to_id, branch FROM elements
                         WHERE project_id = ? AND folder_id = ? AND type IN ('arrow', 'link')
                           AND from_id IN ($marks) AND to_id IN ($marks)
                         ORDER BY `no`, id LIMIT " . (AI_GRAPH_MAX_ARROWS + 1), [$projectId, $folderId, ...$ids, ...$ids]);
        $truncated = $truncated || count($links) > AI_GRAPH_MAX_ARROWS;
        foreach (array_slice($links, 0, AI_GRAPH_MAX_ARROWS) as $link) {
            $arrow = ['id' => (int) $link['id'], 'sourceId' => (int) $link['from_id'],
                'targetId' => (int) $link['to_id'], 'kind' => $link['type'] === 'arrow' ? 'flow' : 'link', 'direction' => 'forward'];
            if (in_array($link['branch'], ['yes', 'no'], true)) $arrow['branchKey'] = $link['branch'];
            if ($link['title'] !== '') $arrow['label'] = aiGraphSafeText($link['title'], 80);
            $arrows[] = $arrow;
        }
    }

    return ['parent' => aiGraphSceneBlock(['id' => $folderId, 'kind' => 'folder', 'title' => $folder['name']]),
        'blocks' => $blocks, 'arrows' => $arrows, 'truncated' => $truncated];
}

function aiAgentLegacyCompile(array $scene, mixed $commands, int $folderId): array
{
    $replacements = [];
    $blocks = array_fill_keys(array_column($scene['blocks'], 'id'), true);
    $refs = [];
    foreach (aiGraphCompile($scene, $commands) as $op) {
        if ($op['op'] === 'block.create') {
            $ref = $op['ref'];
            $refs[$ref] = count($replacements);
            $replacements[] = ['op' => 'element.create', 'folder' => $folderId, 'ref' => $ref,
                'type' => requireKind($op['kind']), 'title' => $op['title']] + array_intersect_key($op, ['description' => true]);
        } elseif ($op['op'] === 'arrow.create' && $op['kind'] === 'contains') {
            if (isset($op['properties']['placement'])) {
                $replacements[$refs[$op['targetId']]]['style'] = $op['properties']['placement'];
            }
        } elseif ($op['op'] === 'arrow.create') {
            $from = aiGraphBlockRef($op['sourceId'], $blocks, $refs);
            $to = aiGraphBlockRef($op['targetId'], $blocks, $refs);
            $replacements[] = aiAgentLegacyArrow($op, $folderId, $from, $to);
        } else {
            $op['op'] = match ($op['op']) {
                'block.update' => 'element.update',
                'arrow.delete' => 'element.delete',
            };
            $replacements[] = $op;
        }
    }
    return $replacements;
}

function aiAgentLegacyArrow(array $op, int $folderId, int|string $from, int|string $to): array
{
    $branch = $op['properties']['branchKey'] ?? 'flow';
    if (!in_array($op['direction'], ['forward', 'reverse'], true) || !in_array($branch, ['flow', 'yes', 'no'], true)) {
        throw new ApiError('This folder supports one arrow direction and yes or no branches');
    }
    if ($op['direction'] === 'reverse') [$from, $to] = [$to, $from];
    $type = $op['kind'] === 'flow' ? 'arrow' : 'link';
    return ['op' => 'element.create', 'folder' => $folderId, 'type' => $type, 'from' => $from, 'to' => $to,
        'title' => $op['properties']['branchLabel'] ?? $op['properties']['title'] ?? '', 'branch' => $branch];
}

/** Повторно проверить границы под замком проекта перед записью пачки. */
function aiAgentGuardLegacyOps(int $projectId, int $folderId, array $ops): void
{
    folderRow($folderId, $projectId);
    $refs = [];
    $fields = ['title', 'description', 'style', 'props', 'from', 'to', 'branch', 'back', 'in'];
    foreach ($ops as $op) {
        if (!is_array($op)) aiAgentLegacyScope();
        $name = $op['op'] ?? '';
        $allowed = match ($name) {
            'element.create' => ['folder', 'type', 'ref', ...$fields],
            'element.update' => ['id', ...$fields],
            'element.delete' => ['id'],
            default => [],
        };
        if (!$allowed || array_diff(array_keys($op), ['op', ...$allowed])) aiAgentLegacyScope();

        if ($name === 'element.create') {
            $target = $op['folder'] ?? null;
            if ($target !== $folderId && $target !== (string) $folderId) aiAgentLegacyScope();
        } else {
            $row = aiAgentLegacyElement($projectId, $folderId, $op['id'] ?? null, $refs);
            if ($row !== null) {
                foreach (['from_id', 'to_id'] as $key) {
                    if ($row[$key]) aiAgentLegacyElement($projectId, $folderId, (int) $row[$key], $refs);
                }
                $deleting = $name === 'element.delete';
                if ($deleting || array_key_exists('in', $op)) aiAgentLegacyGuardRelations($projectId, $folderId, (int) $row['id'], $deleting);
            }
        }

        foreach (['from', 'to'] as $end) {
            if (array_key_exists($end, $op)) aiAgentLegacyElement($projectId, $folderId, $op[$end], $refs);
        }
        foreach ((array) ($op['in'] ?? []) as $id) aiAgentLegacyElement($projectId, $folderId, $id, $refs);
        if (isset($op['ref'])) {
            if (!is_string($op['ref']) || !preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $op['ref']) || isset($refs[$op['ref']])) {
                throw new ApiError('Invalid or duplicate reference for the new block');
            }
            $refs[$op['ref']] = true;
        }
    }
}

function aiAgentLegacyElement(int $projectId, int $folderId, mixed $id, array $refs): ?array
{
    if (is_string($id) && isset($refs[$id])) return null;
    if (!is_int($id) && (!is_string($id) || !ctype_digit($id))) aiAgentLegacyScope();
    $row = dbRow('SELECT id, from_id, to_id FROM elements WHERE id = ? AND project_id = ? AND folder_id = ?',
        [(int) $id, $projectId, $folderId]);
    return $row ?? aiAgentLegacyScope();
}

function aiAgentLegacyGuardRelations(int $projectId, int $folderId, int $id, bool $deleting): void
{
    if ($deleting && dbValue('SELECT 1 FROM elements a LEFT JOIN elements src ON src.id = a.from_id
                   LEFT JOIN elements dst ON dst.id = a.to_id
                  WHERE (a.from_id = ? OR a.to_id = ?) AND (a.project_id <> ? OR a.folder_id <> ?
                    OR src.project_id <> ? OR src.folder_id <> ? OR dst.project_id <> ? OR dst.folder_id <> ?) LIMIT 1',
        [$id, $id, $projectId, $folderId, $projectId, $folderId, $projectId, $folderId])) aiAgentLegacyScope();
    if (dbValue('SELECT 1 FROM members m JOIN elements e ON e.id = CASE WHEN m.element_id = ? THEN m.container_id ELSE m.element_id END
                  WHERE (m.container_id = ? OR m.element_id = ?) AND (e.project_id <> ? OR e.folder_id <> ?) LIMIT 1',
        [$id, $id, $id, $projectId, $folderId])) aiAgentLegacyScope();
}

function aiAgentLegacyScope(): never
{
    throw new ApiError('The action is outside the selected scheme', 'scope');
}
