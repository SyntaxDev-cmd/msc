<?php
declare(strict_types=1);

/** Manifest PWA dinâmico: o app instalado leva o nome/cor/logo da marca (inclusive de revenda) */
require __DIR__ . '/src/bootstrap.php';
start_session();

$user = Auth::user();
$ref = (string) ($_GET['r'] ?? '');
$acc = $user ?: ($ref !== '' ? Account::byUsername($ref) : null);
if ($acc && !$user && !Account::isReseller($acc)) {
    $acc = null;
}
$b = Account::brand($acc);
$icon = $b['logo'] !== ''
    ? ['src' => $b['logo'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']
    : ['src' => 'assets/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'name' => $b['name'],
    'short_name' => mb_substr($b['name'], 0, 12),
    'description' => $b['tagline'],
    'start_url' => './' . ($ref !== '' ? '?r=' . rawurlencode($ref) : ''),
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#0b0b12',
    'theme_color' => '#0b0b12',
    'icons' => [$icon],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
