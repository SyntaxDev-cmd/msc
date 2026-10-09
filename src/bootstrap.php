<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '2.5.0');

$GLOBALS['APP_CFG'] = array_replace(
    require APP_ROOT . '/config.example.php',
    is_file(APP_ROOT . '/config.php') ? (array) require APP_ROOT . '/config.php' : []
);

function cfg(string $key, $default = null)
{
    return $GLOBALS['APP_CFG'][$key] ?? $default;
}

function storage_path(string $rel = ''): string
{
    return APP_ROOT . '/storage' . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

date_default_timezone_set((string) cfg('timezone', 'UTC'));
mb_internal_encoding('UTF-8');

foreach (['library', 'data', 'tmp', 'tmp/cache'] as $dir) {
    if (!is_dir(storage_path($dir))) {
        @mkdir(storage_path($dir), 0755, true);
    }
}

foreach (['Text', 'Db', 'Cache', 'Http', 'Sys', 'Tools', 'Settings', 'Account', 'Playlists', 'Discovery', 'Auth', 'MercadoPago', 'Payments', 'Metadata', 'Innertube', 'Mirrors', 'YouTube', 'Jamendo', 'Library', 'Jobs', 'Worker', 'Agent'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Envia a resposta ao navegador e continua executando em segundo plano
 * (usado para processar downloads sem travar a interface).
 * Funciona no LiteSpeed (Hostinger), PHP-FPM e Apache.
 */
function respond_and_continue($data): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    header('Content-Encoding: none');
    echo $body;
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('sonora_sid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_start();
}

function client_ip(): string
{
    // Só REMOTE_ADDR: cabeçalhos como X-Forwarded-For podem ser forjados para burlar o limite de login.
    // (O LiteSpeed da Hostinger já coloca o IP real do visitante aqui.)
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}
