<?php
declare(strict_types=1);

require_once __DIR__ . '/../elements/props.php';

final class GraphMigrationPlan
{
    public array $blocks = [];
    public array $arrows = [];
    public array $errors = [];
    public array $warnings = [];
    public array $counts = [
        'folders' => 0, 'schemes' => 0, 'elements' => 0, 'flows' => 0, 'labels' => 0,
        'agents' => 0, 'roles' => 0, 'assets' => 0, 'placements' => 0,
        'assignments' => 0, 'agentRoles' => 0, 'gateways' => 0,
        'materialArrows' => 0, 'materialsOnArrows' => 0, 'serviceMaterialLinks' => 0, 'missingProducerRuns' => 0,
        'absoluteWorkDirs' => 0, 'absoluteMaterialUris' => 0, 'fileChecksPending' => 0,
        'filesVerified' => 0,
    ];

    public function __construct(private PDO $db, private int $scope, private ?string $filesDir, private ?array $roots)
    {
    }

    public function build(array $project): void
    {
        $root = "project:$this->scope";
        $this->block($root, 'project', $project['title'], '', [
            'lang' => $project['lang'], 'filesServer' => (bool) $project['files_server'],
            'aiConfirm' => (bool) $project['ai_confirm'], 'strictChecks' => (bool) $project['strict_checks'],
        ], new stdClass(), $project);

        $folders = $this->rows(
            'SELECT f.*, EXISTS (SELECT 1 FROM elements e WHERE e.folder_id = f.id AND e.project_id = f.project_id) AS has_elements
               FROM folders f WHERE f.project_id = ? ORDER BY f.id'
        );
        $containers = [];
        foreach ($folders as $row) {
            $containers[$row['id']] = ($row['has_elements'] ? 'folder-container:' : 'folder:') . $row['id'];
        }
        foreach ($folders as $row) {
            // Карта folder:ID хранит адрес старого холста. В дереве этот холст становится схемой в папке.
            $key = 'folder:' . $row['id'];
            $container = $containers[$row['id']];
            $parent = $row['parent_id'] === null ? $root : ($containers[$row['parent_id']] ?? 'folder:' . $row['parent_id']);
            $style = $this->style($row['style'], $key);
            $settings = [
                'workDir' => $row['work_dir'], 'workUrl' => $row['work_url'],
                'shareScheme' => (bool) $row['share_scheme'], 'runEnv' => $row['run_env'],
                'roleScheme' => $row['role_scheme'], 'contentRev' => (int) $row['content_rev'],
                'camera' => $row['camera'] === null ? null : $this->decode($row['camera'], "$key.camera"),
            ];
            if ($row['has_elements']) {
                $this->block($container, 'folder', $row['name'], null, [], $style, $row);
                $this->block($key, 'scheme', $row['name'], null, $settings, $style, $row);
                $this->arrow("placement:$key", $container, $key, 'contains', 0, [], new stdClass(), $row);
                $this->counts['schemes']++;
            } else {
                $this->block($key, 'folder', $row['name'], null, $settings, $style, $row);
            }
            $this->arrow("placement:$container", $parent, $container, 'contains', (int) $row['sort'], [], new stdClass(), $row);
            $this->counts['folders']++;
            $this->counts['absoluteWorkDirs'] += (int) self::absolutePath($row['work_dir']);
        }

        $agentSql = 'SELECT id, name, role, cli, model, effort, permission, sandbox, close_after, style,
                            rev, created_at, updated_at FROM agents WHERE project_id = ? ORDER BY id';
        foreach ($this->rows($agentSql) as $row) {
            $key = 'agent:' . $row['id'];
            $this->block($key, 'agent', $row['name'], null, [
                'cli' => $row['cli'], 'model' => $row['model'], 'effort' => $row['effort'],
                'permission' => $row['permission'], 'sandbox' => $row['sandbox'], 'closeAfter' => $row['close_after'],
            ], $this->style($row['style'], $key), $row);
            $this->arrow("placement:$key", $root, $key, 'contains', 0, [], new stdClass(), $row);
            $this->counts['agents']++;
            $role = 'role:' . $row['role'];
            if (!isset($this->blocks[$role])) {
                $this->block($role, 'role', $row['role'], null, ['role' => $row['role']], new stdClass(),
                    ['rev' => 0, 'created_at' => $project['created_at'], 'updated_at' => $project['created_at']]);
                $this->arrow("placement:$role", $root, $role, 'contains', 0, [], new stdClass(), $this->blocks[$role]);
                $this->counts['roles']++;
            }
            $this->arrow("role:$key", $key, $role, 'link', 0, ['relation' => 'role'], new stdClass(), $row);
            $this->counts['agentRoles']++;
        }

        $props = $this->elementProps();
        $placement = [];
        foreach ($this->rows('SELECT * FROM elements WHERE project_id = ? ORDER BY id') as $row) {
            $key = 'element:' . $row['id'];
            $style = $this->style($row['style'], $key);
            $folder = 'folder:' . $row['folder_id'];
            $p = ['legacyNo' => (int) $row['no'], 'legacyProps' => (object) ($props[$row['id']] ?? [])];
            $this->counts['elements']++;
            if (in_array($row['type'], ['arrow', 'link'], true)) {
                $p += ['title' => $row['title'], 'description' => $row['description'], 'legacyFolderId' => (int) $row['folder_id']];
                if ($row['type'] === 'arrow') {
                    $branch = $row['branch'];
                    $p['branchKey'] = $branch;
                    $p['back'] = (bool) $row['back'];
                    $label = match ($branch) {
                        'yes' => 'YES',
                        'no' => 'NO',
                        default => $p['back'] ? 'another round' : '',
                    };
                    $p['branchLabel'] = implode(' · ', array_filter([$label, trim($row['title'])], 'strlen'));
                    $this->counts['flows']++;
                } else {
                    $this->counts['labels']++;
                }
                $this->arrow($key, 'element:' . $row['from_id'], 'element:' . $row['to_id'],
                    $row['type'] === 'arrow' ? 'flow' : 'link', $this->counts['elements'], $p, $style, $row);
                if (!isset($this->blocks[$folder])) $this->errors[] = "$key: missing $folder";
                continue;
            }

            $geometry = array_intersect_key((array) $style, array_flip(['x', 'y', 'width', 'height', 'z']));
            $placement[$key] = ['placement' => (object) $geometry];
            $appearance = (object) array_diff_key((array) $style, $geometry);
            $this->block($key, $row['type'], $row['title'], $row['description'], $p, $appearance, $row);
            $this->arrow("placement:$key", $folder, $key, 'contains', $this->counts['elements'], $placement[$key], new stdClass(), $row);
            if ($row['agent_id'] !== null) {
                $this->arrow("assignment:$key", $key, 'agent:' . $row['agent_id'], 'link', 0,
                    ['relation' => 'agent'], new stdClass(), $row);
                $this->counts['assignments']++;
            }
            if ($row['target_folder_id'] !== null) {
                $this->arrow("gateway:$key", $key, 'folder:' . $row['target_folder_id'], 'link', 0,
                    ['relation' => 'gatewayTarget'], new stdClass(), $row);
                $this->counts['gateways']++;
            }
        }

        $sql = 'SELECT m.*, COALESCE(child.rev, parent.rev) AS rev,
                       COALESCE(child.created_at, parent.created_at) AS created_at,
                       COALESCE(child.updated_at, parent.updated_at) AS updated_at
                FROM members m LEFT JOIN elements child ON child.id = m.element_id
                LEFT JOIN elements parent ON parent.id = m.container_id
                WHERE child.project_id = ? OR parent.project_id = ? ORDER BY m.container_id, m.element_id';
        foreach (graphMigrationRows($this->db, $sql, [$this->scope, $this->scope]) as $row) {
            $child = 'element:' . $row['element_id'];
            $parent = 'element:' . $row['container_id'];
            $this->arrow("membership:$parent:$child", $parent, $child, 'contains', (int) $row['sort'],
                $placement[$child] ?? [], new stdClass(), $row);
        }

        $this->buildMaterials($project);

        foreach ($this->arrows as $key => $row) {
            foreach (['source_id', 'target_id'] as $end) {
                if (!isset($this->blocks[$row[$end]])) $this->errors[] = "$key: $end → {$row[$end]} is not among the project blocks";
            }
            if ($row['kind'] === 'contains') $this->counts['placements']++;
        }
    }

    private function buildMaterials(array $project): void
    {
        foreach ($this->rows('SELECT * FROM assets WHERE project_id = ? ORDER BY id') as $row) {
            $key = 'asset:' . $row['id'];
            $this->block($key, 'asset', $row['title'], null, [
                'kind' => $row['kind'], 'uri' => $row['uri'], 'fileKey' => $row['file_key'],
                'body' => $row['body'], 'mime' => $row['mime'],
                'bytes' => $row['bytes'] === null ? null : (int) $row['bytes'],
                'sha256' => $row['sha256'], 'originalName' => $row['original_name'],
            ], new stdClass(), $row);
            $this->arrow("placement:$key", "project:$this->scope", $key, 'contains', 0, [], new stdClass(), $row);
            $this->counts['assets']++;
            $this->counts['absoluteMaterialUris'] += (int) self::absolutePath((string) $row['uri']);
            if ($row['file_key'] !== null) {
                $this->checkFile($key, $row);
            } elseif (self::absolutePath((string) $row['uri']) && $row['body'] === null) {
                $this->checkAbsoluteFile($key, $row);
            }
        }

        $sql = 'SELECT l.*, r.project_id AS run_scope, s.project_id AS step_scope, m.project_id AS producer_scope
                FROM asset_links l LEFT JOIN runs r ON r.id = l.run_id
                LEFT JOIN run_steps s ON s.id = l.step_id LEFT JOIN runs m ON m.id = l.made_by_run
                WHERE l.project_id = ? ORDER BY l.id';
        foreach ($this->rows($sql) as $row) {
            $key = 'material:' . $row['id'];
            $asset = 'asset:' . $row['asset_id'];
            if (!isset($this->blocks[$asset])) {
                $this->errors[] = "$key: missing $asset in this project";
                continue;
            }
            $owner = array_filter(array_intersect_key($row, array_flip(['element_id', 'folder_id', 'run_id', 'step_id'])),
                static fn($id) => $id !== null);
            if (count($owner) !== 1) {
                $this->errors[] = "$key: expected one owner";
                continue;
            }
            $column = array_key_first($owner);
            if ($row['made_by_run'] !== null) {
                if ($row['producer_scope'] === null) {
                    // Удаление прогона в v2 оставляет этот исторический номер у материала.
                    $this->warnings[] = "$key: source run {$row['made_by_run']} was deleted; ID preserved";
                    $this->counts['missingProducerRuns']++;
                } elseif ((int) $row['producer_scope'] !== $this->scope) {
                    $this->errors[] = "$key: source run {$row['made_by_run']} belongs to another project";
                }
            }
            if (in_array($column, ['run_id', 'step_id'], true)) {
                $ownerScope = $column === 'run_id' ? $row['run_scope'] : $row['step_scope'];
                if ((int) $ownerScope !== $this->scope) {
                    $this->errors[] = "$key: owner is missing: $column={$owner[$column]} in this project";
                }
                $this->counts['serviceMaterialLinks']++;
                continue;
            }
            $from = substr($column, 0, -3) . ':' . $owner[$column];
            $props = [
                'relation' => 'asset', 'role' => $row['role'], 'output' => $row['output'],
                'madeByRun' => $row['made_by_run'] === null ? null : (int) $row['made_by_run'],
                'legacyLinkId' => (int) $row['id'],
            ];
            if (isset($this->arrows[$from])) {
                // Материал старой стрелки: в графе концами связей бывают только блоки.
                // Ссылка на материал через его старый ID остаётся у стрелки и в asset_links.
                $this->arrows[$from]['properties']->materials[] = (object) ($props + [
                    'legacyAssetId' => (int) $row['asset_id'], 'sort' => (int) $row['sort'],
                    'createdAt' => $row['created_at'],
                ]);
                $this->counts['materialsOnArrows']++;
                continue;
            }
            $this->arrow($key, $from, $asset, 'link', (int) $row['sort'], $props, new stdClass(),
                ['rev' => $project['rev'], 'created_at' => $row['created_at'], 'updated_at' => $row['created_at']]);
            $this->counts['materialArrows']++;
        }
    }

    private function checkFile(string $key, array $row): void
    {
        if ($this->filesDir === null) {
            $this->counts['fileChecksPending']++;
            return;
        }
        $fileKey = $row['file_key'];
        if (!preg_match('/\A[a-f0-9]{48}\z/D', $fileKey)) {
            $this->errors[] = "$key: invalid file key";
            return;
        }
        $path = $this->filesDir . DIRECTORY_SEPARATOR . $fileKey . '.bin';
        $this->checkFileContent($key, $row, $path, $this->filesDir);
    }

    private function checkAbsoluteFile(string $key, array $row): void
    {
        if ($this->roots === null || !str_starts_with($row['uri'], $this->roots['source'] . '/')) {
            $this->counts['fileChecksPending']++;
            return;
        }
        $path = $this->roots['copy'] . substr($row['uri'], strlen($this->roots['source']));
        $this->checkFileContent($key, $row, $path, $this->roots['copy']);
    }

    private function checkFileContent(string $key, array $row, string $path, string $copyRoot): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !str_starts_with($resolved, rtrim($copyRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || is_link($path) || !is_file($resolved) || !is_readable($resolved)) {
            $this->errors[] = "$key: copied file is unavailable";
            return;
        }
        $hash = @hash_file('sha256', $resolved);
        if ($hash === false || ($row['sha256'] !== null && !hash_equals(strtolower($row['sha256']), $hash))
            || ($row['bytes'] !== null && filesize($resolved) !== (int) $row['bytes'])) {
            $this->errors[] = "$key: copied file checksum or size differs";
            return;
        }
        $counter = $row['sha256'] === null ? 'fileChecksPending' : 'filesVerified';
        $this->counts[$counter]++;
    }

    private function elementProps(): array
    {
        $props = [];
        foreach ($this->rows('SELECT p.* FROM props p JOIN elements e ON e.id = p.element_id
                              WHERE e.project_id = ? ORDER BY p.sort, p.id') as $row) {
            $key = 'prop:' . $row['id'];
            $value = match ($row['type']) {
                'list', 'json' => $this->decode($row['value'], $key),
                default => propsDecode($row),
            };
            $props[$row['element_id']][$row['name']] = $value;
        }
        return $props;
    }

    private function block(string $key, string $kind, string $title, ?string $description, array $properties,
        object $appearance, array $row): void
    {
        if ($kind === 'role' || str_starts_with($key, 'folder-container:')) $properties['legacyMigrationKey'] = $key;
        $this->blocks[$key] = compact('kind', 'title', 'appearance')
            + ['description' => $description ?? '', 'properties' => (object) $properties] + $this->metadata($row);
    }

    private function arrow(string $key, string $source, string $target, string $kind, int $sort, array $properties,
        object $appearance, array $row): void
    {
        if (!str_starts_with($key, 'element:')) $properties['legacyMigrationKey'] = $key;
        $this->arrows[$key] = compact('kind', 'sort', 'appearance') + [
            'source_id' => $source, 'target_id' => $target, 'direction' => 'forward',
            'properties' => (object) $properties,
        ] + $this->metadata($row);
    }

    private function metadata(array $row): array
    {
        return ['scope_id' => $this->scope, 'rev' => (int) $row['rev'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    private function rows(string $sql): array
    {
        return graphMigrationRows($this->db, $sql, [$this->scope]);
    }

    private function style(string $json, string $key): object
    {
        $style = $this->decode($json, "$key.style");
        if (is_object($style) || $style === []) return (object) $style;
        $this->errors[] = "$key.style: expected a JSON object";
        return new stdClass();
    }

    private function decode(string $json, string $key): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->errors[] = "$key: invalid JSON";
            return null;
        }
    }

    private static function absolutePath(string $value): bool
    {
        return str_starts_with($value, '/') || str_starts_with($value, '\\')
            || (strlen($value) >= 3 && ctype_alpha($value[0]) && $value[1] === ':' && str_contains('/\\', $value[2]));
    }
}
