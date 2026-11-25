<?php
/* Запуск worker: собрать пакет задания и отдать его исполнителю.
   Отдаёт: cmdLaunch().
   Не делает: не решает, кто worker — агента и его CLI выбирает схема.

   Режимы:
     cli       — leader сам запускает чужой CLI строкой из config.txt;
     subagent  — печатается готовое сообщение для уже открытого терминала;
     terminal  — то же самое (имя оставлено для привычки). */

declare(strict_types=1);

function cmdLaunch(array $f): int
{
    // Пропуск шага уже на руках — шаг не выдаём заново.
    if (!empty($f['token']) && is_string($f['token'])) {
        $token = $f['token'];
        $step  = ['no' => arg($f), 'attempt' => 1];
    } else {
        /* Новый движок выдаёт готовый блок сам, ещё до launch: тогда open отвечает
           «уже открыт», и мы берём пропуск выданной попытки перевыдачей. Попытка та же. */
        $answer = quiet(static fn() => openStep($f));
        $again  = $answer === null && lastFail()->why === 'already_open';
        if ($again) {
            // Шаг уже в работе — перевыдача отобрала бы его у живого worker. Только с --force.
            $state = call('GET', 'run.state', runArgs($f), $f);
            foreach ($state['open'] ?? [] as $one) {
                if ((string) $one['no'] !== (string) arg($f) || ($one['state'] ?? '') === 'issued') continue;
                if (empty($f['force'])) {
                    die_("Step {$one['no']}.{$one['attempt']} is already {$one['state']}; a worker owns it. "
                        . 'Reassign the step with goblin reissue ' . $one['no'] . ' or launch --force', 'step_busy');
                }
            }
            $answer = call('POST', 'step.reissue', runArgs($f) + ['no' => arg($f)], $f);
        } elseif ($answer === null) {
            die_(lastFail()->getMessage(), lastFail()->why);
        }
        $token  = (string) $answer['token'];
        $step   = $answer['step'];
        say("✓ step {$step['no']}.{$step['attempt']} " . ($again ? 'already issued by the engine; retrieved its pass' : 'issued'));
    }

    // Пакет собираем с --peek: «в работе» шаг сделает сам worker, когда возьмётся.
    $data = call('GET', 'step.get', ['peek' => 1], ['step' => $token]);
    $path = writePackage($data, $step, $token);
    say("✓ task package: $path");

    $mode = is_string($f['mode'] ?? null) ? $f['mode'] : 'cli';
    return match ($mode) {
        'cli'                  => launchCli($f, $data, $step, $token, $path),
        'subagent', 'terminal' => launchMessage($data, $step, $token, $path),
        'orca'                 => softDie('Orca launch mode is not available. Use cli or subagent.'),
        default                => softDie("Unknown launch mode: $mode"),
    };
}

/**
 * Файл задания. Место называет сервер: `packageFile` — storage/runs/<id прогона>/, вне public/
 * и вне рабочей папки, имя с id шага — два прогона одной схемы друг друга не затирают.
 * Старый сервер места не называет — тогда по-прежнему service/steps/14.2.md в рабочей папке.
 */
function writePackage(array $data, array $step, string $token): string
{
    $path = (string) ($data['packageFile'] ?? '');
    if ($path === '') {
        $path = (string) ($data['workDir'] ?? '.') . "/service/steps/{$step['no']}.{$step['attempt']}.md";
    }
    @mkdir(dirname($path), 0775, true);
    // Служебное закрываем от браузера: рабочая папка бывает и под public/.
    $deny = dirname($path, 2) . '/.htaccess';
    if (is_dir(dirname($deny)) && !is_file($deny)) {
        @file_put_contents($deny,
            "# Run service files are available to the server and CLI only.\n"
            . "Require all denied\n");
    }

    $text  = "# Task {$step['no']}.{$step['attempt']}\n\n";
    $text .= 'Worker instructions: ' . cfg('url') . "?op=docs.get&file=worker\n\n";
    if (!empty($data['spec'])) {
        $text .= "## Task specification (from the scheme)\n\n" . $data['spec']['text'] . "\n\n";
    }
    $text .= "## Task package\n\n```json\n"
           . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
           . "\n```\n";

    file_put_contents($path, $text);
    return $path;
}

/**
 * Значение, которое можно подставить в строку запуска.
 *
 * Строку пишет админ в `config.txt`, а подстановки приходят из схемы и карточки агента —
 * их правит любой, кто пишет в проект. Кавычки вокруг `{пакет}` в шаблоне не дают
 * применить escapeshellarg, поэтому опасные знаки просто запрещены.
 */
function shellValue(string $value, string $what): string
{
    if ($value === '') return '';
    // Обратная косая, кавычки и перевод строки — тоже знаки оболочки.
    $bad = '`$;&|<>()*?!' . '\\' . '"' . "'\n\r";
    if (strpbrk($value, $bad) !== false) {
        softDie("The $what value contains shell metacharacters: " . $value);
    }
    return $value;
}

/** Запустить чужой CLI самому. Строка запуска — четвёртое поле строки [cli]. */
function launchCli(array $f, array $data, array $step, string $token, string $path): int
{
    $line = is_string($f['cmd'] ?? null) ? $f['cmd'] : cliCommand($data);
    if ($line === '') {
        say('This CLI has no launch command in config.txt. Run it manually with these options:');
        say('  GOBLIN_URL=' . cfg('url') . ' GOBLIN_PROJECT=' . cfg('project')
            . " GOBLIN_STEP=$token <your CLI> \"Read $path and follow its instructions\"");
        return 0;
    }

    $agent = $data['agent'] ?? [];
    $values = [
        shellValue($path, 'package'),
        shellValue("{$step['no']}.{$step['attempt']}", 'step'),
        shellValue((string) ($data['workDir'] ?? '.'), 'folder'),
        shellValue((string) ($agent['model'] ?? ''), 'model'),
        shellValue((string) ($agent['effort'] ?? ''), 'effort'),
    ];
    $names = ['{package}', '{step}', '{folder}', '{model}', '{effort}',
        '{пакет}', '{шаг}', '{папка}', '{модель}', '{усилие}'];
    $line = strtr($line, array_combine($names, [...$values, ...$values]));

    // Окружение worker: адрес, проект и пропуск шага.
    $env = 'GOBLIN_URL=' . escapeshellarg(cfg('url'))
         . ' GOBLIN_PROJECT=' . escapeshellarg(cfg('project'))
         . ' GOBLIN_STEP=' . escapeshellarg($token);
    $where = (string) ($data['workDir'] ?? '');
    $cd    = $where !== '' ? 'cd ' . escapeshellarg($where) . ' && ' : '';

    say("→ launching worker: $line");
    tell($f, 'launch', "Worker started: $line", "working folder: $where", ['no' => (string) $step['no']]);

    if (!empty($f['wait'])) {
        $code = 0;
        $began = microtime(true);
        ob_start();
        passthru("$cd$env $line", $code);
        $out = (string) ob_get_clean();
        echo $out;

        say("Worker exited with code $code");
        tell($f, 'console', 'terminal output · ' . round(strlen($out) / 1024, 1) . ' KB', $out, [
            'no' => (string) $step['no'],
            'tookMs' => (string) round((microtime(true) - $began) * 1000),
            'meta' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
        ]);
        return $code;
    }

    // В фоне: вывод worker ложится рядом с пакетом задания, чтобы leader мог посмотреть.
    $log = preg_replace('/\.md$/', '', $path) . '.log';
    exec("$cd$env nohup $line > " . escapeshellarg($log) . " 2>&1 &");
    say("Worker is running in the background. Output: $log");
    say('Use `goblin next` or start the watcher with goblin watch --quiet &.');
    return 0;
}

/** Готовое сообщение worker, который уже сидит в открытом терминале. */
function launchMessage(array $data, array $step, string $token, string $path): int
{
    say('--- send this to the worker ---');
    say("Task {$step['no']}.{$step['attempt']}. Worker instructions: goblin help worker");
    say("Task package: $path");
    say('Working folder: ' . ($data['workDir'] ?? '—'));
    say('Environment:');
    say('  export GOBLIN_URL=' . cfg('url'));
    say('  export GOBLIN_PROJECT=' . cfg('project'));
    say("  export GOBLIN_STEP=$token");
    say('Run goblin task, complete the specification, then use goblin submit --result "…".');
    say("Reply with: ok {$step['no']}.{$step['attempt']} or fail {$step['no']}.{$step['attempt']}: reason");
    say('--- end of message ---');
    return 0;
}

/** Строка запуска для CLI этого агента: раздел [cli] в config.txt. */
function cliCommand(array $data): string
{
    $key = (string) (($data['agent'] ?? [])['cli'] ?? '');
    if ($key === '') return '';

    $lists = call('GET', 'config.get')['lists'] ?? [];
    foreach ($lists['cli'] ?? [] as $row) {
        if (($row['key'] ?? '') === $key) return (string) ($row['run'] ?? '');
    }
    return '';
}

function softDie(string $why): int
{
    die_($why);
    return 1;
}
