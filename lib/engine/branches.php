<?php

declare(strict_types=1);

/** Исходы в форме, общей для хода, API и предпроверки. */
function graphBranches(array $graph, int $nodeId): array
{
    $out = [];
    foreach (graphOut($graph, $nodeId) as $edgeId) $out[$edgeId] = $graph['edges'][$edgeId];
    return $out;
}

/** Старые ромбы и перенесённые пары без собственных правил сохраняют бинарное условие. */
function graphBinaryBranches(array $graph, int $nodeId): bool
{
    if (empty($graph['modern'])) return true;
    $keys = [];
    foreach (graphBranches($graph, $nodeId) as $edge) {
        if (($edge['condition'] ?? '') !== '' || !empty($edge['default'])) return false;
        $keys[] = $edge['branch'];
    }
    sort($keys);
    return $keys === ['no', 'yes'];
}

/** Ручной адрес: точный ключ или ID исходящей стрелки. Если даны оба, они должны совпасть. */
function graphSelectBranch(array $graph, int $nodeId, string $key = '', ?int $edgeId = null): array
{
    $out = graphBranches($graph, $nodeId);
    if (empty($graph['modern'])) {
        $out = array_filter($out, static fn(array $edge): bool => in_array($edge['branch'], ['yes', 'no'], true));
    }
    if ($edgeId !== null) {
        $edge = null;
        foreach ($out as $candidate) {
            if ((int) ($candidate['arrow_id'] ?? $candidate['id']) !== $edgeId) continue;
            $edge = $candidate;
            break;
        }
        return $edge && ($key === '' || $edge['branch'] === $key)
            ? ['edge' => $edge, 'status' => 'ok'] : ['edge' => null, 'status' => 'unknown'];
    }
    $matches = array_values(array_filter($out, static fn(array $edge): bool => $key !== '' && $edge['branch'] === $key));
    return match (count($matches)) {
        0 => ['edge' => null, 'status' => 'unknown'],
        1 => ['edge' => $matches[0], 'status' => 'ok'],
        default => ['edge' => null, 'status' => 'ambiguous'],
    };
}

/** Результат вычисления всех правил. Невычислимое правило тоже оставляет выбор открытым. */
function graphEvaluateBranches(array $graph, int $nodeId, array $vars, string $text): array
{
    $matches = $defaults = $unresolved = [];
    foreach (graphBranches($graph, $nodeId) as $edge) {
        if ($edge['default']) $defaults[] = $edge;
        $condition = $edge['condition'];
        if (!is_string($condition)) {
            $unresolved[] = $edge['branch'];
            continue;
        }
        if (trim($condition) === '') continue;
        $result = condEval($condition, $vars, $text);
        if ($result['status'] !== 'ok') $unresolved[] = $edge['branch'];
        elseif ($result['value']) $matches[] = ['edge' => $edge, 'why' => $result['why']];
    }
    if (count($matches) > 1 || count($defaults) > 1) return ['edge' => null, 'status' => 'ambiguous'];
    if ($unresolved) return ['edge' => null, 'status' => 'unresolved'];
    if ($matches) return $matches[0] + ['status' => 'ok'];
    if ($defaults) return ['edge' => $defaults[0], 'status' => 'ok', 'why' => ''];
    return ['edge' => null, 'status' => 'unknown'];
}

function branchLabel(array $edge, string $context = 'steps'): string
{
    if (trim($edge['label'] ?? '') !== '') return $edge['label'];
    $key = (string) $edge['branch'];
    if (!in_array($key, ['yes', 'no'], true)) return $key;
    $labelKey = $context === 'go' ? 'agents.go.' . $key : 'agents.steps.word_' . $key;
    return $context === 'ui' ? t($labelKey) : ta($labelKey);
}

function branchMessage(string $status): string
{
    return match ($status) {
        'ambiguous' => 'Several branches match. Select an arrow by ID.',
        'unresolved' => 'Some branch conditions could not be evaluated.',
        'invalid' => 'Use distinct branch keys, text conditions and at most one default.',
        default => 'Select an outgoing arrow by ID or branch key.',
    };
}

/** Параметр edge проверяем как целый ID, без округлений и приведения произвольных строк. */
function branchInputEdge(): ?int
{
    $raw = input('edge');
    if ($raw === null || $raw === '') return null;
    $id = is_int($raw) || is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($id === false) throw new ApiError(branchMessage('unknown'), 'invalid');
    return $id;
}
