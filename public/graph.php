<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/boot.php';
require_once __DIR__ . '/../lib/web/page.php';

if (installNeeded()) {
    header('Location: install.php');
    exit;
}

$root = dirname(__DIR__);
$version = editorVersion($root);
$viewOnly = isset($_GET['view']);
header('Content-Type: text/html; charset=utf-8');
echo "<!--\n" . agentNote(true) . "\n-->\n";
echo view($root . '/views/graph/editor.html', [
    'version' => $version,
    'lang' => lang(),
    'importmap' => importMap($root, $version),
    'i18n' => langScript(['common', 'editor']),
    'viewOnly' => $viewOnly ? 'true' : 'false',
    'catalogEnabled' => isset(OPS['graph.template.apply']) ? 'true' : 'false',
]);
