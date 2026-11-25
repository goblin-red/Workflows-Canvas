<?php
/* Роутер встроенного сервера PHP — то же, что .htaccess под Apache (корень, public/, public/workfiles/).
   Запуск из корня Гоблина (инструкция — md_backend/ru/backup.md):
     php -S localhost:8080 -t public -d upload_max_filesize=100M -d post_max_size=105M bin/router.php
   Отдаёт: false — файл из public/ как есть (PHP исполняет сервер сам); иначе — отказ или переадресация.
   Не делает: ничего сверх .htaccess — правила те же. */

declare(strict_types=1);

$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Redirect old documentation URLs to the current public guide.
if (preg_match('~^/(?:md_backend/(?:docs|документация)(?:/index\.php|/?)|(?:run-comparison|run-improvements)\.html)$~u', $path)) {
    header('Location: /documentation.php', true, 302);
    return true;
}

// Служебные точечные файлы наружу не отдаём.
$deny = (bool) preg_match('~(^|/)\.~', $path);
if (str_starts_with($path, '/workfiles/')) {
    // Служебное прогонов (пропуска, задания) — нельзя; архив результатов (service/archive) — можно.
    if (preg_match('~(^|/)service(/|$)~', $path) && !preg_match('~(^|/)service/archive(/|$)~', $path)) $deny = true;
    // Рабочие папки — данные, не код: скрипты отсюда не исполняются и не отдаются.
    if (preg_match('~\.(php[0-9]?|phtml|phar|pht|cgi|pl|py|sh)$~i', $path)) $deny = true;
}
if ($deny) {
    http_response_code(403);
    return true;
}

// Markdown — текстом с кодировкой, как AddType в public/.htaccess.
if (str_ends_with($path, '.md') && is_file(__DIR__ . '/../public' . $path)) {
    header('Content-Type: text/markdown; charset=utf-8');
    readfile(__DIR__ . '/../public' . $path);
    return true;
}

return false;
