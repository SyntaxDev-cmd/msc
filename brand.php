<?php
declare(strict_types=1);

/** Serve os logos da marca (global ou white-label de revenda) */
require __DIR__ . '/src/bootstrap.php';

$k = (string) ($_GET['k'] ?? '');
if (!preg_match('/^(global|acc\d+)$/', $k)) {
    http_response_code(404);
    exit;
}
$files = glob(Brand::dir() . '/' . $k . '.{png,jpg,webp}', GLOB_BRACE) ?: [];
if (!$files) {
    http_response_code(404);
    exit;
}
$f = $files[0];
header('Content-Type: ' . (Library::MIME[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($f));
readfile($f);
