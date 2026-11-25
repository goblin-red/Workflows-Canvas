<?php
/* Сборка страницы редактора: разметка из views/editor, карта модулей и словарь языка.
   Отдаёт: renderEditor(), agentNote().
   Не делает: не отдаёт данные — их клиент берёт сам через api.php. */

declare(strict_types=1);

function renderEditor(bool $viewOnly = false, bool $canonicalVisual = false): void
{
    $root = dirname(__DIR__, 2);
    $version = editorVersion($root);
    $note = agentNote($canonicalVisual);

    header('Content-Type: text/html; charset=utf-8');
    // Записка агенту — самой первой строкой: комментарий до <!doctype> браузер пропускает без последствий.
    echo "<!--\n" . $note . "\n-->\n";
    echo view($root . '/views/editor/layout.html', [
        'agentnote' => '<pre hidden class="agent-note">' . h($note) . '</pre>',
        'version'  => $version,
        'importmap' => importMap($root, $version),
        'topbar'   => view($root . '/views/editor/topbar.html'),
        // Выпуск системы — файл VERSION в корне (подпись под логотипом в настройках).
        'left'     => view($root . '/views/editor/left.html', ['release' => h(trim((string) @file_get_contents($root . '/VERSION')))]),
        'canvas'   => view($root . '/views/editor/canvas.html'),
        'panel'    => view($root . '/views/editor/panel.html'),
        'runbar'   => view($root . '/views/editor/runbar.html'),
        'dialogs'  => view($root . '/views/editor/dialogs.html'),
        'bodyclass' => $viewOnly ? 'view-only' : '',
        'lang'     => lang(),
        'i18n'     => langScript(['common', 'editor']),
        // Первый заход: скин и вкладка слева — настройки установки (config.php → look_default, rail_default).
        // Движок голоса (voice_engine): browser или openai — panel/voice.js.
        'defaults' => '<script>window.GOBLIN_DEFAULTS = ' . json_encode([
            'look' => $canonicalVisual ? 'studio' : (string) (config()['look_default'] ?? 'work'),
            'pane' => (string) (config()['rail_default'] ?? 'folders'),
        ], JSON_HEX_TAG) . '; window.GOBLIN_VOICE = '
            . json_encode((string) (config()['voice_engine'] ?? 'browser'), JSON_HEX_TAG) . ';'
            . ($canonicalVisual ? ' window.GOBLIN_CANONICAL_VISUAL = true;' : '') . '</script>',
    ]);
}

/**
 * Подсказка к ссылке из браузера. Сервер не получает часть адреса после #.
 */
function agentNote(bool $native = false): string
{
    $address = $native ? <<<'NATIVE'
    If the page has rendered, use its "Goblin run instructions for the current scene" comment.
    It contains the same instruction URL as Run → Copy leader link.
    For #p=PROJECT&b=PARENT, fetch the leader instructions for that scene:
      api.php?op=scheme&project=PROJECT&parent=PARENT
    If the address uses f=FOLDER instead of b, fetch:
      api.php?op=graph.scene&project=PROJECT&legacyFolder=FOLDER
    Use the returned parentId as PARENT. If the folder still uses the classic canvas,
    read api.php?op=docs.run&role=lead&project=PROJECT&folder=FOLDER instead.
    For a project-only address, api.php?op=graph.scene&project=PROJECT returns the root
    scene and its children. Open the original link with JavaScript if it contains a scene path.
    Confirm the displayed scene is the workflow the person meant before proceeding.
    NATIVE
    : <<<'CLASSIC'
    For #p=PROJECT&f=FOLDER, fetch the leader instructions:
      api.php?op=docs.run&role=lead&project=PROJECT&folder=FOLDER
    If asked to edit the classic folder, use the separate editor task:
      api.php?op=docs.run&role=draw&project=PROJECT&folder=FOLDER
    CLASSIC;

    return <<<END_OF_NOTE
    GOBLIN CANVAS — NOTE FOR AI AGENTS

    This is the browser view for people. If you received it in place of a run link,
    take PROJECT from p (or the older s) in the original URL after #.
    Resolve api.php next to this page, on the same site, and URL-encode the parameter values.

    {$address}

    Reading the instructions does not start a run. Start only if the person asked you to run it.
    If the workflow is unclear, the URL fragment is missing, or access is denied, ask the person to
    open the intended workflow and send its leader link from the Run panel or the agent task dialog.
    If they asked to edit, ask for the scoped link from Connect your agent.
    END_OF_NOTE;
}

/** Версия ресурсов: по самому свежему файлу клиента. Кэш не мешает разработке. */
function editorVersion(string $root): string
{
    $newest = 0;
    foreach (['public/src', 'public/css', 'views/editor'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) $newest = max($newest, $file->getMTime());
        }
    }
    return (string) $newest;
}

/** Карта модулей: клиент пишет `import … from 'goblin/…'`, сборщика нет. */
function importMap(string $root, string $version): string
{
    $map = [];
    $dir = $root . '/public/src';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.js')) continue;
        $name = str_replace($dir . '/', '', $file->getPathname());
        $map['goblin/' . $name] = './src/' . $name . '?v=' . $version;
    }
    ksort($map);
    return json_encode(['imports' => $map], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** Простая подстановка {{ключ}} и перевод {{t:ключ.словаря}} — движка шаблонов тут не нужно. */
function view(string $file, array $vars = []): string
{
    $html = is_file($file) ? (string) file_get_contents($file) : '';
    $html = preg_replace_callback('/\{\{t:([\w.\-]+)\}\}/', static fn(array $m) => h(t($m[1])), $html);
    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . $key . '}}', (string) $value, $html);
    }
    return preg_replace('/\{\{\w+\}\}/', '', $html);
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
