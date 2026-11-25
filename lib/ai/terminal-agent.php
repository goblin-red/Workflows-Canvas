<?php
declare(strict_types=1);

const AI_AGENT_INVITE_SECONDS = 600;
const AI_AGENT_ONLINE_SECONDS = 45;
const AI_AGENT_TOKEN_IDLE_SECONDS = 604800;
const AI_AGENT_TASK_SECONDS = 180;
const AI_AGENT_WAIT_SECONDS = 20;
const AI_AGENT_MAX_BYTES = 262144;

function aiAgentAvailable(): bool
{
    static $available;
    return $available ??= (bool) dbValue("SELECT 1 FROM schema_migrations WHERE name = '037-ai-terminal-agent.sql'");
}

/** Привязку меняет только человек со своей сессией. */
function aiAgentAccountId(): ?int
{
    if (request()['token'] !== '') return null;
    $who = caller();
    return $who['scope'] === 'session' && $who['user_id'] ? (int) $who['user_id'] : null;
}

function aiAgentStatusRow(?int $userId): array
{
    $agent = $userId && aiAgentAvailable()
        ? dbRow('SELECT * FROM ai_terminal_agents WHERE user_id = ?', [$userId])
        : null;
    $target = null;
    if (($agent['scope'] ?? null) === 'scheme' && !empty($agent['scope_project_id'])) {
        try {
            $target = aiAgentTargetInfo($userId, aiAgentResolveTarget($userId, aiAgentStoredTarget($agent, 'scope')));
        } catch (ApiError) {
            // Схема могла быть удалена или сменить владельца после подключения.
        }
    }
    return [
        'account' => $userId !== null,
        'provider' => $agent['provider'] ?? 'builtin',
        'name' => $agent['name'] ?? null,
        'scope' => $agent['scope'] ?? 'scheme',
        'target' => $target,
        'connected' => aiAgentConnected($agent),
        'online' => aiAgentOnline($agent),
    ];
}

function aiAgentStatus(): void
{
    reply(aiAgentStatusRow(aiAgentAccountId()));
}

function aiAgentTargetId($value): ?int
{
    if ($value === null) return null;
    if (!is_int($value) || $value < 1) throw new ApiError(t('server.terminal_agent.bad_target'));
    return $value;
}

function aiAgentStoredTarget(array $row, string $prefix): array
{
    $prefix = $prefix === '' ? '' : $prefix . '_';
    return [
        'projectId' => isset($row[$prefix . 'project_id']) ? (int) $row[$prefix . 'project_id'] : null,
        'parentId' => isset($row[$prefix . 'parent_id']) ? (int) $row[$prefix . 'parent_id'] : null,
        'folderId' => isset($row[$prefix . 'folder_id']) ? (int) $row[$prefix . 'folder_id'] : null,
    ];
}

/** Сопоставить адреса одной схемы и проверить её владельца на момент операции. */
function aiAgentResolveTarget(int $userId, array $target): array
{
    $scope = aiAgentTargetId($target['projectId'] ?? null);
    $parent = aiAgentTargetId($target['parentId'] ?? null);
    $folder = aiAgentTargetId($target['folderId'] ?? null);
    $lock = dbInTransaction() ? ' FOR UPDATE' : '';
    if ($scope === null || !dbValue('SELECT id FROM projects WHERE id = ? AND owner_id = ?' . $lock, [$scope, $userId])) {
        throw new ApiError(t('server.terminal_agent.target_forbidden'), 'scope');
    }
    if ($parent !== null && !dbValue('SELECT 1 FROM blocks WHERE id = ? AND scope_id = ?', [$parent, $scope])) {
        throw new ApiError(t('server.terminal_agent.target_forbidden'), 'scope');
    }
    if ($folder !== null) {
        if (!dbValue('SELECT 1 FROM folders WHERE id = ? AND project_id = ?', [$folder, $scope])) {
            throw new ApiError(t('server.terminal_agent.target_forbidden'), 'scope');
        }
        $mapped = dbValue("SELECT b.id FROM graph_legacy_refs g JOIN blocks b ON b.id = g.block_id AND b.scope_id = g.scope_id
                           WHERE g.scope_id = ? AND g.legacy_kind = 'folder' AND g.legacy_id = ?
                             AND b.kind IN ('folder','scheme')", [$scope, $folder]);
        if ($parent !== null && ($mapped === null || (int) $mapped !== $parent)) {
            throw new ApiError(t('server.terminal_agent.bad_target'));
        }
        $parent = $mapped === null ? null : (int) $mapped;
    } elseif ($parent !== null) {
        $mapped = dbValue("SELECT f.id FROM graph_legacy_refs g JOIN folders f ON f.id = g.legacy_id AND f.project_id = g.scope_id
                           WHERE g.scope_id = ? AND g.legacy_kind = 'folder' AND g.block_id = ?
                           ORDER BY f.id LIMIT 1", [$scope, $parent]);
        $folder = $mapped === null ? null : (int) $mapped;
    }
    return ['projectId' => $scope, 'parentId' => $parent, 'folderId' => $folder];
}

/** Машина не получает ключ проекта. Доступ только к проектам её владельца. */
function aiAgentAuthorizeTarget(array $connection, array $target): array
{
    $userId = (int) $connection['user_id'];
    $target = aiAgentResolveTarget($userId, $target);
    if ($connection['scope'] === 'account') return $target;
    $granted = aiAgentResolveTarget($userId, aiAgentStoredTarget($connection, 'scope'));
    if ($target !== $granted || ($target['parentId'] === null && $target['folderId'] === null)) {
        throw new ApiError(t('server.terminal_agent.target_forbidden'), 'scope');
    }
    return $target;
}

/** Только для кабинета, которому ключ проекта уже доступен. */
function aiAgentTargetInfo(int $userId, array $target): ?array
{
    $scope = $target['projectId'];
    $parent = $target['parentId'];
    $folder = $target['folderId'];
    $project = dbRow("SELECT p.url_key, COALESCE(NULLIF(b.title,''), NULLIF(p.title,''), p.url_key) AS title
        FROM projects p LEFT JOIN graph_legacy_refs g ON g.scope_id = p.id AND g.legacy_kind = 'project' AND g.legacy_id = p.id
        LEFT JOIN blocks b ON b.id = g.block_id AND b.scope_id = p.id WHERE p.id = ? AND p.owner_id = ?", [$scope, $userId]);
    $title = $parent !== null ? dbValue('SELECT title FROM blocks WHERE id = ? AND scope_id = ?', [$parent, $scope])
        : dbValue('SELECT name FROM folders WHERE id = ? AND project_id = ?', [$folder, $scope]);
    if ($project === null || $title === null) return null;
    $info = ['project' => $project['url_key'], 'title' => (string) $title, 'projectTitle' => $project['title']];
    if ($parent !== null) $info['parent'] = $parent;
    if ($folder !== null) $info['folder'] = $folder;
    return $info;
}

function aiAgentTargets(): void
{
    $userId = aiAgentAccountId();
    if ($userId === null) throw new ApiError(t('server.ai.need_login'), 'unauthorized');
    if (!aiAgentAvailable()) reply(['targets' => []]);
    $rows = dbAll("SELECT f.project_id, f.id AS folder_id, b.id AS parent_id
          FROM folders f JOIN projects p ON p.id = f.project_id
          LEFT JOIN graph_legacy_refs g ON g.scope_id = f.project_id AND g.legacy_kind = 'folder' AND g.legacy_id = f.id
          LEFT JOIN blocks b ON b.id = g.block_id AND b.scope_id = f.project_id AND b.kind IN ('folder','scheme')
          WHERE p.owner_id = ?
        UNION ALL
        SELECT b.scope_id, NULL, b.id FROM blocks b JOIN projects p ON p.id = b.scope_id
          WHERE p.owner_id = ? AND b.kind IN ('folder','scheme')
            AND NOT EXISTS (SELECT 1 FROM graph_legacy_refs g JOIN folders f ON f.id = g.legacy_id AND f.project_id = g.scope_id
                             WHERE g.scope_id = b.scope_id AND g.legacy_kind = 'folder' AND g.block_id = b.id)",
        [$userId, $userId]);
    $targets = array_values(array_filter(array_map(
        static fn(array $row): ?array => aiAgentTargetInfo($userId, aiAgentStoredTarget($row, '')),
        $rows
    )));
    usort($targets, static fn(array $a, array $b): int => [$a['projectTitle'], $a['title']] <=> [$b['projectTitle'], $b['title']]);
    reply(['targets' => $targets]);
}

function aiAgentOnline(?array $agent): bool
{
    if (!aiAgentConnected($agent)) return false;
    $now = time();
    return (int) $agent['last_seen_at'] > $now - AI_AGENT_ONLINE_SECONDS
        || (bool) dbValue("SELECT 1 FROM ai_terminal_tasks WHERE user_id = ? AND state = 'leased' AND deadline_at > ? LIMIT 1",
            [$agent['user_id'], $now]);
}

function aiAgentConnected(?array $agent): bool
{
    return !empty($agent['token_hash']) && (int) ($agent['last_seen_at'] ?? 0) > time() - AI_AGENT_TOKEN_IDLE_SECONDS;
}

function aiAgentEnabled(?int $userId): bool
{
    if ($userId === null || !aiAgentAvailable()) return false;
    return dbValue('SELECT provider FROM ai_terminal_agents WHERE user_id = ?', [$userId]) === 'agent';
}

/** Один замок учётной записи также защищает первое создание строки настройки. */
function aiAgentAccountChange(callable $change): void
{
    $userId = aiAgentAccountId();
    if ($userId === null) throw new ApiError(t('server.ai.need_login'), 'unauthorized');
    if (!aiAgentAvailable()) throw new ApiError(t('server.terminal_agent.not_installed'), 'conflict');
    $result = dbTransaction(static function () use ($userId, $change): array {
        if (!dbValue('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId])) {
            throw new ApiError(t('server.ai.need_login'), 'unauthorized');
        }
        $agent = dbRow('SELECT * FROM ai_terminal_agents WHERE user_id = ? FOR UPDATE', [$userId]);
        if ($agent === null) {
            dbRun('INSERT INTO ai_terminal_agents (user_id) VALUES (?)', [$userId]);
            $agent = ['user_id' => $userId, 'token_hash' => null];
        }
        return $change($agent);
    });
    reply($result);
}

function aiAgentInvite(): void
{
    $body = request()['body'];
    $scope = $body['scope'] ?? 'scheme';
    if (!in_array($scope, ['scheme', 'account'], true)) throw new ApiError(t('server.terminal_agent.bad_scope'));
    aiAgentAccountChange(static function (array $agent) use ($body, $scope): array {
        $target = ['projectId' => null, 'parentId' => null, 'folderId' => null];
        if ($scope === 'scheme') {
            $key = $body['project'] ?? null;
            if (!is_string($key) || !validProjectKey($key) || (!isset($body['parent']) && !isset($body['folder']))) {
                throw new ApiError(t('server.terminal_agent.bad_target'));
            }
            $projectId = dbValue('SELECT id FROM projects WHERE url_key = ? AND owner_id = ?', [$key, $agent['user_id']]);
            $target = aiAgentResolveTarget((int) $agent['user_id'], [
                'projectId' => $projectId === null ? null : (int) $projectId,
                'parentId' => $body['parent'] ?? null, 'folderId' => $body['folder'] ?? null,
            ]);
            if ($target['parentId'] !== null && graphRootId($target['projectId']) === $target['parentId']) {
                throw new ApiError(t('server.terminal_agent.bad_target'));
            }
        }
        $code = 'gai_pair_' . bin2hex(random_bytes(16));
        $expires = time() + AI_AGENT_INVITE_SECONDS;
        dbRun('UPDATE ai_terminal_agents SET invite_hash = ?, invite_expires_at = ?, invite_scope = ?,
               invite_project_id = ?, invite_parent_id = ?, invite_folder_id = ? WHERE user_id = ?',
            [hashSecret($code), $expires, $scope, $target['projectId'], $target['parentId'], $target['folderId'], $agent['user_id']]);
        return ['code' => $code, 'expiresAt' => gmdate('c', $expires)];
    });
}

function aiAgentSelect(): void
{
    $provider = request()['body']['provider'] ?? null;
    if (!in_array($provider, ['builtin', 'agent'], true)) throw new ApiError(t('server.terminal_agent.bad_provider'));
    aiAgentAccountChange(static function (array $agent) use ($provider): array {
        if ($provider === 'agent' && !aiAgentConnected($agent)) {
            throw new ApiError(t('server.terminal_agent.disconnected'), 'conflict');
        }
        dbRun('UPDATE ai_terminal_agents SET provider = ? WHERE user_id = ?', [$provider, $agent['user_id']]);
        if ($provider === 'builtin') aiAgentDiscard((int) $agent['user_id']);
        return ['provider' => $provider];
    });
}

function aiAgentRename(): void
{
    $name = request()['body']['name'] ?? null;
    $name = is_string($name) ? trim($name) : '';
    if ($name === '' || mb_strlen($name) > 80) throw new ApiError(t('server.terminal_agent.bad_name'));
    aiAgentAccountChange(static function (array $agent) use ($name): array {
        dbRun('UPDATE ai_terminal_agents SET name = ? WHERE user_id = ?', [$name, $agent['user_id']]);
        return ['name' => $name];
    });
}

function aiAgentDisconnect(): void
{
    aiAgentAccountChange(static function (array $agent): array {
        dbRun("UPDATE ai_terminal_agents SET provider = 'builtin', scope = 'scheme',
               scope_project_id = NULL, scope_parent_id = NULL, scope_folder_id = NULL,
               invite_hash = NULL, invite_expires_at = NULL, invite_scope = NULL,
               invite_project_id = NULL, invite_parent_id = NULL, invite_folder_id = NULL,
               token_hash = NULL, last_seen_at = NULL WHERE user_id = ?", [$agent['user_id']]);
        aiAgentDiscard((int) $agent['user_id']);
        return ['provider' => 'builtin'];
    });
}

/** Закрыть незаконченные задания под тем же замком, что и смена агента. */
function aiAgentDiscard(int $userId): void
{
    dbRun("UPDATE ai_terminal_tasks SET state = 'failed', lease_hash = NULL, messages = NULL
           WHERE user_id = ? AND state IN ('queued','leased')", [$userId]);
}

function aiAgentConnect(): void
{
    $code = request()['body']['code'] ?? null;
    if (!aiAgentAvailable() || !is_string($code) || !preg_match('/\Agai_pair_[0-9a-f]{32}\z/D', $code)) {
        throw new ApiError(t('server.terminal_agent.bad_invite'), 'unauthorized');
    }
    $connected = dbTransaction(static function () use ($code): array {
        $agent = dbRow('SELECT * FROM ai_terminal_agents WHERE invite_hash = ? FOR UPDATE',
            [hashSecret($code)]);
        if ($agent === null || (int) $agent['invite_expires_at'] <= time()) {
            throw new ApiError(t('server.terminal_agent.bad_invite'), 'unauthorized');
        }
        $scope = $agent['invite_scope'];
        if (!in_array($scope, ['scheme', 'account'], true)) throw new ApiError(t('server.terminal_agent.bad_invite'), 'unauthorized');
        $target = aiAgentStoredTarget($agent, 'invite');
        if ($scope === 'scheme') $target = aiAgentResolveTarget((int) $agent['user_id'], $target);
        $token = newSecret('gai_');
        dbRun("UPDATE ai_terminal_agents SET provider = 'agent', scope = ?,
               scope_project_id = ?, scope_parent_id = ?, scope_folder_id = ?,
               invite_hash = NULL, invite_expires_at = NULL, invite_scope = NULL,
               invite_project_id = NULL, invite_parent_id = NULL, invite_folder_id = NULL,
               token_hash = ?, last_seen_at = ? WHERE user_id = ?",
            [$scope, $target['projectId'], $target['parentId'], $target['folderId'],
             hashSecret($token), time(), $agent['user_id']]);
        aiAgentDiscard((int) $agent['user_id']);
        return ['token' => $token, 'scope' => $scope];
    });
    reply($connected);
}

function aiAgentTerminalHash(): string
{
    $token = (string) ($_SERVER['HTTP_X_GOBLIN_AI_AGENT'] ?? '');
    if (!aiAgentAvailable() || !preg_match('/\Agai_[0-9a-f]{48}\z/D', $token)) {
        throw new ApiError(t('server.terminal_agent.bad_token'), 'unauthorized');
    }
    return hashSecret($token);
}

function aiAgentTerminal(string $hash, bool $lock = false): array
{
    $agent = dbRow('SELECT user_id, provider, scope, scope_project_id, scope_parent_id, scope_folder_id
        FROM ai_terminal_agents WHERE token_hash = ? AND last_seen_at > ?'
        . ($lock ? ' FOR UPDATE' : ''), [$hash, time() - AI_AGENT_TOKEN_IDLE_SECONDS]);
    if ($agent === null) throw new ApiError(t('server.terminal_agent.bad_token'), 'unauthorized');
    return $agent;
}

function aiAgentMachine(bool $lock = false): array
{
    return aiAgentTerminal(aiAgentTerminalHash(), $lock);
}

function aiAgentNextTask(int $userId): ?array
{
    return dbRow("SELECT id, kind, messages, project_id, parent_id, folder_id FROM ai_terminal_tasks
                   WHERE user_id = ? AND state = 'queued' AND deadline_at > ?
                   ORDER BY id LIMIT 1", [$userId, time()]);
}

function aiAgentTake(): void
{
    $hash = aiAgentTerminalHash();
    $wait = request()['body']['wait'] ?? 0;
    if (!is_int($wait) || $wait < 0 || $wait > AI_AGENT_WAIT_SECONDS) {
        throw new ApiError(t('server.terminal_agent.bad_wait'));
    }

    $agent = aiAgentTerminal($hash);
    $userId = (int) $agent['user_id'];
    dbRun('UPDATE ai_terminal_agents SET last_seen_at = ? WHERE user_id = ? AND token_hash = ?', [time(), $userId, $hash]);
    dbRun('DELETE FROM ai_terminal_tasks WHERE user_id = ? AND deadline_at <= ?', [$userId, time() - 60]);
    $until = microtime(true) + $wait;
    do {
        $agent = aiAgentTerminal($hash);
        if ($agent['provider'] === 'agent' && aiAgentNextTask($userId) !== null) {
            $task = dbTransaction(static function () use ($hash, $userId): ?array {
                $agent = aiAgentTerminal($hash, true);
                if ($agent['provider'] !== 'agent' || ($row = aiAgentNextTask($userId)) === null) return null;
                if ($row['project_id'] !== null) {
                    try {
                        aiAgentAuthorizeTarget($agent, aiAgentStoredTarget($row, ''));
                    } catch (ApiError) {
                        dbRun("UPDATE ai_terminal_tasks SET state = 'failed', messages = NULL WHERE id = ?", [$row['id']]);
                        return null;
                    }
                }
                $lease = newSecret('gai_lease_');
                $changed = dbRun("UPDATE ai_terminal_tasks SET state = 'leased', lease_hash = ?, messages = NULL
                                   WHERE id = ? AND state = 'queued' AND deadline_at > ?", [hashSecret($lease), $row['id'], time()]);
                if ($changed !== 1) return null;
                return ['id' => (int) $row['id'], 'lease' => $lease, 'kind' => $row['kind'],
                    'messages' => json_decode($row['messages'], true, 512, JSON_THROW_ON_ERROR)];
            });
            if ($task !== null) reply(['task' => $task]);
        }
        if (microtime(true) >= $until) break;
        usleep(250000);
    } while (true);
    reply(['task' => null]);
}

function aiAgentFinish(): void
{
    $hash = aiAgentTerminalHash();
    $body = request()['body'];
    $id = $body['id'] ?? null;
    $lease = $body['lease'] ?? null;
    if (!is_int($id) || $id < 1 || !is_string($lease) || !preg_match('/\Agai_lease_[0-9a-f]{48}\z/D', $lease)) {
        throw new ApiError(t('server.terminal_agent.bad_finish'));
    }

    $data = $body['data'] ?? null;
    $error = $body['error'] ?? null;
    $success = is_array($data) && !array_is_list($data) && !array_key_exists('error', $body);
    $failed = is_string($error) && trim($error) !== '' && strlen($error) <= 2048 && !array_key_exists('data', $body);
    if (!$success && !$failed) throw new ApiError(t('server.terminal_agent.bad_finish'));
    $response = $success ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
    if ($response !== null && strlen($response) > AI_AGENT_MAX_BYTES) throw new ApiError(t('server.terminal_agent.too_large'));

    dbTransaction(static function () use ($hash, $id, $lease, $response, $success): void {
        $agent = aiAgentTerminal($hash, true);
        $task = dbRow('SELECT state, lease_hash, deadline_at, project_id, parent_id, folder_id
                       FROM ai_terminal_tasks WHERE id = ? AND user_id = ? FOR UPDATE',
            [$id, $agent['user_id']]);
        if (!$task || $agent['provider'] !== 'agent' || (int) $task['deadline_at'] <= time()
            || !hash_equals((string) $task['lease_hash'], hashSecret($lease))) {
            throw new ApiError(t('server.terminal_agent.stale_task'), 'conflict');
        }
        if ($task['project_id'] !== null) aiAgentAuthorizeTarget($agent, aiAgentStoredTarget($task, ''));
        dbRun('UPDATE ai_terminal_agents SET last_seen_at = ? WHERE user_id = ?', [time(), $agent['user_id']]);
        if ($task['state'] !== 'leased') return;  // Повтор подтверждает первое завершение.
        dbRun("UPDATE ai_terminal_tasks SET state = ?, response = ? WHERE id = ? AND state = 'leased'",
            [$success ? 'done' : 'failed', $response, $id]);
    });
    reply();
}

/** Очередь получает только сообщения модели. Пропуск проекта ей не нужен. */
function aiAgentAsk(int $userId, array $messages, string $kind, ?array $target = null): array
{
    if (!aiAgentAvailable()) throw new ApiError(t('server.terminal_agent.not_installed'), 'conflict');
    if (dbInTransaction()) throw new LogicException('Terminal agent cannot wait inside a database transaction');
    if (!preg_match('/\A[a-z][a-z0-9._-]{0,31}\z/D', $kind)) throw new InvalidArgumentException('Invalid terminal agent task kind');

    $until = time() + AI_AGENT_TASK_SECONDS;
    [$id, $scope] = dbTransaction(static function () use ($userId, $kind, $messages, $until, $target): array {
        $agent = dbRow('SELECT * FROM ai_terminal_agents WHERE user_id = ? FOR UPDATE', [$userId]);
        if (($agent['provider'] ?? null) !== 'agent' || !aiAgentOnline($agent)) {
            throw new ApiError(t('server.terminal_agent.offline'), 'conflict');
        }
        if ($target !== null || $agent['scope'] !== 'account') {
            $target = aiAgentAuthorizeTarget($agent, $target ?? []);
            $projectKey = (string) dbValue('SELECT url_key FROM projects WHERE id = ?', [$target['projectId']]);
            if ($projectKey !== '') {
                foreach ($messages as &$message) {
                    if (is_string($message['content'] ?? null)) {
                        $message['content'] = str_replace($projectKey, '[project]', $message['content']);
                    }
                }
                unset($message);
            }
        }
        $content = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($content) > AI_AGENT_MAX_BYTES) throw new ApiError(t('server.terminal_agent.too_large'));
        dbRun('DELETE FROM ai_terminal_tasks WHERE user_id = ? AND deadline_at <= ?', [$userId, time() - 60]);
        dbRun('INSERT INTO ai_terminal_tasks (user_id, kind, project_id, parent_id, folder_id, messages, deadline_at) VALUES (?,?,?,?,?,?,?)',
            [$userId, $kind, $target['projectId'] ?? null, $target['parentId'] ?? null, $target['folderId'] ?? null, $content, $until]);
        return [dbId(), $agent['scope']];
    });

    do {
        $task = dbRow('SELECT state, response FROM ai_terminal_tasks WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($task === null || $task['state'] === 'failed') throw new ApiError(t('server.terminal_agent.failed'), 'conflict');
        if ($task['state'] === 'done') {
            $raw = (string) $task['response'];
            dbRun('UPDATE ai_terminal_tasks SET response = NULL WHERE id = ? AND user_id = ?', [$id, $userId]);
            return ['data' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR), 'raw' => $raw, 'think' => '', 'jsonFailure' => null,
                'model' => 'terminal-agent', 'agentScope' => $scope,
                'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0, 'cached' => 0, 'known' => false]];
        }
        if (time() >= $until) {
            if (dbRun("UPDATE ai_terminal_tasks SET state = 'failed', messages = NULL, lease_hash = NULL
                       WHERE id = ? AND user_id = ? AND state IN ('queued','leased')", [$id, $userId])) {
                throw new ApiError(t('server.terminal_agent.timeout'), 'conflict');
            }
            continue;  // Агент мог успеть закончить между чтением и обновлением.
        }
        usleep(250000);
    } while (true);
}
