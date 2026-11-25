<?php
declare(strict_types=1);

require_once __DIR__ . '/plan.php';

function graphMigrationRows(PDO $db, string $sql, array $args = []): array
{
    $query = $db->prepare($sql);
    $query->execute($args);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function graphMigrationJson(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/** Compare stored JSON without relying on key order or database-specific formatting. */
function graphMigrationCanonical(mixed $value): mixed
{
    if (is_object($value)) {
        $keys = (array) $value;
        ksort($keys, SORT_STRING);
        return (object) array_map('graphMigrationCanonical', $keys);
    }
    if (is_array($value)) return array_map('graphMigrationCanonical', $value);
    return $value;
}

final class GraphMigration
{
    private array $existing = [];
    private array $rows = [];
    private array $errors = [];

    public function __construct(private PDO $db, private int $scope)
    {
    }

    public function run(bool $apply, ?string $filesDir, ?array $roots = null, ?callable $authorizeImport = null): array
    {
        $sqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite) {
            $this->db->exec($apply ? 'BEGIN IMMEDIATE' : 'BEGIN');
        } else {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->exec($apply ? 'START TRANSACTION' : 'START TRANSACTION READ ONLY');
        }
        try {
            $sql = 'SELECT id, title, lang, files_server, ai_confirm, strict_checks, rev, created_at, updated_at, owner_id, guest_write
                    FROM projects WHERE id = ?';
            if ($apply && !$sqlite) $sql .= ' FOR UPDATE';
            $project = graphMigrationRows($this->db, $sql, [$this->scope])[0] ?? null;
            if ($authorizeImport !== null) $authorizeImport($project);
            if ($project === null) throw new RuntimeException('Project not found.');
            if ($authorizeImport !== null) {
                $skip = $this->emptyImportGuard($project);
                if ($skip !== null) {
                    $this->db->exec('ROLLBACK');
                    return $skip;
                }
                // План должен записать корень под той же ревизией, что и проект.
                $this->db->prepare('UPDATE projects SET rev = rev + 1 WHERE id = ?')->execute([$this->scope]);
                $project = graphMigrationRows($this->db, $sql, [$this->scope])[0];
            }

            $plan = new GraphMigrationPlan($this->db, $this->scope, $filesDir, $roots);
            $plan->build($project);
            $this->errors = $plan->errors;
            $this->loadExisting();

            $rootKey = 'project:' . $this->scope;
            $root = $plan->blocks[$rootKey];
            $seed = $this->seedRootId($rootKey, $root);
            $counts = $plan->counts + ['rootsToInitialize' => (int) ($seed !== null), 'rootsInitialized' => 0];
            foreach (['block' => $plan->blocks, 'arrow' => $plan->arrows] as $type => $planned) {
                foreach ($planned as $key => $row) {
                    if ($key === $rootKey && $seed !== null) continue;
                    if (isset($this->existing[$type][$key])) $this->verifyRow($type, $key, $row);
                }
                foreach (array_diff_key($this->existing[$type] ?? [], $planned) as $key => $id) {
                    $this->errors[] = "$key: an earlier import has no remaining source";
                }
                $known = array_fill_keys(array_values($this->existing[$type] ?? []), true);
                foreach (array_diff_key($this->rows[$type] ?? [], $known) as $id => $row) {
                    $this->errors[] = "$type:$id: object was created outside the import; use the source copy";
                }
                $counts[$type . 'sPlanned'] = count($planned);
                $counts[$type . 'sExisting'] = count(array_intersect_key($planned, $this->existing[$type] ?? []));
                $counts[$type . 'sToCreate'] = $counts[$type . 'sPlanned'] - $counts[$type . 'sExisting'];
                $counts[$type . 'sCreated'] = 0;
            }
            if ($seed === null && $this->rows && ($counts['blocksToCreate'] || $counts['arrowsToCreate'])) {
                $this->errors[] = 'The existing graph differs from the import plan. Use the source copy.';
            }
            $this->checkContainment($plan);
            $ok = !$this->errors;
            $import = [];
            if ($ok && $apply) {
                if ($seed !== null) {
                    $this->initializeRoot($seed, $root);
                    $counts['rootsInitialized'] = 1;
                }
                foreach (['block' => $plan->blocks, 'arrow' => $plan->arrows] as $type => $planned) {
                    foreach ($planned as $key => $row) {
                        if (isset($this->existing[$type][$key])) continue;
                        try {
                            $this->insert($type, $key, $row);
                        } catch (PDOException $error) {
                            throw new RuntimeException("$key: write failed, SQLSTATE " . $error->getCode());
                        }
                        $counts[$type . 'sCreated']++;
                    }
                }
                if ($authorizeImport !== null) {
                    $import = ['imported' => true, 'rootId' => $this->existing['block'][$rootKey], 'rev' => (int) $project['rev']];
                }
            }
            $this->db->exec($ok && $apply ? 'COMMIT' : 'ROLLBACK');

            return ['scopeId' => $this->scope, 'ok' => $ok, 'counts' => $counts,
                'errors' => $this->errors, 'warnings' => $plan->warnings] + $import;
        } catch (Throwable $error) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (PDOException) {
                // Некоторые ошибки SQLite откатывают транзакцию сами.
            }
            throw $error;
        }
    }

    /** Вызывается под тем же замком проекта, что и запись графа и запуск прогона. */
    private function emptyImportGuard(array $project): ?array
    {
        $root = graphMigrationRows($this->db,
            "SELECT block_id FROM graph_legacy_refs WHERE scope_id = ? AND legacy_kind = 'project' AND legacy_id = ?",
            [$this->scope, $this->scope])[0]['block_id'] ?? null;
        if ($root !== null || graphMigrationRows($this->db, 'SELECT 1 FROM blocks WHERE scope_id = ? LIMIT 1', [$this->scope])
            || !graphMigrationRows($this->db, 'SELECT 1 FROM folders WHERE project_id = ? LIMIT 1', [$this->scope])) {
            return ['ok' => true, 'imported' => false, 'rootId' => $root === null ? null : (int) $root, 'rev' => (int) $project['rev']];
        }
        $run = graphMigrationRows($this->db,
            "SELECT id, `no` FROM runs WHERE project_id = ? AND state IN ('running','paused') ORDER BY id LIMIT 1",
            [$this->scope])[0] ?? null;
        if ($run !== null) {
            $label = 'r' . (int) $run['no'];
            throw new ApiError(t('server.guard.message', ['what' => 'import schemes into Graph', 'run' => $label]), 'conflict',
                ['code2' => 'active_run', 'active_run' => $label, 'run' => (int) $run['id']]);
        }
        return null;
    }

    /** Recognize the empty root installed by schema migration 035. */
    private function seedRootId(string $key, array $expected): ?int
    {
        $id = $this->existing['block'][$key] ?? null;
        if ($id === null) return null;
        $row = $this->rows['block'][$id];
        if ($row['kind'] === 'project' && $row['title'] === $expected['title'] && $row['description'] === ''
            && (int) $row['rev'] === $expected['rev']
            && graphMigrationJson(json_decode($row['properties'])) === '{}'
            && graphMigrationJson(json_decode($row['appearance'])) === '{}') return $id;
        return null;
    }

    private function initializeRoot(int $id, array $root): void
    {
        $statement = $this->db->prepare('UPDATE blocks SET properties = ?, created_at = ?, updated_at = ?
                                         WHERE scope_id = ? AND id = ?');
        $args = [graphMigrationJson($root['properties']), $root['created_at'], $root['updated_at'], $this->scope, $id];
        $statement->execute($args);
        // SQLite's update trigger can replace an explicitly assigned but unchanged timestamp.
        $saved = graphMigrationRows($this->db, 'SELECT updated_at FROM blocks WHERE scope_id = ? AND id = ?',
            [$this->scope, $id])[0]['updated_at'];
        if ($saved !== $root['updated_at']) $statement->execute($args);
    }

    private function loadExisting(): void
    {
        foreach (['block', 'arrow'] as $type) {
            foreach (graphMigrationRows($this->db, "SELECT * FROM {$type}s WHERE scope_id = ?", [$this->scope]) as $row) {
                $id = (int) $row['id'];
                $this->rows[$type][$id] = $row;
                $key = json_decode($row['properties'])->legacyMigrationKey ?? null;
                if (is_string($key)) $this->register($type, $key, $id);
            }
        }
        $mapped = [];
        foreach (graphMigrationRows($this->db, 'SELECT * FROM graph_legacy_refs WHERE scope_id = ?', [$this->scope]) as $row) {
            $key = $row['legacy_kind'] . ':' . $row['legacy_id'];
            if (($row['block_id'] === null) === ($row['arrow_id'] === null)) {
                $this->errors[] = "$key: expected only one mapped object";
                continue;
            }
            $type = $row['block_id'] !== null ? 'block' : 'arrow';
            $id = (int) $row[$type . '_id'];
            if (!isset($this->rows[$type][$id])) {
                $this->errors[] = "$key: mapped object does not exist in the project";
                continue;
            }
            if (isset($mapped[$type][$id])) $this->errors[] = "$key: mapped object is already assigned to {$mapped[$type][$id]}";
            $mapped[$type][$id] = $key;
            $this->register($type, $key, $id);
        }
    }

    private function register(string $type, string $key, int $id): void
    {
        if (isset($this->existing[$type][$key])) {
            $this->errors[] = "$key: duplicate migration key";
            return;
        }
        $this->existing[$type][$key] = $id;
    }

    private function verifyRow(string $type, string $key, array $expected): void
    {
        $actual = $this->rows[$type][$this->existing[$type][$key]];
        foreach ($expected as $column => $value) {
            $found = $actual[$column];
            if (in_array($column, ['source_id', 'target_id'], true)) {
                $value = $this->existing['block'][$value] ?? null;
                $found = (int) $found;
            } elseif (in_array($column, ['properties', 'appearance'], true)) {
                $value = graphMigrationJson(graphMigrationCanonical($value));
                $found = graphMigrationJson(graphMigrationCanonical(json_decode($found, false, 512, JSON_THROW_ON_ERROR)));
            } elseif (is_int($value)) {
                $found = (int) $found;
            }
            if ($found !== $value) $this->errors[] = "$key: field $column differs from its source";
        }
    }

    private function checkContainment(GraphMigrationPlan $plan): void
    {
        // Существующие связи учитываются вместе с новыми, включая ручные связи в графе.
        $out = $incoming = [];
        $add = function (string $from, string $to, string $key) use (&$out, &$incoming): void {
            if (isset($out[$from][$to])) {
                $this->errors[] = "$key: duplicate containment pair ({$out[$from][$to]})";
                return;
            }
            $out[$from][$to] = $key;
            $incoming[$from] ??= 0;
            $incoming[$to] = ($incoming[$to] ?? 0) + 1;
        };

        foreach ($this->rows['arrow'] ?? [] as $id => $row) {
            if ($row['kind'] === 'contains') $add('id:' . $row['source_id'], 'id:' . $row['target_id'], "arrow:$id");
        }
        foreach ($plan->arrows as $key => $row) {
            if ($row['kind'] !== 'contains' || isset($this->existing['arrow'][$key])) continue;
            $from = $this->existing['block'][$row['source_id']] ?? null;
            $to = $this->existing['block'][$row['target_id']] ?? null;
            $add($from === null ? $row['source_id'] : "id:$from", $to === null ? $row['target_id'] : "id:$to", $key);
        }

        $queue = array_keys($incoming, 0, true);
        while ($queue) {
            $from = array_pop($queue);
            unset($incoming[$from]);
            foreach ($out[$from] ?? [] as $to => $key) {
                if (--$incoming[$to] === 0) $queue[] = $to;
            }
        }
        foreach (array_keys($incoming) as $from) {
            foreach ($out[$from] ?? [] as $to => $key) {
                if (isset($incoming[$to])) $this->errors[] = "$key: containment forms a cycle or depends on one";
            }
        }
    }

    private function insert(string $type, string $key, array $row): void
    {
        $row['properties'] = graphMigrationJson($row['properties']);
        $row['appearance'] = graphMigrationJson($row['appearance']);
        if ($type === 'arrow') {
            $row['source_id'] = $this->existing['block'][$row['source_id']];
            $row['target_id'] = $this->existing['block'][$row['target_id']];
        }
        $columns = implode(', ', array_map(static fn($name) => '`' . $name . '`', array_keys($row)));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $statement = $this->db->prepare("INSERT INTO {$type}s ($columns) VALUES ($placeholders)");
        $statement->execute(array_values($row));

        $id = (int) $this->db->lastInsertId();
        $this->existing[$type][$key] = $id;
        [$legacyKind, $legacyId] = explode(':', $key, 2);
        if (!in_array($legacyKind, ['project', 'folder', 'element', 'agent', 'asset'], true)) return;

        $reference = $this->db->prepare(
            'INSERT INTO graph_legacy_refs (scope_id, legacy_kind, legacy_id, block_id, arrow_id) VALUES (?, ?, ?, ?, ?)'
        );
        $reference->execute([$this->scope, $legacyKind, $legacyId, $type === 'block' ? $id : null, $type === 'arrow' ? $id : null]);
    }
}
