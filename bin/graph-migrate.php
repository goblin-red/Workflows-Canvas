<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/graph_migration/migrate.php';

const GRAPH_MIGRATION_USAGE = <<<'HELP'
Convert classic Canvas schemes to Graph on a database copy with migration 035 applied.
php bin/graph-migrate.php --sqlite=/path/to/copy.sqlite [--project=ID] [--files-dir=/path/to/files] [--check|--apply]
php bin/graph-migrate.php --mysql-dsn='mysql:host=127.0.0.1;dbname=goblin_copy;charset=utf8mb4' [same options]
For absolute paths, supply both --source-root=/original/root and --copy-root=/copy/root.
MySQL credentials come from GRAPH_MIGRATE_DB_USER and GRAPH_MIGRATE_DB_PASSWORD.
The default mode is read-only. Specify --project to limit the scope.
Exit codes: 0 success, 1 failed checks or database errors, 2 invalid arguments.
HELP;

function graphMigrationOpenSQLite(string $path, bool $apply): PDO
{
    $path = realpath($path);
    if ($path === false || !is_file($path)) throw new InvalidArgumentException('SQLite file not found.');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if (class_exists('Pdo\\Sqlite')) {
        $options[Pdo\Sqlite::ATTR_OPEN_FLAGS] = $apply ? Pdo\Sqlite::OPEN_READWRITE : Pdo\Sqlite::OPEN_READONLY;
        $db = new Pdo\Sqlite('sqlite:' . $path, null, null, $options);
    } else {
        $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = $apply ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY;
        $db = new PDO('sqlite:' . $path, null, null, $options);
    }
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    if (!$apply) $db->exec('PRAGMA query_only = ON');
    return $db;
}

if (PHP_SAPI !== 'cli') exit(2);

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        [$flag, $value] = array_pad(explode('=', $argument, 2), 2, null);
        $switch = in_array($flag, ['--apply', '--check', '--help'], true);
        $parameter = in_array($flag, ['--sqlite', '--mysql-dsn', '--project', '--files-dir', '--source-root', '--copy-root'], true);
        if (array_key_exists($flag, $options) || (!$switch && !$parameter)
            || ($switch && $value !== null) || ($parameter && ($value === null || $value === ''))) {
            throw new InvalidArgumentException('Invalid or duplicate argument (see --help).');
        }
        $options[$flag] = $value ?? true;
    }
    if (isset($options['--help'])) {
        echo GRAPH_MIGRATION_USAGE, PHP_EOL;
        exit(0);
    }
    if (isset($options['--sqlite']) === isset($options['--mysql-dsn'])
        || isset($options['--apply'], $options['--check'])) {
        throw new InvalidArgumentException('Specify one database and one mode (see --help).');
    }
    $scope = isset($options['--project'])
        ? filter_var($options['--project'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if ($scope === false) throw new InvalidArgumentException('Project ID must be a positive integer.');
    $apply = isset($options['--apply']);
    $files = null;
    if (isset($options['--files-dir'])) {
        $files = realpath($options['--files-dir']);
        if ($files === false || !is_dir($files) || !is_readable($files)) {
            throw new InvalidArgumentException('Copied files directory is unavailable.');
        }
    }

    $roots = null;
    if (isset($options['--source-root']) !== isset($options['--copy-root'])) {
        throw new InvalidArgumentException('Specify --source-root and --copy-root together.');
    }
    if (isset($options['--source-root'])) {
        $source = rtrim($options['--source-root'], '/');
        $copy = realpath($options['--copy-root']);
        if (!str_starts_with($source, '/') || $copy === false || !is_dir($copy)
            || $copy === '/' || $source === $copy || str_starts_with($copy, $source . '/')) {
            throw new InvalidArgumentException('Specify an absolute source root and a separate existing copy directory.');
        }
        $roots = ['source' => $source, 'copy' => $copy];
    }

    if (isset($options['--sqlite'])) {
        $db = graphMigrationOpenSQLite($options['--sqlite'], $apply);
    } else {
        $dsn = $options['--mysql-dsn'];
        $user = getenv('GRAPH_MIGRATE_DB_USER');
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/(?:^mysql:|;)dbname=[^;]+/', $dsn)
            || $user === false || $user === '') {
            throw new InvalidArgumentException('Specify a MySQL DSN containing the copy database name and set GRAPH_MIGRATE_DB_USER.');
        }
        $db = new PDO($dsn, $user, (string) getenv('GRAPH_MIGRATE_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // При проверке самой сессии запрещаются транзакции записи.
        if (!$apply) $db->exec('SET SESSION TRANSACTION READ ONLY');
        else $db->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode, ''), 'STRICT_ALL_TABLES')");
    }

    foreach (['blocks', 'arrows', 'graph_legacy_refs'] as $table) {
        try {
            $db->query("SELECT scope_id FROM $table WHERE 1 = 0");
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['42S02', 'HY000'], true)) {
                throw new RuntimeException("Table $table is unavailable. Apply migration 035 to the database copy.");
            }
            throw $error;
        }
    }

    $ids = $scope === null ? array_column(graphMigrationRows($db, 'SELECT id FROM projects ORDER BY id'), 'id') : [$scope];
    $failed = 0;
    foreach ($ids as $id) {
        try {
            $result = (new GraphMigration($db, (int) $id))->run($apply, $files, $roots);
        } catch (Throwable $error) {
            // PDO can include raw row values in its messages.
            $message = $error instanceof PDOException ? 'Database error, SQLSTATE ' . $error->getCode() : $error->getMessage();
            $result = ['scopeId' => (int) $id, 'ok' => false, 'errors' => [$message]];
        }
        $failed += (int) !$result['ok'];
        echo graphMigrationJson($result), PHP_EOL;
    }
    echo graphMigrationJson(['mode' => $apply ? 'apply' : 'check', 'projects' => count($ids), 'failed' => $failed]), PHP_EOL;
    exit($failed ? 1 : 0);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $error) {
    $message = $error instanceof PDOException ? 'Database error, SQLSTATE ' . $error->getCode() : $error->getMessage();
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
