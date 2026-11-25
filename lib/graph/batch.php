<?php
declare(strict_types=1);

const GRAPH_WRITE_OPS = [
    'block.create', 'block.update', 'block.content', 'block.delete',
    'arrow.create', 'arrow.update', 'arrow.delete',
    'placement.create', 'placement.update', 'placement.delete',
    'graph.asset.add', 'graph.template.apply',
];

/** Для графа открытие проекта и первая правка входят в одну транзакцию. */
function graphRunBatch(array $ops): void
{
    $ops = graphContentRequestOperations($ops);
    $who = caller();
    $key = trim(request()['project']);
    $scopeId = $who['project_id'];

    $write = static function () use ($scopeId, $key, $ops): array {
        return dbTransaction(static function () use ($scopeId, $key, $ops): array {
            // Первый обычный SELECT прав и содержимого должен увидеть состояние после взятия замка.
            if ($scopeId !== null) {
                dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$scopeId]);
            } elseif (validProjectKey($key)) {
                dbRow('SELECT id FROM projects WHERE url_key = ? FOR UPDATE', [$key]);
            }
            $project = requireProject(true);
            if (array_intersect(array_column($ops, 'op'), OWNER_OPS)) requireOwner($project);
            $root = graphRootId((int) $project['id']);
            foreach ($ops as $op) {
                $id = $op['id'] ?? null;
                if (in_array($op['op'], ['block.update', 'block.delete'], true)
                    && ($id === 'root' || ($root !== null && (is_int($id) || is_string($id))
                        && filter_var($id, FILTER_VALIDATE_INT) === $root))) {
                    requireOwner($project);
                }
            }

            return runBatch($project, $ops, static function (array $op, array &$ctx): array {
                $handler = opRule($op['op'])[1];
                if (in_array($op['op'], GRAPH_WRITE_OPS, true)) {
                    $ctx['graphRoot'] ??= graphEnsureRoot($ctx['projectId'], $ctx['rev']);
                    graphValidateSecrets($op);
                    if (array_key_exists('scopeId', $op) && graphId($op['scopeId']) !== $ctx['projectId']) {
                        throw new ApiError(t('server.graph.operation_scope'), 'scope');
                    }
                    return $handler($op, $ctx);
                }

                // runBatch ведёт общий список refs. Старые операции должны видеть только старые ID.
                $ctx['refs'] = $ctx['legacyRefs'] ?? [];
                $result = $handler($op, $ctx);
                if (isset($op['ref'], $result['id'])) $ctx['legacyRefs'][(string) $op['ref']] = (int) $result['id'];
                return $result;
            }, false);
        });
    };

    for ($attempt = 1; ; $attempt++) {
        try {
            $out = $write();
            break;
        } catch (PDOException $e) {
            // Два первых запроса к новому ключу могут пересечься на INSERT в MariaDB.
            if ($attempt >= 3 || $scopeId !== null || (int) ($e->errorInfo[1] ?? 0) !== 1213) throw $e;
        }
    }
    reply(['rev' => $out['rev'], 'results' => $out['results']] + (empty($out['repeat']) ? [] : ['repeat' => true]), $out['warnings']);
}

/** Вложенные поля секрета и выданные ключи не должны попасть ни в граф, ни в журнал правок. */
function graphValidateSecrets(array $data): void
{
    foreach ($data as $key => $value) {
        $name = is_string($key) ? strtolower(str_replace(['_', '-'], '', $key)) : '';
        if (in_array($name, ['password', 'passwd', 'passphrase', 'token', 'accesstoken', 'refreshtoken', 'apikey', 'secret'], true)
            || (is_string($value) && preg_match('/\b(?:gb[alrst]_[A-Za-z0-9_-]{24,}|apikey_[A-Za-z0-9_]+|sk-[A-Za-z0-9_-]{10,})/', $value))) {
            throw new ApiError(t('server.graph.secrets'));
        }
        if (is_array($value)) graphValidateSecrets($value);
        if ($value instanceof stdClass) graphValidateSecrets((array) $value);
    }
}
