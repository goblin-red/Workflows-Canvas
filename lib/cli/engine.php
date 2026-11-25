<?php
/* Утилита нового движка (этап Э3): смотреть прогон, ручной шаг одним вызовом,
   вести до человека, брать работу worker.
   Отдаёт: cmdState(), cmdHistory(), cmdPace(), cmdGo(), cmdDriveRoute(), cmdDrive2(),
           cmdWorkRoute(), cmdWork2(),
           workTake(), runState(), runPhase(), reviewOf(), didLine(), serverHas().
   Не делает: не знает правил прогона — ни условий ромба, ни приёмки, ни жетонов.
              Ход делает сервер (run.advance); утилита печатает, что он сделал,
              и замечает, когда A person needs to act. или No response from the worker..

   Коды выхода (md_backend/ru/progon.md): 0 пройден или ход сделан · 2 сервер недоступен
     3 A person needs to act. · 4 No response from the worker. · 5 остановлен, провален или на паузе · 6 тупик.

   drive и work — основные команды: cmdDriveRoute() и cmdWorkRoute() выбирают путь по engine
   прогона. drive2 и work2 — имена-синонимы нового пути, drive1 и work1 — старый ход (engine = 1).
   Таблица команд — COMMANDS в bin/goblin.php. */

declare(strict_types=1);

const RUN_WORDS    = ['running' => 'running', 'paused' => 'paused', 'stopped' => 'stopped',
                      'done' => 'completed', 'failed' => 'failed'];
const DRIVER_WORDS = ['manual' => 'human-led', 'utility' => 'CLI-led'];
const JUDGE_WORDS  = ['human' => 'human review', 'formal' => 'automated checks'];
const JEV_WORDS    = ['off' => 'Jev off', 'advisor' => 'Jev advises', 'judge' => 'Jev reviews'];
const STEP_WORDS   = ['issued' => 'issued', 'running' => 'in progress', 'submitted' => 'submitted',
                      'accepted' => 'accepted', 'returned' => 'returned', 'failed' => 'failed',
                      'cancelled' => 'cancelled'];
const BY_WORDS     = ['formal' => 'formal checks', 'human' => 'human', 'jev' => 'Jev',
                      'lead' => 'leader', 'worker' => 'worker', 'script' => 'CLI'];


// ── Сервер ─────────────────────────────────────────────────────

/** Есть ли у сервера операция. Список спрашиваем один раз за запуск. */
function serverHas(string $op): bool
{
    static $ops = null;
    if ($ops === null) {
        $answer = quiet(static fn() => call('GET', 'config.get', [], []));
        $why = $answer === null ? lastFail()->why : '';
        /* Пропуск погас (прогон закрылся) — спрашиваем ключом проекта: читать итог
           leader вправе и после конца прогона. Сервера нет — это обрыв, код 2. */
        if ($answer === null && in_array($why, ['unauthorized', 'scope'], true)) {
            $answer = quiet(static fn() => call('GET', 'config.get', [], ['anon' => true]));
        }
        if ($answer === null) {
            if ($why === 'network') die_(lastFail()->getMessage(), 'network', 2);
            if (in_array($why, ['unauthorized', 'scope'], true)) {
                die_('Pass unavailable. The run may be closed or the pass revoked — '
                    . 'Get a new pass with goblin attach rN.', $why, 2);
            }
            die_('The server did not return its operation list: ' . lastFail()->getMessage(), $why ?: 'server', 2);
        }
        $ops = array_column($answer['ops'] ?? [], 'op');
    }
    return in_array($op, $ops, true);
}

/** Новой операции ещё нет на сервере — сказать прямо, а не «неизвестная операция». */
function needServer(string $op): void
{
    if (!serverHas($op)) die_("The server does not support $op. Update the server to use this operation.", 'not_ready');
}

/**
 * Снимок прогона (run.state); с $full — ещё и все шаги.
 *
 * Пройденный прогон гасит пропуск leader, а посмотреть итог всё равно нужно:
 * пропуск больше не действует — читаем ключом проекта, как это делает человек.
 */
function runState(array $f, bool $full = false): array
{
    needServer('run.state');
    $args = runArgs($f) + ($full ? ['full' => 1] : []);
    $a = quiet(static fn() => call('GET', 'run.state', $args, $f, 40));
    if ($a !== null) return $a;

    // Причину первого отказа запоминаем сразу: запасной запрос идёт через
    // quiet(), а тот обнуляет lastFail() — иначе отказ печатается без слов.
    $why   = lastFail()->why;
    $sorry = lastFail()->getMessage();

    $label = runIdArg($f);
    if (in_array($why, ['unauthorized', 'scope'], true) && $label !== null) {
        $byKey = quiet(static fn() => call('GET', 'run.state', ['run' => $label] + $args, ['anon' => true], 40));
        if ($byKey !== null) return $byKey;
    }
    die_($sorry, $why ?: 'server', 2);
}

/** Одна страница хода: version будит ожидание, event-курсор доставляет действия без потерь. */
function runAdvance(array $f, int $since, int $sinceEvent, int $wait = 25): array
{
    return call('POST', 'run.advance', runArgs($f) + [
        'since' => $since, 'sinceEvent' => $sinceEvent, 'wait' => $wait,
    ], $f, max(15, $wait + 15));
}

/**
 * Забрать весь согласованный снимок событий. Пока есть hasMoreEvents, страницы
 * читаются с wait=0; event id защищает печать от дубля при повторном ответе.
 * Возвращает [последний ответ с объединённым did, новый event-курсор].
 */
function runAdvanceDrain(array $f, int $since, int $sinceEvent, int $wait = 25): array
{
    $cursor = $sinceEvent;
    $did = [];
    $seen = [];
    $answer = [];

    for ($page = 0; $page < 100; $page++) {
        $before = $cursor;
        $answer = runAdvance($f, $since, $cursor, $page === 0 ? $wait : 0);
        if (!array_key_exists('lastEvent', $answer) || !array_key_exists('hasMoreEvents', $answer)) {
            die_('run.advance did not return lastEvent/hasMoreEvents', 'bad_contract');
        }
        foreach ($answer['did'] ?? [] as $one) {
            $event = (int) ($one['event'] ?? 0);
            if ($event <= 0) die_('An action event is missing its numeric ID.', 'bad_contract');
            if ($event <= $sinceEvent || isset($seen[$event])) continue;
            $seen[$event] = true;
            $did[] = $one;
        }

        $cursor = max($cursor, (int) ($answer['lastEvent'] ?? $cursor));
        if (empty($answer['hasMoreEvents'])) {
            $answer['did'] = $did;
            $answer['lastEvent'] = $cursor;
            return [$answer, $cursor];
        }
        if ($cursor <= $before) die_('The server did not advance lastEvent while reporting more events.', 'bad_contract');
        $since = (int) ($answer['run']['version'] ?? $since);
    }
    die_('More action pages remain after the allowed number of requests.', 'bad_contract');
}

/**
 * Ход круга. Отдаёт [ответ, курсор событий]; прогон закрылся — [null, курсор],
 * а в `$end` лежит код выхода.
 *
 * Сервер гасит пропуск leader, как только Run completed. или остановлен, поэтому
 * последний запрос круга приходит отказом. Это конец работы, а не сбой: перечитываем
 * состояние ключом проекта и отвечаем как положено.
 */
function advanceOrEnd(array $f, int $since, int $sinceEvent, ?int &$end, int $wait = 25): array
{
    $page = quiet(static fn() => runAdvanceDrain($f, $since, $sinceEvent, $wait));
    if ($page !== null) return $page;

    // Слова отказа берём до запасного запроса: quiet() обнуляет lastFail(),
    // и после удачного run.state причина первого отказа исчезала.
    $why   = lastFail()->why;
    $sorry = lastFail()->getMessage();

    $label = runIdArg($f);
    if (in_array($why, ['unauthorized', 'scope'], true) && $label !== null) {
        $state = quiet(static fn() => call('GET', 'run.state', ['run' => $label], ['anon' => true], 20));
        $where = (string) ($state['run']['state'] ?? '');
        if ($where === 'done') {
            say('✓ ' . runLabel($state) . ' completed');
            $end = 0;
            return [null, $sinceEvent];
        }
        if (in_array($where, ['stopped', 'failed'], true)) {
            say('■ ' . runLabel($state) . ' ' . (RUN_WORDS[$where] ?? $where));
            $end = 5;
            return [null, $sinceEvent];
        }
    }
    // Живой прогон и отказ по пропуску: чаще всего в окружении лежат сразу
    // пропуск leader и пропуск агента, а утилита берёт агентский раньше.
    if (in_array($why, ['unauthorized', 'scope'], true) && cfg('agent') !== '' && cfg('run') !== '') {
        $sorry .= '. GOBLIN_AGENT and GOBLIN_RUN are both set. The leader and worker'
            . ' use separate environments. Keep only GOBLIN_RUN for drive.';
    }
    die_($sorry, $why ?: 'server', 2);
}

/**
 * Где прогон сейчас — только по ответу сервера, без правил:
 *   done · stopped (не идёт: остановлен, провален, пауза) · show (ждёт паузу показа)
 *   review (сдача ждёт человека) · human (готово то, чего движок сам не делает)
 *   dead (ничего не открыто и ничего не готово — тупик) · work (worker работают)
 */
function runPhase(array $a): string
{
    $state = (string) ($a['run']['state'] ?? '');
    if ($state === 'done') return 'done';
    if ($state !== 'running') return 'stopped';
    if (($a['wakeIn'] ?? null) !== null) return 'show';

    $open = $a['open'] ?? [];
    foreach ($open as $one) {
        if (($one['state'] ?? '') === 'submitted') return 'review';
    }
    if (!empty($a['ready'])) return 'human';
    return $open ? 'work' : 'dead';
}

/**
 * Сервер просит ещё один явный ход: сделать его до остановки у человека.
 * Один и тот же version повторно не дёргаем — это был бы tight loop на
 * нарушенном контракте needsAdvance.
 */
function runNeedsAdvance(array $a, array &$seen): bool
{
    if (empty($a['needsAdvance'])) return false;
    $version = (int) ($a['run']['version'] ?? -1);
    if (isset($seen[$version])) {
        die_('The server repeated needsAdvance without changing version. Automatic advancement stopped.',
            'bad_contract', 3);
    }
    $seen[$version] = true;
    return true;
}


// ── Печать ─────────────────────────────────────────────────────

/** Секунды без лишних нулей: 3, 1.5. */
function secs($value): string
{
    $n = (float) $value;
    return $n == floor($n) ? (string) (int) $n : (string) round($n, 1);
}

/** Одна строка о том, что сделал движок (элемент `did`). */
function didLine(array $d): string
{
    $step = (string) ($d['step'] ?? '');
    $why  = trim((string) ($d['why'] ?? ''));
    $by   = isset($d['by']) ? ' · ' . (BY_WORDS[$d['by']] ?? $d['by']) : '';

    /* Заголовок события часто начинается теми же словами, что и наша строка
       («принят 35.2»): повтор срезаем, иначе выходит «принят 35.2: принят 35.2». */
    $tail = static function (string $head) use ($why): string {
        $why = trim(preg_replace('/^' . preg_quote($head, '/') . '\s*·?\s*/u', '', $why));
        return $why !== '' ? ": $why" : '';
    };

    return match ((string) ($d['kind'] ?? '')) {
        'accept' => "✓ accepted $step$by" . $tail("accepted $step"),
        'return' => "↩ returned $step$by" . $tail("returned $step"),
        'decide' => "◆ decision $step → " . (($d['branch'] ?? '') === 'yes' ? 'yes' : 'no') . ($why !== '' ? " ($why)" : ''),
        'open'   => "→ issued $step" . (!empty($d['agent']) ? " · {$d['agent']}" : ''),
        'message' => '✉ ' . ($why !== '' ? $why : 'message') . ($step !== null ? " · step $step" : ''),
        // Заголовок события сам может начинаться словами «прогон закрыт»:
        // срезаем повтор, иначе выходило «прогон закрыт: прогон закрыт: …».
        'finish' => '■ run closed' . ($why !== ''
            ? ': ' . preg_replace('/^(?:run closed|прогон закрыт):\s*/iu', '', $why)
            : ''),
        default  => '· ' . trim(($d['kind'] ?? '') . " $step") . ($why !== '' ? ": $why" : ''),
    };
}

/** Шапка прогона и «чего ждёт». */
function sayState(array $a): void
{
    $run = $a['run'];
    say(sprintf('run %s · %s · %s · %s · %s · display pause %s s · version %d',
        $run['label'] ?? '?',
        RUN_WORDS[$run['state'] ?? ''] ?? ($run['state'] ?? '?'),
        DRIVER_WORDS[$run['driver'] ?? ''] ?? 'unknown driver',
        JUDGE_WORDS[$run['judge'] ?? ''] ?? 'unknown reviewer',
        JEV_WORDS[$run['jev'] ?? ''] ?? 'Jev ?',
        secs($run['showPause'] ?? 0),
        (int) ($run['version'] ?? 0)));

    if (trim((string) ($run['waitFor'] ?? '')) !== '') say('Waiting for: ' . $run['waitFor']);

    /* Сданный ответ показываем прямо здесь. Без него leader, у которого нет
       под рукой вывода drive, решал вслепую: строка «ждёт вашей приёмки»
       называла шаг, но не то, что в нём сдано. */
    foreach ($a['open'] ?? [] as $one) {
        $said = trim((string) ($one['result'] ?? ''));
        say("  Open {$one['no']}.{$one['attempt']} — " . (STEP_WORDS[$one['state']] ?? $one['state'])
            . ($said !== '' ? ': ' . mb_substr($said, 0, 120) : ''));

        if (($one['state'] ?? '') === 'submitted') {
            say("     Accept: goblin go {$one['no']}   ·   return: goblin go {$one['no']} --return REASON");
        }
    }
    // У закрытого прогона «готово к выдаче» — не подсказка, а обман:
    // выдавать уже нечего и некому.
    $alive = in_array((string) ($run['state'] ?? ''), ['running', 'paused'], true);
    if ($alive && !empty($a['ready'])) {
        say('Ready for a manual action: ' . implode(', ', array_map(
            static fn($r) => (string) ($r['no'] ?? '?') . (!empty($r['title']) ? " «{$r['title']}»" : ''),
            $a['ready'])));
    }
    if (($a['wakeIn'] ?? null) !== null) say('next action in ' . secs($a['wakeIn']) . ' s (display pause)');
}

/** Шаги строками: значок, адрес, результат. */
function sayHistory(array $rows): void
{
    foreach ($rows as $one) {
        $mark = MARKS[$one['state']] ?? '·';
        $said = trim((string) ($one['result'] ?? ''));
        $word = $one['state'] !== 'accepted' ? '  (' . (STEP_WORDS[$one['state']] ?? $one['state']) . ')' : '';
        say("  $mark {$one['no']}.{$one['attempt']}" . ($said !== '' ? "  $said" : '') . $word);
    }
}

/** Заголовок прогона коротко: для строк drive. */
function runLabel(array $a): string
{
    return (string) ($a['run']['label'] ?? '?');
}


// ── leader: смотреть ──────────────────────────────────────────

/** goblin state [--full] — где мы и чего ждём; с --full ещё и все шаги. */
function cmdState(array $f): int
{
    $a = runState($f, !empty($f['full']));
    sayState($a);
    if (!empty($f['full'])) sayHistory($a['steps'] ?? []);
    return 0;
}

/** goblin history [--no 35] [--all] — принятое в прогоне (с --all — все попытки). */
function cmdHistory(array $f): int
{
    $a   = runState($f, true);
    $no  = num($f, 'no', 0);
    $all = !empty($f['all']);

    $rows = array_values(array_filter($a['steps'] ?? [], static fn($one) =>
        ($all || $one['state'] === 'accepted') && (!$no || (int) $one['no'] === $no)));

    say('run ' . runLabel($a) . ' · ' . ($all ? 'all attempts' : 'accepted work')
        . ($no ? " · block $no" : '') . ' · ' . count($rows));
    sayHistory($rows);
    return 0;
}

/**
 * goblin report [--id rN] — короткий итог прогона: сколько принято, сколько
 * времени занял каждый шаг и что выглядит подозрительно быстрым.
 *
 * Операция run.report была на сервере с самого начала, но входа из утилиты
 * у неё не было — итог приходилось собирать глазами из history.
 */
function cmdReport(array $f): int
{
    needServer('run.report');

    // Итог смотрят уже после конца прогона: пропуск leader к этому моменту погас.
    $slow = num($f, 'slow', 0);
    $a = askAboutRun('run.report', runArgs($f) + ($slow > 0 ? ['slow' => $slow] : []), $f);

    /* Ромбы и шлюзы — тоже строки шагов, но работы в них нет. Слово «попыток»
       в заголовке сбивало: считаем отдельно и называем каждое своим словом. */
    $byWorker = 0;
    $byEngine = 0;
    foreach ($a['steps'] ?? [] as $one) {
        if (($one['state'] ?? '') !== 'accepted') continue;
        in_array((string) ($one['type'] ?? ''), ['decision', 'gateway'], true) ? $byEngine++ : $byWorker++;
    }

    $run = $a['run'] ?? [];
    say('run ' . runLabel($a) . ' · ' . (RUN_WORDS[$run['state'] ?? ''] ?? ($run['state'] ?? '?'))
        . ' · accepted ' . (int) ($a['accepted'] ?? 0) . ' of ' . (int) ($a['total'] ?? 0)
        . ((int) ($a['jobs'] ?? 0) ? ' · jobs ' . (int) $a['jobs'] : ''));
    if ($byEngine) say("  worker results: $byWorker · engine decisions and gateway transitions: $byEngine");

    $fast = 0;
    foreach ($a['steps'] ?? [] as $one) {
        $mark = match ((string) $one['state']) {
            'accepted' => '✓', 'returned' => '↩', 'failed' => '✗', 'cancelled' => '⊘', default => '·',
        };
        $seconds = $one['seconds'] === null ? '' : ' · ' . secs((float) $one['seconds']) . ' s';
        if (!empty($one['suspiciouslyFast'])) $fast++;

        say(sprintf('  %s %d.%d %s%s%s%s',
            $mark, (int) $one['no'], (int) $one['attempt'], (string) $one['title'], $seconds,
            trim((string) $one['result']) !== '' ? ' — ' . $one['result'] : '',
            !empty($one['suspiciouslyFast']) ? '  · quick completion without a content review' : ''));

        // Возврат и провал без причины читать бессмысленно: она и есть суть строки.
        $error = trim((string) ($one['error'] ?? ''));
        if ($error !== '' && in_array((string) $one['state'], ['returned', 'failed', 'cancelled'], true)) {
            say('      reason: ' . $error);
        }
    }

    // Не обвинение, а наводка: быстро и без содержательной проверки.
    if ($fast) {
        say("· quick steps without a content review: $fast"
            . '. Review these steps. Use --slow N to change the threshold.');
    }
    if (trim((string) ($run['summary'] ?? '')) !== '') say('Summary: ' . $run['summary']);
    return 0;
}

/** goblin pace 3 — пауза показа на ходу; 0 отпускает прогон на полную скорость. */
function cmdPace(array $f): int
{
    needServer('run.update');
    $pause = arg($f);
    if ($pause === null || !is_numeric($pause) || (float) $pause < 0) {
        die_('goblin pace SECONDS. Use 0 to disable the display pause.');
    }

    $a = call('POST', 'run.update', runArgs($f) + ['pause' => (float) $pause], $f);
    $now = (float) ($a['run']['showPause'] ?? $pause);
    say($now > 0
        ? '✓ Display pause: ' . secs($now) . ' s per block or arrow on the canvas'
        : '✓ Display pause disabled.');
    return 0;
}


// ── leader: ручной шаг ─────────────────────────────────────────

/**
 * Ручной шаг одним вызовом.
 *   goblin go [N] [--why «…»]        — принять сданное (N — если сданных несколько)
 *   goblin go [N] --return «…»       — вернуть на доработку
 *   goblin go 36 yes|no [--why «…»]  — решить ромб
 * Затем ход движка — до следующей сдачи, до человека или до конца — и одним экраном
 * новая сдача со всем для проверки.
 */
function cmdGo(array $f): int
{
    needServer('run.advance');
    $before = runState($f, true);

    /* Шаг называют и номером блока (36), и адресом попытки (36.2) — в выводе
       он везде стоит с попыткой, и leader подставляли его целиком. Берём
       номер блока: попытку всё равно выбирает сервер по открытой сдаче. */
    $no = arg($f);
    if (is_string($no) && preg_match('/^(\d+)\.\d+$/', trim($no), $found)) $no = $found[1];

    $branch = arg($f, 1);

    // 1. Действие человека.
    if ($branch !== null) {
        if (!in_array($branch, ['yes', 'no'], true)) die_('Use yes or no to select a decision branch.');
        call('POST', 'step.decide', runArgs($f) + ['no' => $no, 'branch' => $branch, 'why' => $f['why'] ?? null], $f);
        say("◆ decision $no → " . ($branch === 'yes' ? 'yes' : 'no'));
    } else {
        $sent = array_values(array_filter($before['open'] ?? [], static fn($one) =>
            ($one['state'] ?? '') === 'submitted' && ($no === null || (string) $one['no'] === (string) $no)));

        if (count($sent) > 1) {
            die_('Several results are ready. Choose a step: goblin go ' . implode(' | ', array_column($sent, 'no')));
        }
        if ($sent) {
            goAct($f, $sent[0]);
        } elseif (isset($f['return']) || $no !== null) {
            die_('No submitted step' . ($no !== null ? " $no" : '') . ' to accept or return.');
        }
    }

    /* 2. Ход движка. Курсор ставим на верхушку ленты, снятую до действия:
          иначе каждый `go` заново печатал прогон с первого события, и на
          длинной схеме leader получал простыню вместо своего шага.
          Всю ленту показывают goblin log и goblin history.

          Ярлык прогона запоминаем здесь же: приёмка последнего шага закрывает
          прогон и гасит пропуск leader, и без ярлыка следующий запрос падал
          с «Пропуск не действует» — на удачно завершённом прогоне. */
    if (!array_key_exists('id', $f) && !empty($before['run']['label'])) {
        $f['id'] = (string) $before['run']['label'];
    }
    return goOn($f, (int) ($before['lastEvent'] ?? 0));
}

/** Принять или вернуть одну сдачу. */
function goAct(array $f, array $step): void
{
    $address = "{$step['no']}.{$step['attempt']}";

    if (isset($f['return'])) {
        $why = is_string($f['return']) ? $f['return'] : (string) ($f['why'] ?? '');
        if (trim($why) === '') die_('Give a reason: goblin go --return REASON.');
        call('POST', 'step.return', runArgs($f) + ['step' => $step['id'], 'why' => $why], $f);
        say("↩ returned $address: $why");
        return;
    }
    // Печатать приёмку здесь не нужно: следом идёт лента событий, и она
    // скажет то же самое, только с разбором проверок.
    call('POST', 'step.accept', runArgs($f) + ['step' => $step['id'], 'why' => $f['why'] ?? null], $f);
}

/** Ход движка после действия: печатать сделанное, остановиться на том, что требует человека. */
function goOn(array $f, int $sinceEvent = 0): int
{
    $timeout = num($f, 'timeout', 600);
    $since   = -1;
    $moved   = time();
    $advanceSeen = [];
    $end = null;

    // Сказали один раз, что ждём worker: без этого `go` после приёмки молчит
    // до самого таймаута, и непонятно, работает он или завис.
    $said = false;

    while (true) {
        // Ждать дольше собственного срока незачем: --timeout 5 должен
        // возвращать управление через пять секунд, а не через двадцать пять.
        [$a, $sinceEvent] = advanceOrEnd($f, $since, $sinceEvent, $end, waitWithin($moved, $timeout));
        if ($a === null) return (int) $end;
        foreach ($a['did'] ?? [] as $line) say(didLine($line));
        if ((int) $a['run']['version'] !== $since) $moved = time();
        $since = (int) $a['run']['version'];
        if (runNeedsAdvance($a, $advanceSeen)) continue;

        if (!$said && runPhase($a) === 'work') {
            say('⏳ waiting for the worker, up to ' . $timeout . ' s (set with --timeout)'
                . '. Ctrl+C stops this command and leaves the run active.');
            $said = true;
        }

        switch (runPhase($a)) {
            case 'done':
                say('✓ run ' . runLabel($a) . ' completed');
                return 0;
            case 'stopped':
                say('■ run ' . runLabel($a) . ' ' . (RUN_WORDS[$a['run']['state']] ?? $a['run']['state']));
                return 5;
            case 'review':
                sayReview($a, $f);
                return 0;
            case 'human':
                say('⚠ ' . ($a['run']['waitFor'] ?: 'A person needs to act.'));
                foreach ($a['ready'] as $item) {
                    if (($item['type'] ?? '') === 'decision') say("  goblin go {$item['no']} yes|no --why REASON");
                }
                return driveExit(3, 'The leader needs to act.');
            case 'dead':
                say('⚠ ' . ($a['run']['waitFor'] ?: 'Run blocked: no steps are ready to open.'));
                return driveExit(6, 'Run blocked: no steps are ready to open.');
        }
        if (time() - $moved > $timeout) {
            say('⚠ no response from the worker for ' . howLong(time() - $moved) . ': ' . waitingOn($a));
            return driveExit(4, 'No response from the worker.');
        }
    }
}

/** Новая сдача одним экраном: ТЗ, вход, результат, итог формальных проверок. */
function sayReview(array $a, array $f): void
{
    foreach ($a['open'] ?? [] as $step) {
        if (($step['state'] ?? '') !== 'submitted') continue;
        $r = reviewOf($a, $step, $f);

        say('');
        say("⌛ submitted {$step['no']}.{$step['attempt']}" . ($r['title'] !== '' ? " «{$r['title']}»" : ''));
        if ($r['spec'] !== '') say("Task specification:\n" . preg_replace('/^/m', '  ', mb_strimwidth($r['spec'], 0, 1200, ' …')));
        if ($r['input'] !== '') say("Input: {$r['input']}");
        if ($r['vars']) {
            say('Input variables: ' . implode(' ', array_map(
                static fn($k, $v) => "$k=$v", array_keys($r['vars']), $r['vars'])));
        }
        say("Result: {$r['result']}");
        foreach ($r['files'] as $file) say('  File: ' . (is_array($file) ? ($file['path'] ?? json_encode($file, JSON_UNESCAPED_UNICODE)) : $file));
        say('Checks: ' . ($r['checks'] !== '' ? $r['checks'] : 'unavailable'));
    }
    say('');
    say('Next: goblin go or goblin go --return REASON');
}

/**
 * Всё для проверки сданного шага: { title, spec, input, vars, result, files, checks }.
 *
 * Сервер отдаёт это полем `review` у открытого шага в run.state (решение leader).
 * Пока поля нет — собираем сами из того, что уже есть: пакет задания из ленты
 * прогона (ТЗ, вход, переменные — ровно то, что получил worker), результат — из шагов.
 * Итога формальных проверок без сервера взять неоткуда.
 */
function reviewOf(array $a, array $step, array $f): array
{
    $out = ['title' => '', 'spec' => '', 'input' => '', 'vars' => [], 'result' => '', 'files' => [], 'checks' => ''];

    if (!empty($step['review']) && is_array($step['review'])) {
        $r = $step['review'];
        return [
            'title'  => (string) ($r['title'] ?? $step['title'] ?? ''),
            'spec'   => textOf($r['spec'] ?? ''),
            'input'  => inputLine($r['input'] ?? ''),
            'vars'   => (array) ($r['vars'] ?? []),
            'result' => (string) ($r['result'] ?? ''),
            'files'  => (array) ($r['files'] ?? []),
            'checks' => checksLine($r['checks'] ?? ''),
        ];
    }

    // Результат — из шагов прогона.
    foreach ($a['steps'] ?? runState($f, true)['steps'] ?? [] as $one) {
        if ((int) ($one['id'] ?? 0) === (int) $step['id']) $out['result'] = (string) ($one['result'] ?? '');
    }

    // Пакет этой попытки — последнее событие package в ленте.
    $events = quiet(static fn() => call('GET', 'run.log', runArgs($f) + [
        'step' => (string) $step['no'], 'kind' => 'package',
    ], $f))['events'] ?? [];
    $last = null;
    foreach ($events as $event) {
        if ((int) ($event['attempt'] ?? 0) === (int) $step['attempt']) $last = $event;
    }
    if ($last) {
        $body = quiet(static fn() => call('GET', 'run.log', runArgs($f) + ['event' => (string) $last['id']], $f))['event']['body'] ?? '';
        $pack = json_decode((string) $body, true) ?: [];

        $out['title'] = (string) ($pack['step']['title'] ?? $pack['element']['title'] ?? '');
        $out['spec']  = textOf($pack['spec'] ?? '');
        $out['vars']  = (array) ($pack['vars'] ?? []);
        $out['input'] = inputLine($pack['input'] ?? (($pack['earlier'] ?? []) ? end($pack['earlier']) : ''));
    }
    return $out;
}

/**
 * Образец ответа с пояснением, что в нём подставляют.
 *
 * `{a}` — имя переменной, и worker гадали, писать ли фигурные скобки. Сами
 * скобки в ответе не нужны: на их место идёт число.
 */
function answerHint(string $answer): string
{
    if (!preg_match_all('/\{([\p{L}_][\p{L}\p{N}_]*)\}/u', $answer, $found)) return $answer;

    $names = array_unique($found[1]);
    return $answer . "\n"
        . (count($names) > 1 ? 'Replace ' : 'Replace ')
        . '{' . implode('}, {', $names) . '} with '
        . (count($names) > 1 ? 'your numbers' : 'your number')
        . '. Omit the braces in the answer.';
}

/**
 * Сколько ещё можно держать долгий запрос: не дольше остатка своего срока.
 * Ноль сервер понимает как «ответь сразу», поэтому меньше секунды не просим.
 */
function waitWithin(int $moved, int $timeout): int
{
    $left = $timeout - (time() - $moved);
    return max(1, min(25, $left));
}

/**
 * Чего ждём, когда сервер молчит.
 *
 * `wait_for` пуст, пока движку нечего делать: шаг выдан и должен быть у worker.
 * Раньше строка «No response from the worker. 25с:» обрывалась пустотой и не называла шаг.
 */
function waitingOn(array $a): string
{
    $said = trim((string) ($a['run']['waitFor'] ?? ''));
    if ($said !== '') return $said;

    $open = [];
    foreach ($a['open'] ?? [] as $one) {
        $open[] = "{$one['no']}.{$one['attempt']} — " . (STEP_WORDS[$one['state']] ?? $one['state'])
            . (!empty($one['agent']) ? " · agent {$one['agent']}" : '');
    }
    return $open
        ? 'Worker step: ' . implode(', ', $open)
        : 'No open steps. See goblin state.';
}

/** Последняя строка drive: exit code цифрой и словом. */
function driveExit(int $code, string $why): int
{
    say("exit code $code — $why");
    return $code;
}

/** ТЗ бывает строкой или объектом с полем text. */
function textOf($spec): string
{
    return trim(is_array($spec) ? (string) ($spec['text'] ?? '') : (string) $spec);
}

/** Вход одной строкой: у слияния их несколько. */
function inputLine($input): string
{
    if (is_string($input)) return trim($input);
    if (!is_array($input)) return '';
    if (isset($input['result'])) {
        return (isset($input['no']) ? "{$input['no']}: " : '') . $input['result'];
    }
    return implode(' · ', array_filter(array_map('inputLine', $input)));
}

/** Итог проверок строкой: «образец — да · арифметика — да». */
function checksLine($checks): string
{
    if (is_string($checks)) return trim($checks);
    if (!is_array($checks)) return '';
    $out = [];
    foreach ($checks as $name => $one) {
        $out[] = is_array($one)
            ? trim(($one['name'] ?? $name) . ' — ' . (($one['ok'] ?? null) === true ? 'yes' : (($one['ok'] ?? null) === false ? 'no' : '?'))
                . (!empty($one['why']) ? " ({$one['why']})" : ''))
            : (is_string($name) ? "$name — $one" : (string) $one);
    }
    return implode(' · ', $out);
}


// ── leader: вести до человека ─────────────────────────────────

/** Canonical drive: engine 2 ведёт сервер, engine 1 остаётся на старом круге. */
function cmdDriveRoute(array $f): int
{
    if (!serverHas('run.state')) return cmdDrive($f);
    // Старый drive умеет вести сразу весь проект; новый требует точный прогон.
    if (cfg('run') === '' && runIdArg($f) === null) return cmdDrive($f);
    $state = runState($f);
    return (int) ($state['run']['engine'] ?? 1) === 2 ? cmdDrive2($f) : cmdDrive($f);
}

/**
 * goblin drive2 [--timeout 600] — вести прогон до конца или до человека.
 * Правил здесь нет: каждый вызов run.advance делает всё, что можно без человека,
 * и держит запрос, пока не будет перемены.
 */
function cmdDrive2(array $f): int
{
    needServer('run.advance');
    asScript();
    $timeout = num($f, 'timeout', 600);
    $since   = -1;
    $sinceEvent = 0;
    $moved   = time();
    $advanceSeen = [];
    $end = null;

    while (true) {
        [$a, $sinceEvent] = advanceOrEnd($f, $since, $sinceEvent, $end, waitWithin($moved, $timeout));
        if ($a === null) return (int) $end;
        foreach ($a['did'] ?? [] as $line) say(runLabel($a) . ' · ' . didLine($line));
        if ((int) $a['run']['version'] !== $since) $moved = time();
        $since = (int) $a['run']['version'];
        if (runNeedsAdvance($a, $advanceSeen)) continue;

        /* Код выхода несёт смысл, а видно его только через $?: называем его
           и словами. Агенту-leader это экономит лишний разбор вывода. */
        switch (runPhase($a)) {
            case 'done':    say('✓ ' . runLabel($a) . ' completed'); return driveExit(0, 'Run completed.');
            case 'stopped': say('■ ' . runLabel($a) . ' ' . (RUN_WORDS[$a['run']['state']] ?? $a['run']['state']));
                            return driveExit(5, 'The run is inactive.');
            case 'review':
            case 'human':   say('⚠ ' . ($a['run']['waitFor'] ?: 'A person needs to act.'));
                            return driveExit(3, 'The leader needs to act.');
            case 'dead':    say('⚠ ' . ($a['run']['waitFor'] ?: 'Run blocked: no steps are ready to open.'));
                            return driveExit(6, 'Run blocked: no steps are ready to open.');
        }
        if (time() - $moved > $timeout) {
            say('⚠ no response from the worker for ' . howLong(time() - $moved) . ': ' . waitingOn($a));
            return driveExit(4, 'No response from the worker.');
        }
    }
}


// ── worker: взять шаг ──────────────────────────────────────────

/** Canonical work: известный legacy-шаг оставляем старому step.mine/get. */
function cmdWorkRoute(array $f): int
{
    if (!serverHas('step.take')) return cmdWork($f);
    $agent = is_string($f['token'] ?? null) ? $f['token'] : cfg('agent');
    if ($agent === '') return cmdWork($f);

    // Явный --id уже задаёт область работы: не подменяем её найденным
    // current-step из общего step.mine.
    if (runIdArg($f) !== null) {
        $state = runState($f);
        $where = (string) ($state['run']['state'] ?? '');
        /* Кончился прогон только в этих состояниях. Пауза обратима: leader сделает
           resume, и работа придёт — уходить из круга нельзя (код 3 = «работы пока нет»). */
        if (in_array($where, ['done', 'stopped', 'failed'], true)) {
            say('The run is closed. No more work will arrive.');
            return 0;
        }
        if ($where === 'paused') {
            say('The run is paused. The leader must resume it before more work can arrive.');
            return 3;
        }
        return (int) ($state['run']['engine'] ?? 1) === 1
            ? cmdWork($f)
            : workTake($f);
    }

    $peek = quiet(static fn() => call('GET', 'step.mine', ['wait' => 0], ['_' => [], 'token' => $agent], 15));
    $runId = (int) ($peek['step']['run'] ?? 0);
    if ($runId) {
        $state = quiet(static fn() => call('GET', 'run.state', ['run' => $runId], ['_' => [], 'token' => $agent], 15));
        if ($state !== null && (int) ($state['run']['engine'] ?? 1) === 1) return cmdWork($f);
        $f['id'] = (string) $runId;
    }
    return workTake($f);
}

/** goblin work2 [--wait 25] [--raw] — взять свой шаг через step.take. */
function cmdWork2(array $f): int
{
    needServer('step.take');
    return workTake($f);
}

/**
 * Взять шаг пропуском агента. Пропуск шага — на экран (export) и в кэш:
 * submit, note, job и fail найдут его сами.
 * Коды: 0 — шаг взят или работа окончена · 3 — работы пока нет, зови снова.
 */
function workTake(array $f): int
{
    $agent = is_string($f['token'] ?? null) ? $f['token'] : cfg('agent');
    if ($agent === '') die_('Set the worker pass: export GOBLIN_AGENT=gba_…');
    $session = workerSession($f, true);

    // Только пропуском агента: пропуск шага из кэша или окружения тут не годится.
    $wait = num($f, 'wait', 25);
    $take = runArgs($f) + ['wait' => $wait, 'session' => $session];
    $auth = ['_' => [], 'token' => $agent, 'session' => $session];
    $a = quiet(static fn() => call('POST', 'step.take', $take, $auth, $wait + 15));

    if ($a === null) {
        if (lastFail() instanceof CommittedReplay) {
            $replay = lastFail()->response;
            $receipt = is_array($replay['receipt'] ?? null) ? $replay['receipt'] : [];
            $recovery = is_array($replay['recovery'] ?? null) ? $replay['recovery'] : [];
            $run = (int) ($recovery['run'] ?? $receipt['run'] ?? 0);
            $step = (int) ($recovery['step'] ?? $receipt['step'] ?? 0);
            if (!$run || !$step) die_(committedReplayMessage(lastFail()), 'replay_secret_unavailable', 3);

            // Команда уже сделана: не ищем «текущий» шаг заново, а просим новый
            // секрет строго для прежних run/step и той же worker session.
            $restore = ['wait' => 0, 'session' => $session, 'run' => $run, 'step' => $step];
            $a = quiet(static fn() => call('POST', 'step.take', $restore, $auth, 15));
            if ($a === null) die_('The command completed, but access to the previous step could not be restored: '
                . lastFail()->getMessage(), lastFail()->why, 3);
        }
    }
    if ($a === null) {
        $why = lastFail()->why;
        if (in_array($why, ['unauthorized', 'not_found'], true)) {
            stepCacheForget($f);
            die_('The server or run is unreachable. The outcome is unknown: ' . lastFail()->getMessage(), $why, 2);
        }
        die_(lastFail()->getMessage(), $why, $why === 'network' ? 2 : 1);
    }

    /* Код выхода здесь несёт весь смысл: 0 — расходиться, 3 — звать снова.
       Раньше в тексте его не было, и два похожих исхода читались одинаково. */
    if (empty($a['ready'])) {
        if (!empty($a['running'])) {
            say('No work yet. Run goblin work again (exit 3).');
            return 3;
        }
        stepCacheForget($f);
        say('No runs remain. Work is complete (exit 0).');
        return 0;
    }

    $step  = $a['step'] ?? [];
    $token = (string) ($step['token'] ?? '');
    if ($token === '') die_('The server issued a step without a pass (step.token).');
    if (isset($f['id']) && is_string($f['id']) && ctype_digit(trim($f['id']))) {
        $asked = (int) trim($f['id']);
        if ($asked !== (int) ($step['run'] ?? 0)) {
            die_('The server returned a step from another run. The pass was discarded.', 'scope_mismatch');
        }
    }
    stepCacheKeep($token, [
        'run' => (int) ($step['run'] ?? 0),
        'agent' => (int) ($step['agent'] ?? 0),
        'step' => (int) ($step['id'] ?? 0),
    ], $f);

    say('→ your step ' . ($step['address'] ?? "{$step['no']}.{$step['attempt']}")
        . (!empty($step['title']) ? ": {$step['title']}" : ''));
    say('Step pass saved to the private worker session cache.');
    if (!empty($f['raw'])) {
        say(json_encode($a['package'] ?? [], JSON_UNESCAPED_UNICODE));
        return 0;
    }

    // Главное — в первых строках: ТЗ длинное, а worker нужны вход и образец.
    sayPackageHead($a['package'] ?? [], !empty($f['brief']));
    if (!empty($f['brief'])) return 0;

    sayPackage($a['package'] ?? []);
    return 0;
}

/**
 * Короткая шапка задания: вход, образец ответа и где взять инструкцию.
 *
 * Полное ТЗ занимает экран, и worker грепали его ради трёх строк. С флагом
 * `--brief` этой шапкой вывод и ограничивается.
 */
function sayPackageHead(array $p, bool $brief = false): void
{
    $vars = (array) ($p['vars'] ?? []);
    $line = $vars
        ? implode(' ', array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars))
        : inputLine($p['input'] ?? '');

    say('');
    say('── Summary ──────────────────────────────────');
    // Адрес шага стоит выше рамки, но в кратком выводе он нужен и внутри.
    if (!empty($p['address']))                    say('Step:     ' . $p['address']
        . (!empty($p['element']['title']) ? ' · ' . $p['element']['title'] : ''));
    // Пустая строка читалась как потерянное поле: у первого шага входа и правда нет.
    say('Input:    ' . (trim($line) !== '' ? $line : 'This is the first step.'));
    // Пустую строку читают как потерянное поле: говорим прямо, что образца нет.
    if (trim((string) ($p['answer'] ?? '')) !== '') {
        foreach (explode("\n", answerHint((string) $p['answer'])) as $i => $row) {
            say(($i === 0 ? 'Answer format: ' : '         ') . $row);
        }
    } else {
        say('Follow the task specification for the answer format.');
    }
    $why = trim((string) ($p['previous']['why'] ?? ''));
    if ($why !== '')                              say('Returned: ' . $why);
    // Слово leader важнее всего остального: оно новее задания.
    foreach ($p['said'] ?? [] as $one)            say('leader: ' . ($one['text'] ?? ''));
    /* Инструкцию называем в начале прогона: на седьмом шаге подряд эта строка
       уже шум. Пустой список принятого — верный признак первого шага. */
    if (empty($p['earlier'])) say('Worker instructions: goblin help worker');
    if ($brief) say('Full task specification: goblin task');
    say('─────────────────────────────────────────────');
}

/** Пакет задания по-человечески (md_backend/ru/progon.md). */
function sayPackage(array $p): void
{
    say("Working folder: " . ($p['workDir'] ?? '?') . "   result folder: " . ($p['outDir'] ?? '?'));

    $spec = textOf($p['spec'] ?? '');
    if ($spec !== '') say("\n## Task specification\n$spec");

    if (!empty($p['context'])) {
        say("\n## Context");
        foreach ($p['context'] as $item) say('— ' . ($item['title'] ?? '') . ': ' . textOf($item['text'] ?? $item));
    }

    // Входные материалы блока: без них worker не знает, с какими файлами работать.
    if (!empty($p['inputs'])) {
        say("\n## Inputs");
        foreach ($p['inputs'] as $item) {
            $where = !empty($item['uri']) ? (string) $item['uri'] : (!empty($item['file']) ? 'file' : '');
            say('— ' . ($item['title'] ?? '') . ($where !== '' ? ": $where" : ''));
        }
    }

    /* Три названия одного и того же сбивали worker: «Вход» — это откуда
       пришёл жетон, а работать надо с числами, которые сервер уже разобрал. */
    $input = inputLine($p['input'] ?? '');
    if ($input !== '') say("\n## Results from incoming arrows\n$input");
    if (!empty($p['vars'])) {
        say("\n## Input values for calculations\n" . implode(' ', array_map(
            static fn($k, $v) => "$k=$v", array_keys((array) $p['vars']), (array) $p['vars'])));
    }

    /* Уже принятое в прогоне. ТЗ схем ссылаются на этот раздел прямым текстом
       («возьми числа из раздела Уже принято»), а на engine 2 его не печатали. */
    if (!empty($p['earlier'])) {
        say("\n## Previously accepted work (reference)");
        foreach ($p['earlier'] as $item) {
            say('— ' . ($item['no'] ?? '?') . ' ' . ($item['title'] ?? '') . ': ' . ($item['result'] ?? ''));
        }
    }

    if (trim((string) ($p['answer'] ?? '')) !== '') say("\n## Answer format\n" . answerHint((string) $p['answer']));
    if (!empty($p['accept'])) say("\n## Requirements\n— " . implode("\n— ", (array) $p['accept']));
    if (!empty($p['reject'])) say("\n## Disqualifying conditions\n— " . implode("\n— ", (array) $p['reject']));

    sayPackageLimits($p['limits'] ?? []);
    sayPackageAgent($p['agent'] ?? null);

    // Общая картина папки приходит, только если человек включил share_scheme.
    if (!empty($p['scheme'])) {
        say("\n## Scheme overview");
        foreach ($p['scheme'] as $item) {
            say(($item['type'] ?? '') === 'arrow'
                ? '— ' . ($item['no'] ?? '?') . ' arrow ' . ($item['from'] ?? '?') . ' → ' . ($item['to'] ?? '?')
                    . (!empty($item['branch']) ? " [{$item['branch']}]" : '')
                : '— ' . ($item['no'] ?? '?') . ' ' . ($item['type'] ?? '') . ': ' . ($item['title'] ?? ''));
        }
    }

    $why = trim((string) ($p['previous']['why'] ?? ''));
    if ($why !== '') say("\n## Feedback on the previous attempt\n$why");

    /* Записка leader шагу. Печаталась только старым печатником `goblin task`,
       а на engine 2 worker её не видел вовсе — хотя пишут её именно ему.
       Строку «leader: …» с адресом для доклада не повторяем. */
    $note = [];
    foreach (explode("\n", (string) ($p['note'] ?? '')) as $row) {
        $row = trim($row);
        if ($row === '' || str_starts_with($row, 'leader:')) continue;
        $note[] = $row;
    }
    if ($note) say("\n## Leader note\n" . implode(' ', $note));

    // Сказанное вслух после выдачи шага: `goblin say` у leader.
    if (!empty($p['said'])) {
        say("\n## Messages from the leader");
        foreach ($p['said'] as $one) {
            say('— ' . (BY_WORDS[$one['by'] ?? ''] ?? ($one['by'] ?? '')) . ': ' . ($one['text'] ?? ''));
        }
    }

    if (!empty($p['stop'])) say("\n⚠ Stop requested. Use goblin fail --reason cancelled.");

    say("\nSubmit: goblin submit --result 'one line' [FILE=OUTPUT ...] [--next]");
}

/** Пределы шага: обязательные выходы, срок и лимит платных вызовов. */
function sayPackageLimits(array $limits): void
{
    $lines = [];

    if (!empty($limits['outputs'])) {
        $lines[] = 'required outputs: ' . implode(', ', (array) $limits['outputs']);
    }
    if (($limits['timeout'] ?? null) !== null && (int) $limits['timeout'] > 0) {
        $lines[] = 'time limit: ' . (int) $limits['timeout'] . ' s';
    }
    if (isset($limits['paidCalls'])) {
        $lines[] = 'paid call limit: ' . (int) $limits['paidCalls']
            . ' (record each request with goblin job add before calling the service)';
    }

    // Последняя попытка — повод не гадать, а спросить: goblin fail --blocker.
    $max = (int) ($limits['maxAttempts'] ?? 0);
    $now = (int) ($limits['attempt'] ?? 0);
    if ($max > 0) {
        $lines[] = "attempts for this block: $now of $max"
            . ($now >= $max ? ' (last attempt)' : '');
    }

    if ($lines) say("\n## Limits\n— " . implode("\n— ", $lines));
}

/** Карточка агента: чем работать, какой моделью и с каким усилием. */
function sayPackageAgent(?array $agent): void
{
    if (!$agent) return;

    $lines = array_filter([
        trim((string) ($agent['cli'] ?? '')) !== '' ? 'CLI: ' . $agent['cli'] : null,
        trim((string) ($agent['model'] ?? '')) !== '' ? 'model: ' . $agent['model'] : null,
        trim((string) ($agent['effort'] ?? '')) !== '' ? 'effort: ' . $agent['effort'] : null,
    ]);
    if (!$lines) return;

    say("\n## Assigned worker: " . ($agent['name'] ?? '') . "\n— " . implode("\n— ", $lines));
}
