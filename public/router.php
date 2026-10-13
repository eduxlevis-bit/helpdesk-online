<?php
// Router for PHP built-in server:  php -S localhost:8080 router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // serve static file (css, images)
}
if (is_dir($file) && file_exists(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}
if ($path === '/' || $path === '') {
    require __DIR__ . '/index.php';
    return true;
}
http_response_code(404);
echo '<h1>404 Not Found</h1><p><a href="' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/">Home</a></p>';
