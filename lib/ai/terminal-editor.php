<?php
declare(strict_types=1);

require_once __DIR__ . '/terminal-legacy.php';

/** Список редактора содержит ID и названия, без ключей проектов. */
function aiAgentEditorProjects(): void
{
    $agent = aiAgentMachine();
    $user = (int) $agent['user_id'];
    if ($agent['scope'] === 'scheme') {
        $target = aiAgentEditorTarget($agent);
        $project = dbRow('SELECT id, title FROM projects WHERE id = ? AND owner_id = ?', [$target['projectId'], $user]);
        if (!$project) throw new ApiError(t('server.project.not_found'), 'not_found');
        $project['id'] = (int) $project['id'];
        $title = $target['parentId'] !== null
            ? dbValue('SELECT title FROM blocks WHERE id = ? AND scope_id = ?', [$target['parentId'], $project['id']])
            : dbValue('SELECT name FROM folders WHERE id = ? AND project_id = ?', [$target['folderId'], $project['id']]);
        $project['schemes'] = [['parent' => $target['parentId'], 'folder' => $target['folderId'], 'title' => (string) $title]];
        reply(['projects' => [$project]]);
    }

    $projects = dbAll('SELECT id, title FROM projects WHERE owner_id = ? ORDER BY title, id', [$user]);
    foreach ($projects as &$project) {
        $id = $project['id'] = (int) $project['id'];
        $project['root'] = graphRootId($id);
        $rows = dbAll(
            "SELECT b.id AS parent, r.legacy_id AS folder, b.title FROM blocks b
              LEFT JOIN graph_legacy_refs r ON r.scope_id = b.scope_id AND r.block_id = b.id AND r.legacy_kind = 'folder'
              WHERE b.scope_id = ? AND b.kind IN ('scheme','folder')
              UNION ALL
              SELECT NULL AS parent, f.id AS folder, f.name AS title FROM folders f
              WHERE f.project_id = ? AND NOT EXISTS (SELECT 1 FROM graph_legacy_refs r
                    WHERE r.scope_id = f.project_id AND r.legacy_kind = 'folder' AND r.legacy_id = f.id AND r.block_id IS NOT NULL)
              ORDER BY title", [$id, $id]);
        $project['schemes'] = array_map(static fn(array $row): array => [
            'parent' => $row['parent'] === null ? null : (int) $row['parent'],
            'folder' => $row['folder'] === null ? null : (int) $row['folder'],
            'title' => $row['title'],
        ], $rows);
    }
    unset($project);
    reply(['projects' => $projects]);
}

function aiAgentEditorTarget(array $agent): array
{
    $project = inputInt('projectId');
    $parent = inputInt('parent');
    $folder = inputInt('folder');
    if ($agent['scope'] === 'scheme') {
        $project ??= $agent['scope_project_id'] === null ? null : (int) $agent['scope_project_id'];
        if ($parent === null && $folder === null) {
            $parent = $agent['scope_parent_id'] === null ? null : (int) $agent['scope_parent_id'];
            $folder = $agent['scope_folder_id'] === null ? null : (int) $agent['scope_folder_id'];
        }
    }
    return aiAgentAuthorizeTarget($agent, ['projectId' => $project, 'parentId' => $parent, 'folderId' => $folder]);
}

function aiAgentEditorView(array $target): array
{
    $project = $target['projectId'];
    // В графовом редакторе старый адрес папки тоже ведёт к канонической схеме.
    if ($target['folderId'] !== null && ($target['parentId'] === null
        || (empty(config()['graph_editor']) && input('folder') !== null))) {
        return ['classic', aiAgentLegacyScene($project, $target['folderId'])];
    }
    $parent = $target['parentId'] ?? graphRootId($project);
    if ($parent === null) throw new ApiError('Specify a scheme', 'not_found');
    return ['graph', aiGraphScene($project, $parent)];
}

function aiAgentEditorScene(): void
{
    $info = dbTransaction(static function (): array {
        $agent = aiAgentMachine(true);
        $target = aiAgentEditorTarget($agent);
        $project = dbRow('SELECT rev FROM projects WHERE id = ? AND owner_id = ? FOR UPDATE', [$target['projectId'], $agent['user_id']]);
        if (!$project) throw new ApiError(t('server.project.not_found'), 'not_found');
        [$format, $scene] = aiAgentEditorView($target);
        return ['target' => $target, 'rev' => (int) $project['rev'], 'format' => $format, 'scene' => $scene];
    });
    reply($info);
}

function aiAgentEditorEdit(): void
{
    $body = request()['body'];
    $id = $body['opId'] ?? null;
    if (!is_int($body['ifRev'] ?? null) || $body['ifRev'] < 0 || !is_string($id)
        || !preg_match('/\Aedit-[A-Za-z0-9_-]{8,64}\z/D', $id)
        || array_diff(array_keys(request()['query']), ['op'])
        || array_diff(array_keys($body), ['op', 'projectId', 'parent', 'folder', 'ifRev', 'opId', 'commands'])) {
        throw new ApiError('Edits require ifRev, an opId starting with edit-, and commands');
    }
    $out = dbTransaction(static function () use ($body): array {
        $agent = aiAgentMachine(true);
        $target = aiAgentEditorTarget($agent);
        $projectId = $target['projectId'];
        $project = dbRow('SELECT * FROM projects WHERE id = ? AND owner_id = ? FOR UPDATE', [$projectId, $agent['user_id']]);
        if (!$project) throw new ApiError(t('server.project.not_found'), 'not_found');
        [$format, $scene] = aiAgentEditorView($target);
        $parent = $scene['parent']['id'];
        $commands = $body['commands'] ?? null;
        $scope = 'terminal-edit:' . $agent['user_id'] . ':' . $format . ':' . $parent;
        $hash = hash('sha256', aiGraphJson([$body['ifRev'], $commands]));
        $repeat = journalFindRepeat($projectId, $scope, $body['opId'], $hash);
        if ($repeat !== null) return $repeat + ['repeat' => true];
        $ops = $format === 'classic' ? aiAgentLegacyCompile($scene, $commands, $parent) : aiGraphCompile($scene, $commands);
        if (!$ops) throw new ApiError('Specify the scheme edits');
        if ($agent['scope'] === 'scheme' && $format === 'graph') aiAgentGuardGraphEdits($projectId, $scene, $ops);
        if ($format === 'classic') aiAgentGuardLegacyOps($projectId, $parent, $ops);

        return runBatch($project, $ops, static function (array $op, array &$ctx): array {
            $handler = opRule($op['op'])[1];
            if (in_array($op['op'], GRAPH_WRITE_OPS, true)) $ctx['graphRoot'] ??= graphEnsureRoot($ctx['projectId'], $ctx['rev']);
            return $handler($op, $ctx);
        }, false, ['via' => 'terminal-agent', 'user_id' => (int) $agent['user_id'], 'op_scope' => $scope, 'request_hash' => $hash]);
    });
    reply(['rev' => $out['rev'], 'results' => $out['results']] + (empty($out['repeat']) ? [] : ['repeat' => true]), $out['warnings']);
}

/** Изменение общего блока или стрелки может быть видно на другой схеме. */
function aiAgentGuardGraphEdits(int $project, array $scene, array $ops): void
{
    $parent = $scene['parent']['id'];
    $shared = array_fill_keys(array_column(dbAll(
        "SELECT DISTINCT a.target_id FROM arrows a JOIN arrows b
           ON b.scope_id = a.scope_id AND b.target_id = a.target_id AND b.kind = 'contains' AND b.source_id <> a.source_id
          WHERE a.scope_id = ? AND a.kind = 'contains' AND a.source_id = ?", [$project, $parent]), 'target_id'), true);
    $arrows = array_column($scene['arrows'], null, 'id');
    $separate = array_fill_keys(array_column(array_filter($scene['blocks'],
        static fn(array $block): bool => in_array($block['kind'], ['project', 'scheme', 'folder'], true)), 'id'), true);
    foreach ($ops as $op) {
        if ($op['op'] === 'block.update' && isset($separate[$op['id']])) {
            throw new ApiError('This scheme requires separate editing access', 'scope');
        }
        $ids = match ($op['op']) {
            'block.update' => [$op['id']],
            'arrow.create' => [$op['sourceId'], $op['targetId']],
            'arrow.delete' => [$arrows[$op['id']]['sourceId'], $arrows[$op['id']]['targetId']],
            default => [],
        };
        foreach ($ids as $id) {
            if (isset($shared[$id])) throw new ApiError('This block is used in several schemes', 'scope');
        }
    }
}
