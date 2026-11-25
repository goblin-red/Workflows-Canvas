<?php
/* Короткие подсказки: их печатает `goblin <команда> --help`.
   Отдаёт: hint(), cmdHelp().
   Не делает: не дублирует инструкции — полные правила в instructions/. */

declare(strict_types=1);

const HINTS = [
    'job' => "goblin job add --provider PROVIDER --tool TOOL --model MODEL [--request FILE]\nRecord a job before calling a service. Use goblin job set REF --id ID --state done after it succeeds.",
    'submit' => "goblin submit --result \"RESULT\" [FILE=OUTPUT ...] [--next]\nSubmit the result and optionally attach files by output name.",
    'note' => "goblin note \"MESSAGE\" --stage working|checking|waiting",
    'fail' => "goblin fail --reason \"REASON\" [--blocker]",
    'task' => "goblin task [--raw] [--peek]\nPeek reads the task without claiming it.",
    'work' => "goblin work [--id RUN] [--wait 25] [--brief] [--raw] [--session ID]\nWait for an available step and claim it.",
    'work1' => "goblin work1 [--wait 60]\nClaim work through the legacy engine.",
    'watch' => "goblin watch [--id RUN | --folder ID] [--every 5] [--timeout 600] [--accept 90] [--idle 120] [--log PATH]\nObserve the current run.",
    'drive' => "goblin drive [--id RUN] [--timeout 600]\nAdvance the run until it finishes or needs a decision.",
    'drive1' => "goblin drive1 [--id RUN] [--rounds N] [--accept-clean] [--timeout 900] [--no-finish]\nDrive a legacy run.",
    'launch' => "goblin launch STEP [--mode cli|subagent] [--wait] [--cmd COMMAND] [--force]\nPrepare and start the worker for the selected step.",
    'prepare' => "goblin prepare --folder ID [--files]\nClear old display states and covers. The --files option also clears run output and step service files.",
    'log' => "goblin log [--id RUN] [--step STEP] [--kind KIND] [--full] [--event ID]\nRead run events. Use --full for event bodies.",
    'say' => "goblin say \"MESSAGE\" [--to AGENT] [--no STEP]\nWrite a message to the run timeline.",
    'jev' => "goblin jev STEP [--raw]\nShow Jev’s configured assessment without changing the run.",
    'state' => "goblin state [--id RUN] [--full]\nShow the current state, open work and ready steps. Use --full for previous attempts.",
    'go' => "goblin go [STEP] [--why REASON]\ngoblin go STEP --return REASON\ngoblin go STEP yes|no [--why REASON]\nAccept, return or choose a branch, then continue the run.",
    'drive2' => "goblin drive2 [--id RUN] [--timeout 600]\nDrive a run with engine 2.",
    'pace' => "goblin pace SECONDS\nSet the display pause. Zero removes it.",
    'history' => "goblin history [--no STEP] [--all]\nShow accepted work.",
    'report' => "goblin report [--id RUN]\nShow completion, accepted work and elapsed times.",
    'work2' => "goblin work2 [--wait 25] [--raw] [--session ID]\nClaim work with step.take.",
    'reset' => "goblin reset STEP\nReset the work block for a new attempt.",
    'accept' => "goblin accept STEP [--why REASON]",
    'return' => "goblin return STEP --why REASON",
    'decide' => "goblin decide STEP yes|no --why REASON",
    'token' => "goblin token AGENT_ID\nIssue a worker pass for claiming steps.",
];

function hint(string $name): string
{
    return HINTS[$name] ?? 'No help is available for this command. Run goblin --help.';
}

/** Полная инструкция с сервера: `goblin help lead` или `goblin help worker`. */
function cmdHelp(array $f): int
{
    $which = arg($f) ?? (cfg('step') !== '' || cfg('agent') !== '' ? 'worker' : 'lead');

    /* `goblin help go` спрашивали в самый нужный момент — когда leader увидел
       «нужен человек». Раньше это уходило в docs.get за несуществующей
       инструкцией «go» и падало. Подсказка по команде лежит рядом, отдаём её. */
    if (!in_array($which, ['lead', 'worker', 'deepseek'], true)) {
        if (isset(HINTS[$which])) {
            say(hint($which));
            return 0;
        }
        die_("No instructions for {$which}. Use goblin help lead|worker|deepseek,"
            . " or goblin <command> --help for a command.");
    }

    $where = cfg('url') . '?' . http_build_query(['op' => 'docs.get', 'file' => $which, 'project' => cfg('project')]);
    $text  = @file_get_contents($where);
    if ($text === false) die_('Could not read instructions: ' . $where);
    say($text);
    return 0;
}
