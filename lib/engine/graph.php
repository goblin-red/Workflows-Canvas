<?php
/* Общий граф предпроверки, старта и хода прогона.
   engineBlockGraph() отдаёт ID блоков и стрелок v3.
   engineGraph() в режиме blocks адаптирует перенесённую папку к ID действующей истории. */

declare(strict_types=1);

require_once __DIR__ . '/branches.php';
require_once __DIR__ . '/native.php';

/**
 * Граф одной папки.
 *   nodes — id узла → строка элемента + props (правила в своём виде, graphRules)
 *           + bad, если правило записано неверно: его называет предпроверка;
 *   edges — id стрелки → строка стрелки;
 *   in / out — id узла → список id стрелок, по возрастанию id.
 */
function engineGraph(int $folderId, bool $legacy = false): array
{
    if (!$legacy && getenv('GOBLIN_RUN_GRAPH') === 'blocks' && graphHasLegacyRefs()) {
        $scope = dbValue('SELECT project_id FROM folders WHERE id = ?', [$folderId]);
        if ($scope !== null) {
            $block = graphLegacyFolderBlock((int) $scope, $folderId, false);
            if ($block !== null) return engineHistoryGraph((int) $scope, $block, $folderId);
        }
    }
    return engineLegacyGraph($folderId);
}

/** Старые установки до 035 и ещё не перенесённые папки используют прежнюю схему. */
function graphHasLegacyRefs(): bool
{
    static $available = null;
    return $available ??= (bool) (dbDriver() === 'sqlite'
        ? dbValue("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'graph_legacy_refs'")
        : dbValue("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'graph_legacy_refs'"));
}

function engineLegacyGraph(int $folderId): array
{
    $rows = dbAll(
        "SELECT id, `no`, type, title, description, agent_id, from_id, to_id, branch, back, target_folder_id, folder_id
           FROM elements
          WHERE folder_id = ? AND type IN ('block','decision','gateway','arrow')
          ORDER BY id",
        [$folderId]
    );
    $props = propsMap($folderId);
    $nodes = $edges = [];
    foreach ($rows as $row) {
        if ($row['type'] === 'arrow') {
            $edges[] = $row;
        } else {
            $row['props'] = $props[(int) $row['id']] ?? [];
            $nodes[] = $row;
        }
    }
    return graphAssemble($folderId, $nodes, $edges, false);
}

/** Исполняемая сцена из blocks/arrows. Вложение задаёт состав сцены. */
function engineBlockGraph(int $scopeId, int $parentId): array
{
    $blocks = dbAll("SELECT b.* FROM arrows c JOIN blocks b ON b.id = c.target_id AND b.scope_id = c.scope_id
                     WHERE c.scope_id = ? AND c.source_id = ? AND c.kind = 'contains' ORDER BY b.id",
        [$scopeId, $parentId]);
    $flows = dbAll("SELECT a.* FROM arrows a
                   JOIN arrows src ON src.target_id = a.source_id AND src.scope_id = a.scope_id
                   JOIN arrows dst ON dst.target_id = a.target_id AND dst.scope_id = a.scope_id
                   WHERE a.scope_id = ? AND a.kind = 'flow'
                     AND src.kind = 'contains' AND src.source_id = ?
                     AND dst.kind = 'contains' AND dst.source_id = ? ORDER BY a.id",
        [$scopeId, $parentId, $parentId]);

    $nodes = [];
    foreach ($blocks as $block) {
        if (!in_array($block['kind'], ['block', 'decision', 'gateway'], true)) continue;
        $id = (int) $block['id'];
        $props = graphProperties($block['properties']);
        $props += graphProperties($props['legacyProps'] ?? null);
        $nodes[] = [
            'id' => $id, 'no' => (int) ($props['no'] ?? $props['legacyNo'] ?? $id), 'type' => $block['kind'],
            'title' => $block['title'], 'description' => $block['description'],
            'props' => $props, 'agent_id' => null, 'target_folder_id' => null,
        ];
    }
    $edges = [];
    foreach ($flows as $flow) {
        if (!in_array($flow['direction'], ['forward', 'reverse'], true)) continue;
        $prop = graphProperties($flow['properties']);
        $key = $prop['branchKey'] ?? $prop['branch'] ?? '';
        $label = $prop['branchLabel'] ?? $prop['label'] ?? '';
        [$from, $to] = [(int) $flow['source_id'], (int) $flow['target_id']];
        if ($flow['direction'] === 'reverse') [$from, $to] = [$to, $from];
        $edges[] = [
            'id' => (int) $flow['id'], 'type' => 'arrow', 'from_id' => $from, 'to_id' => $to,
            'branch' => is_string($key) ? $key : '',
            'label' => is_string($label) ? $label : '',
            'title' => is_string($prop['title'] ?? null) ? $prop['title'] : '',
            'condition' => $prop['branchCondition'] ?? $prop['condition'] ?? $prop['cond'] ?? '',
            'default' => propOn($prop['branchDefault'] ?? $prop['default'] ?? false),
            'back' => (int) propOn($prop['back'] ?? false),
        ];
    }
    return graphAssemble($parentId, $nodes, $edges, true);
}

/**
 * Пока история ссылается на elements, исполняем сцену с полным соответствием
 * идентификаторов. Топология и правила берутся из новой сцены.
 */
function engineHistoryGraph(int $scopeId, int $parentId, int $folderId): array
{
    $scene = engineBlockGraph($scopeId, $parentId);
    $blocks = $arrows = [];
    foreach (dbAll("SELECT r.block_id, r.arrow_id, e.id, e.`no`, e.type, e.agent_id, e.target_folder_id
                     FROM graph_legacy_refs r JOIN elements e ON e.id = r.legacy_id AND e.project_id = r.scope_id
                    WHERE r.scope_id = ? AND r.legacy_kind = 'element' AND e.folder_id = ?", [$scopeId, $folderId]) as $row) {
        if ($row['block_id'] !== null) $blocks[(int) $row['block_id']][] = $row;
        if ($row['arrow_id'] !== null) $arrows[(int) $row['arrow_id']][] = $row;
    }

    $nodes = $edges = $ids = [];
    foreach ($scene['nodes'] as $id => $node) {
        $refs = $blocks[$id] ?? [];
        if (count($refs) !== 1 || $refs[0]['type'] !== $node['type']) throw graphHistoryError('block', $id);
        $legacy = $refs[0];
        $ids[$id] = (int) $legacy['id'];
        $nodes[] = array_replace($node, [
            'block_id' => $id, 'id' => $ids[$id], 'no' => $legacy['no'], 'folder_id' => $folderId,
            'agent_id' => $legacy['agent_id'], 'target_folder_id' => $legacy['target_folder_id'],
        ]);
    }
    foreach ($scene['edges'] as $id => $edge) {
        $refs = $arrows[$id] ?? [];
        if (count($refs) !== 1 || $refs[0]['type'] !== 'arrow') throw graphHistoryError('arrow', $id);
        $edges[] = array_replace($edge, [
            'arrow_id' => $id, 'id' => (int) $refs[0]['id'], 'no' => (int) $refs[0]['no'],
            'from_id' => $ids[$edge['from_id']], 'to_id' => $ids[$edge['to_id']],
        ]);
    }
    // История старых прогонов и порядок выдачи используют прежние идентификаторы.
    usort($nodes, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    usort($edges, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    return graphAssemble($folderId, $nodes, $edges, true);
}

function graphHistoryError(string $kind, int $id): ApiError
{
    $say = langAgents() === 'en' ? 'Run history requires a legacy mapping' : 'A legacy link is required for this run history';
    return new ApiError("$say ($kind $id)", 'conflict', ['code2' => 'history_ref_required', 'kind' => $kind, 'id' => $id]);
}

function graphProperties($value): array
{
    if (is_array($value)) return $value;
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

/** Общая форма графа. Стрелка исполнима только между двумя узлами данной сцены. */
function graphAssemble(int $folderId, array $nodes, array $edges, bool $modern): array
{
    $graph = ['folder' => $folderId, 'nodes' => [], 'edges' => [], 'in' => [], 'out' => [], 'modern' => $modern];
    foreach ($nodes as $row) {
        $id = (int) $row['id'];
        $row['props'] = graphRules($row['props'], $bad);
        if ($bad) $row['bad'] = $bad;
        $graph['nodes'][$id] = $row;
        $graph['in'][$id] = [];
        $graph['out'][$id] = [];
    }
    foreach ($edges as $edge) {
        $id = (int) $edge['id'];
        [$from, $to] = [(int) $edge['from_id'], (int) $edge['to_id']];
        if (!isset($graph['nodes'][$from], $graph['nodes'][$to])) continue;
        $graph['edges'][$id] = $edge;
        $graph['out'][$from][] = $id;
        $graph['in'][$to][] = $id;
    }
    return $graph;
}

/**
 * Правила узла в одном виде — для предпроверки и для хода:
 *   start — флажок: включён только true, 1 или '1' (propOn), как в folderStarters;
 *   join  — all или any; другое значение правилом не считается: уходит в $bad,
 *           предпроверка называет его, а ход берёт значение по умолчанию (all).
 */
function graphRules(array $props, ?array &$bad = null): array
{
    $bad = [];
    if (array_key_exists('start', $props)) $props['start'] = propOn($props['start']);
    if (array_key_exists('join', $props) && !in_array($props['join'], ['all', 'any'], true)) {
        $bad['join'] = $props['join'];
        unset($props['join']);
    }
    return $props;
}

/** Стартер папки в графе (блок с флажком start) — id или null. */
function graphStarter(array $graph): ?int
{
    foreach ($graph['nodes'] as $id => $node) {
        if ($node['type'] === 'block' && ($node['props']['start'] ?? false) === true) return (int) $id;
    }
    return null;
}

/**
 * Входы схемы — одно правило для предпроверки, старта и хода (readyList).
 * Есть стартер — вход он и только он; нет — узлы без прямых входящих стрелок.
 * Отдаёт id узлов по возрастанию номера.
 */
function graphEntries(array $graph): array
{
    $starter = graphStarter($graph);
    if ($starter !== null) return [$starter];

    $out = [];
    foreach ($graph['nodes'] as $id => $node) {
        if (!graphIn($graph, (int) $id, false)) $out[(int) $node['no']] = (int) $id;
    }
    ksort($out);
    return array_values($out);
}

/** Предел выдач блока в круге без своего max_attempts: прогон не должен крутиться вечно. */
const LOOP_ATTEMPTS = 20;

/** Стоит ли узел в круге: по стрелкам (и возвратным) из него можно вернуться в него же. */
function graphInLoop(array $graph, int $id): bool
{
    $queue = [$id];
    $seen = [];
    while ($queue) {
        $node = array_shift($queue);
        foreach ($graph['out'][$node] ?? [] as $edgeId) {
            $to = (int) $graph['edges'][$edgeId]['to_id'];
            if ($to === $id) return true;
            if (isset($seen[$to])) continue;
            $seen[$to] = true;
            $queue[] = $to;
        }
    }
    return false;
}

/**
 * Предел выдач узла: свойство max_attempts; у узла в круге без него —
 * LOOP_ATTEMPTS (20). 0 — предела нет.
 */
function graphMaxAttempts(array $graph, int $id): int
{
    $max = (int) graphProp($graph, $id, 'max_attempts', 0);
    if ($max > 0) return $max;
    return graphInLoop($graph, $id) ? LOOP_ATTEMPTS : 0;
}

/** Правило узла с запасным значением — как runProp(), только без запроса. */
function graphProp(array $graph, int $nodeId, string $name, $fallback)
{
    $props = $graph['nodes'][$nodeId]['props'] ?? [];
    return array_key_exists($name, $props) ? $props[$name] : $fallback;
}

/**
 * Входящие стрелки узла.
 * $back: null — все, false — только прямые, true — только возвратные.
 */
function graphIn(array $graph, int $nodeId, ?bool $back = null): array
{
    $out = [];
    foreach ($graph['in'][$nodeId] ?? [] as $edgeId) {
        $isBack = (int) $graph['edges'][$edgeId]['back'] === 1;
        if ($back === null || $back === $isBack) $out[] = $edgeId;
    }
    return $out;
}

/** Выходящие стрелки узла — все, включая возвратные. */
function graphOut(array $graph, int $nodeId): array
{
    return $graph['out'][$nodeId] ?? [];
}
