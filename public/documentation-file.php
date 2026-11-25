<?php
declare(strict_types=1);

$retired = ['run-comparison.html', 'run-improvements.html', 'goblin-run-explained.html', 'future-ideas.md'];
if (!in_array($_GET['file'] ?? '', $retired, true)) {
    http_response_code(404);
    exit;
}

header('Location: documentation.php', true, 302);
