<?php
declare(strict_types=1);

const GRAPH_ASSET_PRIVATE_PROPERTIES = ['uri' => true, 'fileKey' => true, 'fileStorage' => true, 'storageKey' => true];

function graphAssetBlockKind(string $kind): bool
{
    return in_array($kind, ['asset', 'material'], true);
}

const GRAPH_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'];

function graphAssetList(): void
{
    $scope = (int) requireProject(false)['id'];
    $owners = array_filter(['block' => input('block'), 'arrow' => input('arrow'),
        'run' => input('run'), 'step' => input('step')], static fn($value) => $value !== null);
    if (count($owners) !== 1) throw new ApiError(t('server.graph.material.owner'));
    $owner = array_key_first($owners);
    $id = graphId($owners[$owner]);
    $withText = (string) input('text', '') === '1';

    $assets = match ($owner) {
        'block' => graphAssetListBlock($scope, $id, $withText),
        'arrow' => graphAssetListArrow($scope, $id, $withText),
        'run', 'step' => graphAssetListHistory($scope, $owner, $id, $withText),
    };
    reply(['assets' => $assets]);
}

function graphAssetListBlock(int $scope, int $id, bool $withText): array
{
    graphRow('blocks', $scope, $id);
    $rows = dbAll("SELECT b.*, a.id AS link_id, a.sort AS link_sort, a.properties AS link_props
                    FROM arrows a JOIN blocks b ON b.id = a.target_id AND b.scope_id = a.scope_id
                   WHERE a.scope_id = ? AND a.source_id = ? AND a.kind = 'link' AND b.kind IN ('asset','material')
                   ORDER BY a.sort, a.id", [$scope, $id]);
    $out = [];
    foreach ($rows as $row) {
        $props = graphAssetProps($row['link_props']);
        if (($props['relation'] ?? null) !== 'asset') continue;
        $out[] = graphAssetView($row, graphAssetLegacy($scope, (int) $row['id']), $withText, $props)
            + ['arrowId' => (int) $row['link_id'], 'sort' => (int) $row['link_sort']];
    }
    return $out;
}

/** In v3 an arrow cannot be the endpoint of an asset relation. Its migrated links live in properties. */
function graphAssetListArrow(int $scope, int $id, bool $withText): array
{
    $arrow = graphRow('arrows', $scope, $id);
    $materials = graphAssetProps($arrow['properties'])['materials'] ?? [];
    $out = [];
    foreach (is_array($materials) ? $materials : [] as $link) {
        if (!is_array($link) || ($link['relation'] ?? null) !== 'asset'
            || !is_int($link['legacyAssetId'] ?? null)) continue;
        $asset = graphAssetHistoryRow($scope, $link['legacyAssetId']);
        if ($asset === null) continue;
        $material = $asset['material_id'] === null ? null : graphRow('blocks', $scope, (int) $asset['material_id']);
        $out[] = graphAssetView($material, $asset, $withText, $link)
            + ['ownerArrowId' => $id, 'sort' => (int) ($link['sort'] ?? 0)];
    }
    usort($out, static fn($a, $b) => [$a['sort'], $a['legacyLinkId']] <=> [$b['sort'], $b['legacyLinkId']]);
    return $out;
}

function graphAssetListHistory(int $scope, string $owner, int $id, bool $withText): array
{
    $run = $owner === 'run' ? runRow($id, $scope) : runRow((int) stepRow($id, $scope)['run_id'], $scope);
    $who = caller();
    if (!empty($who['run_id']) && (int) $who['run_id'] !== (int) $run['id']) {
        throw new ApiError(t('server.graph.material.other_run'), 'scope');
    }
    $column = $owner === 'run' ? 'run_id' : 'step_id';
    $rows = dbAll("SELECT a.*, b.id AS material_id, l.id AS link_id, l.role, l.output, l.sort, l.made_by_run
                    FROM asset_links l JOIN assets a ON a.id = l.asset_id AND a.project_id = l.project_id
                    LEFT JOIN graph_legacy_refs g ON g.scope_id = l.project_id AND g.legacy_kind = 'asset' AND g.legacy_id = a.id
                    LEFT JOIN blocks b ON b.scope_id = g.scope_id AND b.id = g.block_id AND b.kind IN ('asset','material')
                   WHERE l.project_id = ? AND l.$column = ? ORDER BY l.sort, l.id", [$scope, $id]);
    return array_map(static fn(array $row) => graphAssetView(null, $row, $withText, [
        'role' => $row['role'], 'output' => $row['output'], 'madeByRun' => $row['made_by_run'], 'legacyLinkId' => $row['link_id'],
    ]) + ['sort' => (int) $row['sort']], $rows);
}

function graphAssetProps(string $json): array
{
    $props = json_decode($json, true);
    return is_array($props) ? $props : [];
}

/** Resolve a migrated record through the database map. Editable block properties never select bytes. */
function graphAssetLegacy(int $scope, int $block): ?array
{
    return dbRow("SELECT a.* FROM graph_legacy_refs g JOIN assets a ON a.project_id = g.scope_id AND a.id = g.legacy_id
                   WHERE g.scope_id = ? AND g.block_id = ? AND g.legacy_kind = 'asset'", [$scope, $block]);
}

function graphAssetHistoryRow(int $scope, int $id): ?array
{
    return dbRow("SELECT a.*, b.id AS material_id FROM assets a
                   LEFT JOIN graph_legacy_refs g ON g.scope_id = a.project_id AND g.legacy_id = a.id AND g.legacy_kind = 'asset'
                   LEFT JOIN blocks b ON b.scope_id = g.scope_id AND b.id = g.block_id AND b.kind IN ('asset','material')
                  WHERE a.project_id = ? AND a.id = ?", [$scope, $id]);
}

function graphAssetView(?array $material, ?array $legacy, bool $withText, array $link): array
{
    $props = $material === null ? [] : graphAssetProps($material['properties']);
    $materialId = $material['id'] ?? $legacy['material_id'] ?? null;
    $role = is_string($link['role'] ?? null) ? $link['role'] : 'attachment';
    $file = graphAssetByteSource($material, $legacy);
    $out = [
        'materialId' => $materialId === null ? null : (int) $materialId,
        'legacyAssetId' => $legacy === null ? null : (int) $legacy['id'],
        'legacyLinkId' => isset($link['legacyLinkId']) ? (int) $link['legacyLinkId'] : null,
        'kind' => is_string($props['kind'] ?? null) ? $props['kind'] : ($legacy['kind'] ?? 'file'),
        'title' => $material['title'] ?? $legacy['title'],
        'mime' => $file['mime'] ?? $legacy['mime'] ?? (is_string($props['body'] ?? null) ? 'text/markdown' : ''),
        'name' => $file['name'] ?? $legacy['original_name'] ?? (is_string($props['originalName'] ?? null) ? $props['originalName'] : null),
        'bytes' => isset($file['path']) ? (int) filesize($file['path']) : null,
        'rev' => (int) ($material['rev'] ?? $legacy['rev']),
        'role' => $role,
        'output' => is_string($link['output'] ?? null) ? $link['output'] : '',
        'madeByRun' => isset($link['madeByRun']) ? (int) $link['madeByRun'] : null,
        'file' => $file !== null,
    ];
    if ($legacy !== null) {
        $uri = (string) $legacy['uri'];
        if ($legacy['file_key'] === null && preg_match('#\Ahttps?://[^\s]+\z#iD', $uri)
            && filter_var($uri, FILTER_VALIDATE_URL) !== false) {
            $out['url'] = $uri;
        } elseif (assetAtAgentUri($legacy['file_key'], $uri)) {
            $out['agent'] = true;
        }
        if ($file === null && ($legacy['file_key'] !== null || str_starts_with($uri, '/'))) $out['missing'] = true;
        if ($out['bytes'] === null && $legacy['bytes'] !== null) $out['bytes'] = (int) $legacy['bytes'];
    } elseif ($file === null && ($props['fileStorage'] ?? '') === 'graph') {
        $out['missing'] = true;
    }
    $body = array_key_exists('body', $props) ? $props['body'] : ($legacy['body'] ?? null);
    if (is_string($body)) $out['text'] = $withText || $role === 'spec' ? $body : true;
    return $out;
}

function graphAssetFile(): void
{
    $scope = (int) requireProject(false)['id'];
    $block = input('material');
    $asset = input('asset');
    if (($block === null) === ($asset === null)) throw new ApiError(t('server.graph.material.one_id'));
    if (callerRole() === 'worker') {
        if ($block === null) throw new ApiError(t('server.graph.material.current_step'), 'scope');
        graphWorkerMaterial($scope, graphId($block));
        return;
    }
    $material = $legacy = null;
    if ($block !== null) {
        $material = graphRow('blocks', $scope, graphId($block));
        if (!graphAssetBlockKind($material['kind'])) throw new ApiError(t('server.graph.material.not_found'), 'not_found');
        $legacy = graphAssetLegacy($scope, (int) $material['id']);
    } else {
        $legacy = assetRow(graphId($asset), $scope);
    }
    if ($legacy !== null) fileAllowed($legacy);
    $file = graphAssetByteSource($material, $legacy);
    if ($file === null) {
        $text = $material === null ? null : (graphAssetProps($material['properties'])['body'] ?? null);
        if (input('format') === 'material' && is_string($text)) graphMaterialMarkdown($text);
        throw new ApiError(t('server.graph.material.file_unavailable'), 'not_found');
    }
    fileSend($file['path'], $file['name'], $file['mime']);
}

/** Воркер читает только материал из снимка конкретной активной попытки. */
function graphWorkerMaterial(int $scope, int $materialId): void
{
    $who = caller();
    if (empty($who['step_id'])) throw new ApiError(t('server.graph.material.step_pass'), 'scope');
    $allowed = engineLocked($scope, static function () use ($scope, $materialId, $who): array {
        [$run, $step] = workerStepAuthority($who, $scope);
        workerAuthorityFresh($who, $scope, $step);
        if (empty($step['block_id']) || !in_array($step['state'], ['issued','running'], true)
            || !in_array($run['state'], ['running','paused'], true)) {
            throw new ApiError(t('server.graph.material.step_material_not_found'), 'not_found');
        }
        $node = runScene($run)['nodes'][(int) $step['block_id']] ?? [];
        foreach (nativeStepMaterials($node) as $entry) {
            if ((int) $entry['block'] !== $materialId) continue;
            $material = dbRow('SELECT * FROM blocks WHERE scope_id = ? AND id = ?', [$scope, $materialId]);
            $file = $material && graphAssetBlockKind($material['kind'])
                ? graphAssetByteSource($material, graphAssetLegacy($scope, $materialId)) : null;
            return ['file' => $file, 'text' => $entry['text'] ?? null];
        }
        throw new ApiError(t('server.graph.material.step_material_not_found'), 'not_found');
    });
    $file = $allowed['file'];
    if ($file !== null && in_array($file['mime'], GRAPH_IMAGE_MIMES, true)) {
        fileSend($file['path'], $file['name'], $file['mime']);
        return;
    }
    if (is_string($allowed['text']) && $allowed['text'] !== '') graphMaterialMarkdown($allowed['text']);
    if ($file !== null && preg_match('/\.(md|markdown)$/iD', $file['name'])
        && in_array(strtolower(explode(';', $file['mime'], 2)[0]), ['text/markdown', 'text/plain'], true)) {
        fileSend($file['path'], $file['name'], 'text/markdown; charset=utf-8');
        return;
    }
    throw new ApiError(t('server.graph.material.file_unavailable'), 'not_found');
}

function graphMaterialMarkdown(string $text): void
{
    header('Content-Type: text/markdown; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Cache-Control: private, no-store');
    echo $text;
    exit;
}

/** Paths are resolved from scoped legacy records or the server's scoped upload directory. */
function graphAssetByteSource(?array $material, ?array $legacy): ?array
{
    if ($legacy !== null) {
        $path = graphAssetLegacyPath($legacy);
        if ($path === null) return null;
        $mime = (string) $legacy['mime'];
        if (!preg_match('#\A[a-z0-9_.+-]+/[a-z0-9_.+-]+(?:;[ -~]+)?\z#iD', $mime)) $mime = '';
        return ['path' => $path, 'mime' => $mime, 'name' => (string) ($legacy['original_name'] ?: $legacy['title'])];
    }
    if ($material === null) return null;
    $props = graphAssetProps($material['properties']);
    $path = graphAssetStoredPath($material);
    if ($path === null || !is_file($path) || !is_readable($path)) return null;
    $mime = @getimagesize($path)['mime'] ?? null;
    if (!in_array($mime, GRAPH_IMAGE_MIMES, true)) return null;
    $name = $props['originalName'] ?? $material['title'];
    return ['path' => $path, 'mime' => $mime, 'name' => is_string($name) ? $name : $material['title']];
}

function graphAssetLegacyPath(array $legacy): ?string
{
    if ($legacy['file_key'] !== null) {
        $key = (string) $legacy['file_key'];
        $dir = realpath((string) config()['files_dir']);
        if ($dir === false || !preg_match('/\A[a-fA-F0-9]{48}\z/D', $key)) return null;
        $candidate = $dir . '/' . $key . '.bin';
        $path = realpath($candidate);
        if ($path !== $candidate) return null;
    } else {
        $uri = (string) $legacy['uri'];
        if (!str_starts_with($uri, '/') || str_contains($uri, "\0")) return null;
        try {
            $path = pathMustBeInProject($uri, (int) $legacy['project_id'], 'asset_file');
        } catch (ApiError) {
            return null;
        }
        // A shared parent root must not let one project read a nested workspace of another project.
        $deepest = -1;
        $own = false;
        foreach (dbAll("SELECT project_id, work_dir FROM folders WHERE work_dir <> ''") as $row) {
            $work = realpath((string) $row['work_dir']);
            if ($work === false || ($path !== $work && !str_starts_with($path, rtrim($work, '/') . '/'))) continue;
            $length = strlen($work);
            if ($length >= $deepest) {
                $sameProject = (int) $row['project_id'] === (int) $legacy['project_id'];
                $own = $sameProject || ($length === $deepest && $own);
                $deepest = $length;
            }
        }
        if ($deepest >= 0 && !$own) return null;
    }
    return is_file($path) && is_readable($path) ? $path : null;
}

function graphAssetStorageRoot(bool $create = false): ?string
{
    $base = (string) config()['files_dir'];
    if ($create && !is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) return null;
    $base = realpath($base);
    return $base === false ? null : graphAssetCanonicalDir($base . '/graph-assets', $create);
}

function graphAssetCanonicalDir(string $dir, bool $create): ?string
{
    clearstatcache(true, $dir);
    if (is_link($dir)) return null;
    if ($create && !is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) return null;
    return is_dir($dir) && realpath($dir) === $dir ? $dir : null;
}

function graphAssetStoredDir(int $scope, bool $create = false): ?string
{
    $root = graphAssetStorageRoot($create);
    return $root === null ? null : graphAssetCanonicalDir("$root/$scope", $create);
}

function graphAssetStoredName(array $material): string
{
    $id = (int) $material['id'];
    // A recreated ID gets a fresh path. The locator survives edits to material properties.
    $generation = substr(hash('sha256', $material['created_at']), 0, 24);
    return "$id-$generation.bin";
}

function graphAssetStoredPath(array $material, bool $create = false): ?string
{
    $dir = graphAssetStoredDir((int) $material['scope_id'], $create);
    if ($dir === null) return null;
    $path = $dir . '/' . graphAssetStoredName($material);
    return is_link($path) ? null : $path;
}

/** Read-only inventory of the graph upload namespace; deletion is deliberately a separate operation. */
function graphAssetInventory(): array
{
    $report = ['files' => 0, 'linked' => 0, 'orphaned' => 0, 'orphanBytes' => 0, 'skipped' => 0, 'orphans' => []];
    $root = graphAssetStorageRoot();
    if ($root === null) {
        $path = rtrim((string) config()['files_dir'], '/') . '/graph-assets';
        $report['skipped'] = (int) (is_link($path) || file_exists($path));
        return $report;
    }

    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        $name = $entry->getFilename();
        $scope = filter_var($name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($scope === false || (string) $scope !== $name || $entry->isLink() || !$entry->isDir()) {
            $report['skipped']++;
            continue;
        }

        dbTransaction(static function () use ($scope, &$report): void {
            // Uploads take the same lock before creating files.
            dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$scope]);
            $dir = graphAssetStoredDir($scope);
            if ($dir === null) {
                $report['skipped']++;
                return;
            }
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
                $name = $file->getFilename();
                $id = preg_match('/\A([1-9][0-9]*)-[a-f0-9]{24}\.bin\z/D', $name, $matches)
                    ? filter_var($matches[1], FILTER_VALIDATE_INT) : false;
                $path = $file->getPathname();
                clearstatcache(true, $path);
                $stat = $id === false ? false : @lstat($path);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                    $report['skipped']++;
                    continue;
                }

                $report['files']++;
                $block = dbRow('SELECT id, kind, created_at FROM blocks WHERE scope_id = ? AND id = ?', [$scope, $id]);
                if ($block !== null && graphAssetStoredName($block) === $name) {
                    $counter = graphAssetBlockKind($block['kind']) ? 'linked' : 'skipped';
                    $report[$counter]++;
                    continue;
                }

                $report['orphaned']++;
                $report['orphanBytes'] += $stat['size'];
                $report['orphans'][] = [
                    'scopeId' => $scope, 'blockId' => $id, 'name' => $name, 'bytes' => $stat['size'],
                    'reason' => $block === null ? 'missingBlock' : 'oldGeneration',
                ];
            }
        });
    }
    usort($report['orphans'], static fn($a, $b) => [$a['scopeId'], $a['blockId'], $a['name']]
        <=> [$b['scopeId'], $b['blockId'], $b['name']]);
    return $report;
}

function graphAssetAdd(array $op, array &$ctx): array
{
    if (array_key_exists('text', $op) === array_key_exists('material', $op)) {
        throw new ApiError(t('server.graph.material.text_or_id'));
    }
    [$owner, $link] = graphAssetTarget($op, $ctx);
    if (array_key_exists('material', $op)) {
        $material = graphRow('blocks', $ctx['projectId'], graphId($op['material'], $ctx['graphBlockRefs'] ?? []));
        if (!graphAssetBlockKind($material['kind']) || (int) $material['id'] === $owner) {
            throw new ApiError(t('server.graph.material.not_found'), 'not_found');
        }
        $id = (int) $material['id'];
    } else {
        $text = graphText($op, 'text', '');
        $id = graphAssetNew($op, $ctx, ['kind' => 'text', 'body' => $text, 'mime' => 'text/markdown']);
    }
    return graphAssetAttach($op, $ctx, $owner, $id, $link);
}

/** Validate the attachment owner and link fields before allocating a block or file. */
function graphAssetTarget(array $op, array &$ctx): array
{
    $scope = $ctx['projectId'];
    $owner = graphId($op['block'] ?? null, ['root' => $ctx['graphRoot']] + ($ctx['graphBlockRefs'] ?? []));
    graphRow('blocks', $scope, $owner);
    if ($owner === $ctx['graphRoot']) requireOwner($ctx['project']);
    $role = graphText($op, 'role', 'attachment');
    if (!isset(LINK_ROLES[$role]) || in_array($role, ['result', 'preview'], true)) {
        throw new ApiError(t('server.graph.material.role'));
    }
    $output = graphText($op, 'output', '', 64);

    $legacyFolder = dbValue("SELECT COALESCE(e.folder_id, f.id) FROM graph_legacy_refs g
                 LEFT JOIN elements e ON g.legacy_kind = 'element' AND e.id = g.legacy_id AND e.project_id = g.scope_id
                 LEFT JOIN folders f ON g.legacy_kind = 'folder' AND f.id = g.legacy_id AND f.project_id = g.scope_id
                 WHERE g.scope_id = ? AND g.block_id = ? AND g.legacy_kind IN ('element','folder')", [$scope, $owner]);
    if ($legacyFolder !== null) activeGuardFolders($scope, [(int) $legacyFolder], 'edit_assets');

    return [$owner, ['relation' => 'asset', 'role' => $role, 'output' => $output]];
}

function graphAssetNew(array $op, array &$ctx, array $properties, array $storage = []): int
{
    $title = graphText($op, 'title', '', 512);
    return graphBlockWrite([
        'op' => 'block.create', 'kind' => 'asset', 'title' => $title, 'properties' => $properties,
    ], $ctx, $storage)['id'];
}

function graphAssetAttach(array $op, array &$ctx, int $owner, int $material, array $link): array
{
    $scope = $ctx['projectId'];
    $sort = 0;
    foreach (dbAll("SELECT id, target_id, sort, properties FROM arrows
                      WHERE scope_id = ? AND source_id = ? AND kind = 'link' ORDER BY sort, id", [$scope, $owner]) as $row) {
        $props = graphAssetProps($row['properties']);
        if (($props['relation'] ?? null) !== 'asset') continue;
        $sort = max($sort, (int) $row['sort'] + 1);
        if (($props['role'] ?? 'attachment') !== $link['role']) continue;
        if ((int) $row['target_id'] === $material && ($props['output'] ?? '') === $link['output']) {
            graphRememberRef($op, $ctx['graphBlockRefs'], $material);
            return ['id' => $material, 'materialId' => $material, 'arrowId' => (int) $row['id'], 'rev' => $ctx['rev']];
        }
        if ($link['role'] === 'spec') throw new ApiError(t('server.graph.material.duplicate_spec'), 'conflict');
    }
    $arrow = graphArrowWrite([
        'op' => 'arrow.create', 'kind' => 'link', 'sourceId' => $owner, 'targetId' => $material,
        'sort' => $op['sort'] ?? $sort, 'properties' => $link,
    ], $ctx)['id'];
    graphRememberRef($op, $ctx['graphBlockRefs'], $material);
    return ['id' => $material, 'materialId' => $material, 'arrowId' => $arrow, 'rev' => $ctx['rev']];
}

function graphAssetUpload(): void
{
    // The batch records this request together with its graph changes and supports opId retries.
    unset($GLOBALS['goblin_single']);
    $project = requireProject(false);
    requireWrite($project);
    $file = request()['files'];
    $tmp = $file['tmp_name'] ?? null;
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
        throw new ApiError(t('server.graph.material.one_upload'));
    }
    $size = filesize($tmp);
    if ($size === false || $size > UPLOAD_LIMIT) throw new ApiError(t('server.graph.material.upload_size'));
    $mime = @getimagesize($tmp)['mime'] ?? null;
    if (!in_array($mime, GRAPH_IMAGE_MIMES, true)) throw new ApiError(t('server.graph.material.upload_image'));
    $name = trim(str_replace(["\0", '/', '\\'], '_', (string) ($file['name'] ?? '')));
    $name = mb_substr(mb_scrub($name, 'UTF-8'), 0, 255) ?: 'image';
    $sha = hash_file('sha256', $tmp);
    if ($sha === false) throw new ApiError(t('server.graph.material.upload_read'));
    $op = array_intersect_key(request()['body'], array_flip(['block', 'title', 'role', 'sort', 'output']));
    $op += ['op' => 'graph.asset.upload', 'title' => $name, 'name' => $name, 'bytes' => $size, 'sha256' => $sha, 'mime' => $mime];
    graphValidateSecrets($op);
    $scope = (int) $project['id'];

    $out = dbTransaction(static function () use ($scope, $op, $tmp): array {
        dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$scope]);
        $project = requireProject(true);
        return runBatch($project, [$op], static function (array $op, array &$ctx) use ($tmp): array {
            $ctx['graphRoot'] = graphEnsureRoot($ctx['projectId'], $ctx['rev']);
            [$owner, $link] = graphAssetTarget($op, $ctx);
            graphAssetUploadQuota($ctx['project'], $op);
            $material = graphAssetNew($op, $ctx, [
                'kind' => 'image', 'mime' => $op['mime'],
                'originalName' => $op['name'], 'bytes' => $op['bytes'], 'sha256' => $op['sha256'],
            ], ['fileStorage' => 'graph']);
            $result = graphAssetAttach($op, $ctx, $owner, $material, $link);
            $path = graphAssetStoredPath(graphRow('blocks', $ctx['projectId'], $material), true);
            if ($path === null || file_exists($path)) throw new ApiError(t('server.graph.material.upload_save'));
            // Register undo before moving: a failed cross-filesystem copy can leave a partial file.
            dbOnRollback(static function () use ($path): void {
                if (is_file($path)) unlink($path);
            });
            if (!move_uploaded_file($tmp, $path)) throw new ApiError(t('server.graph.material.upload_save'));
            return $result;
        }, false);
    });
    reply(['rev' => $out['rev'], 'results' => $out['results']] + (empty($out['repeat']) ? [] : ['repeat' => true]), $out['warnings']);
}

function graphAssetUploadQuota(array $project, array $file): void
{
    if (!agentsRemote()) return;
    $limit = filesLimit();
    if ($limit['kb'] && $file['bytes'] > $limit['kb'] * 1024) {
        throw new ApiError(t('server.file.over_kb', ['name' => $file['name'], 'kb' => $limit['kb']]), 'conflict');
    }
    if (!$limit['count']) return;
    $scopes = $project['owner_id']
        ? array_column(dbAll('SELECT id FROM projects WHERE owner_id = ?', [$project['owner_id']]), 'id')
        : [$project['id']];
    $base = rtrim((string) (realpath((string) config()['workfiles_dir']) ?: config()['workfiles_dir']), '/') . '/';
    $have = 0;
    foreach ($scopes as $scope) {
        $dir = graphAssetStoredDir((int) $scope);
        if ($dir !== null) $have += count(glob($dir . '/*.bin') ?: []);
        foreach (dbAll('SELECT uri FROM assets WHERE project_id = ? AND body IS NULL AND uri IS NOT NULL', [$scope]) as $row) {
            $have += (int) str_starts_with($row['uri'], $base);
        }
    }
    if ($have >= $limit['count']) throw new ApiError(t('server.file.over_count', ['n' => $limit['count']]), 'conflict');
}
