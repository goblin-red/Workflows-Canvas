#!/usr/bin/env php
<?php
/* Goblin Canvas command line for leaders and workers.
   GOBLIN_URL points to api.php. GOBLIN_PROJECT is the project key from #p=.
   Use GOBLIN_RUN for a leader pass, GOBLIN_STEP for a single task or GOBLIN_AGENT
   for a worker who claims several tasks. GOBLIN_WORKER_SESSION identifies the
   worker session across requests. The server keeps the run state.
   See instructions/en/run.md for the full role instructions. */

declare(strict_types=1);

$lib = dirname(__DIR__) . '/lib/cli';
require $lib . '/net.php';
require $lib . '/lead.php';
require $lib . '/engine.php';
require $lib . '/worker.php';
require $lib . '/launch.php';
require $lib . '/watch.php';
require $lib . '/help.php';

/* Таблица команд: имя → функция и роль. Роль здесь только для справки —
   права проверяет сервер по пропуску. Добавить команду = добавить строку. */
const COMMANDS = [
    // leader
    'read'    => ['cmdRead',    'leader'],
    'check'   => ['cmdCheck',   'leader'],
    'prepare' => ['cmdPrepare', 'leader'],
    'start'   => ['cmdStart',   'leader'],
    'next'    => ['cmdNext',    'leader'],
    'steps'   => ['cmdSteps',   'leader'],
    'log'     => ['cmdLog',     'leader'],
    'say'     => ['cmdSay',     'both'],
    'status'  => ['cmdStatus',  'leader'],
    'open'    => ['cmdOpen',    'leader'],
    'launch'  => ['cmdLaunch',  'leader'],
    'wait'    => ['cmdWait',    'leader'],
    'accept'  => ['cmdAccept',  'leader'],
    'return'  => ['cmdReturn',  'leader'],
    'decide'  => ['cmdDecide',  'leader'],
    'jev'     => ['cmdJev',     'leader'],
    'pass'    => ['cmdPass',    'leader'],
    'cancel'  => ['cmdCancel',  'leader'],
    'reissue' => ['cmdReissue', 'leader'],
    'reset'   => ['cmdReset',   'leader'],
    'pause'   => ['cmdPause',   'leader'],
    'resume'  => ['cmdResume',  'leader'],
    'stop'    => ['cmdStop',    'leader'],
    'attach'  => ['cmdAttach',  'leader'],
    'finish'  => ['cmdFinish',  'leader'],
    'drive'   => ['cmdDriveRoute', 'leader'],
    'drive1'  => ['cmdDrive',      'leader'],
    // Явные aliases сохраняем до отдельной приёмки legacy/new маршрутов.
    'state'   => ['cmdState',   'leader'],
    'go'      => ['cmdGo',      'leader'],
    'drive2'  => ['cmdDrive2',  'leader'],
    'pace'    => ['cmdPace',    'leader'],
    'history' => ['cmdHistory', 'both'],
    'report'  => ['cmdReport',  'leader'],
    'watch'   => ['cmdWatch',   'leader'],
    'token'   => ['cmdToken',   'leader'],
    // worker
    'work'    => ['cmdWorkRoute', 'worker'],
    'work1'   => ['cmdWork',      'worker'],
    'work2'   => ['cmdWork2',   'worker'],
    'task'    => ['cmdTask',    'worker'],
    'note'    => ['cmdNote',    'worker'],
    'job'     => ['cmdJob',     'worker'],
    'submit'  => ['cmdSubmit',  'worker'],
    'fail'    => ['cmdFail',    'worker'],
    // оба
    'help'    => ['cmdHelp',    'both'],
];

exit(main(array_slice($argv, 1)));

function main(array $argv): int
{
    $name = $argv[0] ?? '';

    if ($name === '' || $name === '-h' || $name === '--help') {
        sayAbout();
        return 0;
    }
    if (!isset(COMMANDS[$name])) die_("Unknown command: $name");

    $f = flags(array_slice($argv, 1));

    // `--help` у команды — просьба объяснить, а не работать. Без этой проверки
    // `goblin job add --help` заводил настоящую платную заявку. Справку отдаём
    // раньше проверки окружения: объяснять команду можно и без проекта.
    if (!empty($f['help']) || !empty($f['h'])) {
        say(hint($name));
        return 0;
    }

    // Справку отдаём и без проекта: `goblin help worker` — первая команда
    // нового worker, спотыкаться на ней не о чем.
    if ($name !== 'help' && cfg('project') === '') die_('Set GOBLIN_PROJECT first');

    /* Команду leader агентским пропуском не ведут. Если в окружении лежат
       оба пропуска, утилита раньше брала агентский (он старше по очереди) и
       leader получал «scope» или «Не указан прогон» — на этом спотыкались и
       люди, и агенты, совмещающие обе роли. Для leader команд агентский
       пропуск просто не смотрим; явный --token по-прежнему сильнее всего. */
    if (COMMANDS[$name][1] === 'leader' && cfg('agent') !== '' && cfg('run') !== '') {
        cfg('agent', '');
    }

    return (int) COMMANDS[$name][0]($f);
}

/** Общая справка: шапка файла и команды по ролям. */
function sayAbout(): void
{
    $doc = (string) (new ReflectionFunction('main'))->getFileName();
    $head = file_get_contents($doc) ?: '';
    if (preg_match('~/\*(.+?)\*/~s', $head, $found)) {
        say(trim(preg_replace('~^ {0,3}~m', '', $found[1])));
    }
    say('');
    foreach (['leader', 'worker', 'both'] as $role) {
        $names = array_keys(array_filter(COMMANDS, static fn($c) => $c[1] === $role));
        say(ucfirst($role) . ': ' . implode(', ', $names));
    }
    say('');
    say('Command help: goblin <command> --help');
}
