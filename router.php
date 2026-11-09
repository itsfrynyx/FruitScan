<?php
// Router for PHP's development server; Apache uses the supplied .htaccess files.
$path = str_replace('\\', '/', rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
if (str_contains($path, '..') || !preg_match('~^/(?:$|index\.php$|api\.php$|(?:app|assets|styles)/[^?]+$)~', $path)) {
    http_response_code(404); exit('Not found');
}
if ($path === '/') { require __DIR__ . '/index.php'; return true; }
return false;
