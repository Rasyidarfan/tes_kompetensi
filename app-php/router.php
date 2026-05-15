<?php
// Router for PHP built-in server: php -S 0.0.0.0:8081 router.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly
if (preg_match('#^/static/#', $uri)) {
    $file = __DIR__ . $uri;
    if (is_file($file)) return false; // let built-in server handle
}

// All other requests go through index.php
require __DIR__ . '/index.php';
