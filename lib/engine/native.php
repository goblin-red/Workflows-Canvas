<?php
declare(strict_types=1);

/** Снимок сцены входит в историю и переживает последующие изменения графа. */
function runScene(array $run): array
{
    if (empty($run['parent_block_id'])) {
        return engineGraph((int) $run['folder_id'], !advanceOn($run));
    }
    return json_decode($run['graph_snapshot'], true, 512, JSON_THROW_ON_ERROR);
}

function historyNode(array $step): ?int
{
    $id = $step['block_id'] ?? $step['element_id'] ?? null;
    return $id === null ? null : (int) $id;
}

function historyVia(array $step): ?int
{
    $id = $step['via_arrow_id'] ?? $step['via_edge_id'] ?? null;
    return $id === null ? null : (int) $id;
}

function historyChosen(array $step): ?int
{
    $id = $step['chosen_arrow_id'] ?? $step['chosen_edge_id'] ?? null;
    return $id === null ? null : (int) $id;
}

function historyArrow(array $mark): int
{
    return (int) ($mark['arrow_id'] ?? $mark['edge_id']);
}

function runTaskText(array $graph, int $id): string
{
    if (empty($graph['native'])) return taskText($id);
    $node = $graph['nodes'][$id];
    $spec = trim((string) ($node['spec']['text'] ?? ''));
    return $spec !== '' ? $spec : taskOneLine((string) $node['description']);
}

function runTaskSpec(array $graph, int $id): ?array
{
    return !empty($graph['native']) ? ($graph['nodes'][$id]['spec'] ?? null) : specText('element_id', $id);
}

function historyStepInsert(array $run, array $element, ?int $via): array
{
    return empty($run['parent_block_id'])
        ? ['element_id' => (int) $element['id'], 'via_edge_id' => $via]
        : ['block_id' => (int) $element['id'], 'block_kind' => $element['type'], 'via_arrow_id' => $via];
}

/** Только связи собственной области. Роль в графе дополняет серверное право агента. */
function nativeScene(int $scope, int $parent): array
{
    $row = dbRow('SELECT id, title, properties FROM blocks WHERE scope_id = ? AND id = ?', [$scope, $parent]);
    if (!$row) throw new ApiError('Scheme not found', 'not_found');
    $settings = graphProperties($row['properties']);
    $text = static fn(string $key, string $fallback = ''): string => is_string($settings[$key] ?? null) ? $settings[$key] : $fallback;
    $folder = [
        'id' => $parent, 'name' => $row['title'],
        'work_dir' => nativeWorkPath($text('workDir'), $scope), 'work_url' => $text('workUrl'),
        'role_scheme' => $text('roleScheme', 'solo'), 'run_env' => $text('runEnv', 'orca'),
    ];

    $graph = engineBlockGraph($scope, $parent);
    $graph['native'] = true;
    $graph['settings'] = $folder;
    $links = $roles = $agents = [];
    foreach (dbAll(
        "SELECT a.*, s.kind AS source_kind, t.kind AS target_kind, s.title AS source_title, t.title AS target_title,
                s.description AS source_description, t.description AS target_description,
                s.properties AS source_properties, t.properties AS target_properties
           FROM arrows a JOIN blocks s ON s.id = a.source_id AND s.scope_id = a.scope_id
           JOIN blocks t ON t.id = a.target_id AND t.scope_id = a.scope_id
          WHERE a.scope_id = ? AND a.kind IN ('link','contains') ORDER BY a.sort, a.id", [$scope]) as $link) {
        if ($link['direction'] === 'reverse') {
            foreach (['id', 'kind', 'title', 'description', 'properties'] as $field) {
                [$link['source_'.$field], $link['target_'.$field]] = [$link['target_'.$field], $link['source_'.$field]];
            }
        } elseif ($link['direction'] !== 'forward') continue;

        $from = (int) $link['source_id'];
        $to = (int) $link['target_id'];
        $props = graphProperties($link['properties']);
        $link['relation'] = $props['relation'] ?? '';
        $link['role'] = $props['role'] ?? '';
        if ($link['relation'] === 'role' && $link['source_kind'] === 'agent' && $link['target_kind'] === 'role') {
            $role = graphProperties($link['target_properties'])['role'] ?? '';
            if (in_array($role, ['lead', 'worker'], true)) $roles[$from][$role] = true;
        }
        $links[$from][] = $link;
    }

    $accounts = [];
    foreach (dbAll(
        "SELECT r.block_id, a.id, a.role FROM graph_legacy_refs r JOIN agents a
           ON a.id = r.legacy_id AND a.project_id = r.scope_id
          WHERE r.scope_id = ? AND r.legacy_kind = 'agent' AND r.block_id IS NOT NULL", [$scope]) as $agent) {
        $accounts[(int) $agent['block_id']][] = $agent;
    }
    foreach ($accounts as $id => $entries) {
        if (count($entries) !== 1 || count($roles[$id] ?? []) !== 1) continue;
        $agent = $entries[0];
        if (isset($roles[$id][$agent['role']])) $agents[$id] = $agent;
    }

    $graph['agents'] = $agents;
    $contexts = [];
    foreach ($links as $from => $out) {
        foreach ($out as $link) {
            if ($link['kind'] !== 'contains' || !in_array($link['source_kind'], ['group', 'area'], true)) continue;
            $contexts[(int) $link['target_id']][$from] = [
                'id' => $from, 'no' => $from, 'type' => $link['source_kind'], 'title' => $link['source_title'],
                'about' => taskOneLine($link['source_description']), 'text' => '',
            ];
        }
    }

    $no = 0;
    foreach ($graph['nodes'] as $id => &$node) {
        $node['no'] = ++$no;
        $node['context'] = nativeGroups($id, $contexts);
        $node['inputs'] = [];
        foreach ($links[$id] ?? [] as $link) {
            if ($link['kind'] !== 'link') continue;
            $to = (int) $link['target_id'];
            if ($link['relation'] === 'agent' && $link['target_kind'] === 'agent' && ($agents[$to]['role'] ?? '') === 'worker') {
                $node['agent_id'] ??= (int) $agents[$to]['id'];
            }
            if ($link['relation'] === 'gatewayTarget' && $node['type'] === 'gateway'
                && in_array($link['target_kind'], ['project', 'folder', 'scheme'], true)) {
                $node['target_block_id'] ??= $to;
            }
            if ($link['relation'] === 'asset' && in_array($link['target_kind'], ['asset','material'], true)) {
                $material = graphProperties($link['target_properties']);
                $text = is_string($material['body'] ?? null) ? $material['body'] : $link['target_description'];
                if ($link['role'] === 'spec' && trim($text) !== '') {
                    $node['spec'] ??= ['text' => $text, 'block' => $to, 'title' => $link['target_title']];
                } elseif (in_array($link['role'], ['input','reference','attachment','code'], true)) {
                    $node['inputs'][] = ['block' => $to, 'title' => $link['target_title'], 'role' => $link['role'], 'text' => $text];
                }
            }
        }
    }
    unset($node);
    return $graph;
}

function nativeGroups(int $id, array $parents, array &$seen = []): array
{
    $out = [];
    foreach ($parents[$id] ?? [] as $parent => $group) {
        if (isset($seen[$parent])) continue;
        $seen[$parent] = true;
        $out = array_merge($out, nativeGroups($parent, $parents, $seen), [$group]);
    }
    return $out;
}

function runElementAsked(array $run): ?array
{
    if (!empty($run['parent_block_id']) && input('block') !== null) {
        $id = graphId(input('block'));
        return runScene($run)['nodes'][$id] ?? null;
    }
    return runElement($run, stepNoAsked());
}

function runSceneLiveGuard(array $run): void
{
    if (empty($run['parent_block_id'])) {
        runLiveGuard((int) $run['folder_id'], (int) ($run['id'] ?? 0));
        return;
    }
    $live = nativeActive((int) $run['project_id'], (int) $run['parent_block_id'], (int) ($run['id'] ?? 0));
    if ($live) throw new ApiError(ta('agents.runs.already_going', ['no' => (int) $live['no']]), 'conflict');
}

function nativeActive(int $project, int $parent, int $except = 0): ?array
{
    return dbRow("SELECT id, `no` FROM runs WHERE project_id = ? AND parent_block_id = ? AND id <> ?
                   AND state IN ('running','paused') LIMIT 1", [$project, $parent, $except]);
}

/** Свойства исполнения берём из выданного снимка, а старые шаги используют прежние настройки. */
function runStepProps(array $run, array $step): array
{
    if (!empty($step['block_id'])) return runScene($run)['nodes'][(int) $step['block_id']]['props'] ?? [];
    return $step['element_id'] ? propsOf((int) $step['element_id']) : [];
}

function runParentAsked(): ?int
{
    $parent = input('parent');
    if ($parent === null) return null;
    if (input('folder') !== null) throw new ApiError('Specify parent or folder');
    return graphId($parent);
}

/** Пропуск выдаётся лишь назначенному и действующему worker этой области. */
function nativeStepAgent(array $run, array $element): ?int
{
    $id = (int) ($element['agent_id'] ?? 0);
    $asked = input('agent');
    if ($asked !== null) {
        $card = runScene($run)['agents'][graphId($asked)] ?? null;
        if (!$card || (int) $card['id'] !== $id) throw new ApiError(ta('agents.steps.not_your_step'), 'scope');
    }
    return $id && dbValue("SELECT id FROM agents WHERE id = ? AND project_id = ? AND role = 'worker'",
        [$id, (int) $run['project_id']]) ? $id : null;
}

function nativeTaskMeaning(array $node): array
{
    return ['about' => taskOneLine((string) $node['description']), 'context' => $node['context'] ?? [],
        'spec' => trim((string) ($node['spec']['text'] ?? ''))];
}

function nativeTaskBlock(array $node): array
{
    return array_intersect_key($node, array_flip(['id', 'no', 'type', 'title', 'description']));
}

function historyKind(array $step): string
{
    return $step['block_kind'] ?? (string) dbValue('SELECT type FROM elements WHERE id = ?', [(int) $step['element_id']]);
}

/** Выпуск пропуска связывает карточку с учётной записью проекта под общим замком. */
function nativeWorkerAccount(int $scope, int $blockId): array
{
    $block = graphRow('blocks', $scope, $blockId);
    if ($block['kind'] !== 'agent') throw new ApiError(t('server.agent.token_worker_only'));
    $roles = [];
    foreach (dbAll(
        "SELECT a.properties, target.properties AS role_props FROM arrows a JOIN blocks target
           ON target.scope_id = a.scope_id AND target.kind = 'role'
          AND target.id = CASE WHEN a.direction = 'reverse' THEN a.source_id ELSE a.target_id END
          WHERE a.scope_id = ? AND a.kind = 'link' AND
            ((a.direction = 'forward' AND a.source_id = ?) OR (a.direction = 'reverse' AND a.target_id = ?))",
        [$scope, $blockId, $blockId]) as $link) {
        if ((graphProperties($link['properties'])['relation'] ?? '') !== 'role') continue;
        $role = graphProperties($link['role_props'])['role'] ?? '';
        if (in_array($role, ['worker', 'lead'], true)) $roles[$role] = true;
    }
    if ($roles !== ['worker' => true]) throw new ApiError(t('server.agent.token_worker_only'), 'scope');

    $accounts = dbAll("SELECT a.* FROM graph_legacy_refs r JOIN agents a ON a.project_id = r.scope_id AND a.id = r.legacy_id
                        WHERE r.scope_id = ? AND r.legacy_kind = 'agent' AND r.block_id = ?", [$scope, $blockId]);
    if ($accounts) {
        if (count($accounts) !== 1 || $accounts[0]['role'] !== 'worker') {
            throw new ApiError(t('server.agent.token_worker_only'), 'scope');
        }
        return $accounts[0];
    }
    dbRun("INSERT INTO agents (project_id, name, role, rev) VALUES (?, ?, 'worker', ?)",
        [$scope, mb_substr($block['title'], 0, 255), bumpRev($scope)]);
    $id = dbId();
    dbRun("INSERT INTO graph_legacy_refs (scope_id, legacy_kind, legacy_id, block_id) VALUES (?, 'agent', ?, ?)",
        [$scope, $id, $blockId]);
    return agentRow($id, $scope);
}

/** Проверяем также существующего предка, пока целевой каталог ещё не создан. */
function nativeWorkPath(string $path, int $projectId): string
{
    if (trim($path) === '') return '';
    try {
        if (str_contains($path, "\0")) throw new ApiError('Invalid working folder.');
        $clean = folderWorkDir($path, $projectId);
        $ancestor = $clean;
        $tail = [];
        while (!is_dir($ancestor)) {
            if (file_exists($ancestor) || is_link($ancestor) || $ancestor === '/') {
                throw new ApiError('Invalid working folder.');
            }
            array_unshift($tail, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $real = realpath($ancestor);
        if ($real === false) throw new ApiError('The working folder is unavailable.');
        $resolved = rtrim($real, '/') . ($tail ? '/' . implode('/', $tail) : '');
        // Проверка разрешённых корней, включая переход через символьную ссылку, и области.
        return folderWorkDir($resolved, $projectId);
    } catch (ApiError $error) {
        throw new ApiError(langAgents() === 'en' ? 'Working directory is outside the allowed roots or is unavailable.'
            : 'The working folder is unavailable or outside the allowed roots.', 'forbidden',
            ['code2' => 'work_dir_forbidden']);
    }
}

/** Создаём только каталоги этого прогона. Откат убирает только созданные нами пустые каталоги. */
function nativeWorkFolders(string $work, int $no, int $projectId): void
{
    if ($work === '') return;

    foreach ([$work . '/in', $work . '/out/r' . $no] as $path) {
        $safe = nativeWorkPath($path, $projectId);
        if ($safe !== $path) throw new ApiError('The run subfolder must be inside its working folder.', 'forbidden');
        $missing = [];
        for ($dir = $safe; !is_dir($dir); $dir = dirname($dir)) $missing[] = $dir;
        foreach (array_reverse($missing) as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775) && !is_dir($dir)) {
                throw new ApiError('Could not create the working folder.', 'conflict');
            }
            if ($dir !== realpath($dir)) throw new ApiError('The working folder was redirected.', 'forbidden');
            dbOnRollback(static fn() => @rmdir($dir));
        }
    }
}

/** Ссылки относительные. Полномочие передаётся только заголовком запроса. */
function nativeMaterialLinks(array $node, bool $worker): array
{
    return array_map(static fn(array $item): array => [
        'block' => (int) $item['block'], 'title' => (string) $item['title'], 'role' => $item['role'],
        'url' => '?' . http_build_query(['op' => $worker ? 'step.get' : 'graph.asset.file',
            'material' => (int) $item['block'], 'format' => $worker ? null : 'material']),
    ], nativeStepMaterials($node));
}


function nativeStepMaterials(array $node): array
{
    $materials = $node['inputs'] ?? [];
    if (!empty($node['spec']['block'])) $materials[] = $node['spec'] + ['role' => 'spec'];
    return $materials;
}
