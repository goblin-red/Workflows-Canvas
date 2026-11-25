<?php
declare(strict_types=1);

const AI_GRAPH_MAX_BLOCKS = 60;
const AI_GRAPH_MAX_ARROWS = 120;

/** Context for the model. Only bounded, explicitly selected graph fields leave the server. */
function aiGraphScene(int $scope, int $parent): array
{
    $head = dbRow('SELECT id, kind, title FROM blocks WHERE scope_id = ? AND id = ?', [$scope, $parent]);
    if (!$head) throw new ApiError(t('server.graph_assistant.scene_missing'), 'not_found');
    $rows = dbAll("SELECT b.id, b.kind, b.title, SUBSTR(b.description, 1, 2048) AS description
                    FROM arrows a JOIN blocks b ON b.scope_id = a.scope_id AND b.id = a.target_id
                   WHERE a.scope_id = ? AND a.source_id = ? AND a.kind = 'contains'
                   ORDER BY a.sort, a.id LIMIT " . (AI_GRAPH_MAX_BLOCKS + 1), [$scope, $parent]);
    $truncated = count($rows) > AI_GRAPH_MAX_BLOCKS;
    $blocks = array_map(static function (array $row): array {
        $block = aiGraphSceneBlock($row);
        if ($row['description'] !== '' && !in_array($row['kind'], ['asset', 'material'], true)) {
            $block['description'] = aiGraphSafeText($row['description'], 240);
        }
        return $block;
    }, array_slice($rows, 0, AI_GRAPH_MAX_BLOCKS));

    $ids = [$parent, ...array_column($blocks, 'id')];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = dbAll("SELECT id, source_id, target_id, kind, direction,
                           JSON_UNQUOTE(JSON_EXTRACT(properties, '$.branchKey')) AS branch_key,
                           JSON_UNQUOTE(JSON_EXTRACT(properties, '$.branchLabel')) AS label
                    FROM arrows WHERE scope_id = ? AND source_id IN ($marks) AND target_id IN ($marks)
                    ORDER BY sort, id LIMIT " . (AI_GRAPH_MAX_ARROWS + 1), [$scope, ...$ids, ...$ids]);
    $arrows = array_map(static function (array $row): array {
        $arrow = ['id' => (int) $row['id'], 'sourceId' => (int) $row['source_id'],
            'targetId' => (int) $row['target_id'], 'kind' => $row['kind'], 'direction' => $row['direction']];
        if ($row['kind'] === 'flow') {
            if (is_string($row['branch_key']) && preg_match('/\A[a-z][a-z0-9_-]{0,30}\z/D', $row['branch_key'])
                && !aiGraphUnsafeText($row['branch_key'])) {
                $arrow['branchKey'] = $row['branch_key'];
            }
            if (is_string($row['label']) && $row['label'] !== '' && $row['label'][0] !== '{' && $row['label'][0] !== '[') {
                $arrow['label'] = aiGraphSafeText($row['label'], 80);
            }
        }
        return $arrow;
    }, array_slice($rows, 0, AI_GRAPH_MAX_ARROWS));

    return [
        'parent' => aiGraphSceneBlock($head), 'blocks' => $blocks, 'arrows' => $arrows,
        'truncated' => $truncated || count($rows) > AI_GRAPH_MAX_ARROWS,
    ];
}

function aiGraphSceneBlock(array $row): array
{
    return ['id' => (int) $row['id'], 'kind' => aiGraphSafeText($row['kind'], 32),
        'title' => aiGraphSafeText($row['title'], 128)];
}

/** Detect common secrets and path-like strings in free text as well as structured fields omitted above. */
function aiGraphUnsafeText(string $value): bool
{
    if (!mb_check_encoding($value, 'UTF-8')) return true;
    try {
        graphValidateSecrets([$value]);
    } catch (ApiError) {
        return true;
    }
    return preg_match(<<<'RE'
#(?:\b(?:password|passwd|passphrase|token|api[_ -]?key|secret|пароль|токен)\s*[:=]\s*\S
   |\b(?:Bearer|Basic)\s+[A-Za-z0-9+/=_.-]{8,}
   |-----BEGIN [^-\r\n]*PRIVATE KEY-----
   |\b(?:gh[pousr]_|github_pat_|xox[baprs]-|AKIA|ASIA)[A-Za-z0-9_-]{12,}
   |\b[A-Za-z0-9_-]{32,}\b
   |\b[a-z][a-z0-9+.-]*://
   |[\#?&](?:p|project|token|key)=
   |(?<![\p{L}\p{N}])(?:/|\.{1,2}/|~/|[A-Za-z]:[/\\]|\\\\)[^\s]
   |\b(?!(?:да/нет|yes/no)\b)[\p{L}_.-][\p{L}\p{N}_.-]*[/\\][\p{L}\p{N}_.-]+)#ixu
RE, $value) !== 0;
}

function aiGraphSafeText(string $text, int $length): string
{
    return aiGraphUnsafeText($text) ? '[redacted]' : mb_substr($text, 0, $length);
}

function aiGraphJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
