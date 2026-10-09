<?php
declare(strict_types=1);

/** Entrega áudio/vídeo/capa com suporte a Range (permite avançar/voltar a música) */
require __DIR__ . '/src/bootstrap.php';
start_session();

if (!Auth::check()) {
    http_response_code(401);
    exit;
}
session_write_close();

$t = Library::get((int) ($_GET['id'] ?? 0));
$isCover = isset($_GET['cover']);
$rel = $t ? ($isCover ? $t['cover_path'] : $t['file_path']) : '';
$path = $rel !== '' ? realpath(Library::abs($rel)) : false;
$root = realpath(Library::root());

if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = Library::MIME[$ext] ?? 'application/octet-stream';
$size = filesize($path);
$mtime = filemtime($path);
$etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
if (isset($_GET['download'])) {
    $name = $t['artist'] . ' - ' . $t['title'] . '.' . $ext;
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
}
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$start = 0;
$end = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

@set_time_limit(0);
$fp = fopen($path, 'rb');
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp) && !connection_aborted()) {
    $chunk = fread($fp, (int) min(262144, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
    $left -= strlen($chunk);
}
fclose($fp);
