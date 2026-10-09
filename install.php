<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
start_session();

$msg = '';
$err = '';
$configured = Auth::configured();

if ($configured && !Auth::check()) {
    header('Location: ./');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals(Auth::csrf(), (string) ($_POST['csrf'] ?? ''))) {
        $err = 'Sessão expirada, tente novamente.';
    } else {
        @set_time_limit(900);
        try {
            switch ($_POST['do'] ?? '') {
                case 'password':
                    if ((string) $_POST['password'] !== (string) $_POST['password2']) {
                        throw new InvalidArgumentException('As senhas não conferem.');
                    }
                    Auth::setPassword((string) $_POST['password']);
                    Auth::attempt((string) $_POST['password']);
                    $configured = true;
                    $msg = 'Senha definida! Agora instale as ferramentas abaixo.';
                    break;
                case 'ytdlp':
                    $msg = 'yt-dlp instalado: versão ' . Tools::installYtdlp();
                    break;
                case 'deno':
                    Tools::installDeno();
                    $msg = 'Deno instalado (melhora a compatibilidade com o YouTube).';
                    break;
                case 'ffmpeg':
                    Tools::installFfmpeg();
                    $msg = 'ffmpeg instalado! Conversão para MP3/Opus ativada.';
                    break;
                case 'detect':
                    Tools::detect();
                    $msg = 'Ferramentas verificadas novamente.';
                    break;
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$tools = Tools::state(true);
$writable = is_writable(storage_path('library')) && is_writable(storage_path('data'));
$checks = [
    ['PHP 8.0 ou superior', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION, true],
    ['Extensão pdo_sqlite', extension_loaded('pdo_sqlite'), '', true],
    ['Extensão curl', extension_loaded('curl'), '', true],
    ['Pasta storage/ gravável', $writable, '', true],
    ['proc_open liberado (executar yt-dlp)', $tools['exec'], $tools['exec'] ? '' : 'Ative no hPanel › PHP Configuration › disable_functions', true],
    ['Python 3.10+', (bool) $tools['python'], (string) $tools['python'], false],
    ['yt-dlp', (bool) $tools['ytdlp'], (string) $tools['ytdlp_version'], true],
    ['Deno (runtime JS p/ YouTube)', (bool) $tools['deno'], '', false],
    ['ffmpeg (converter p/ MP3/Opus)', (bool) $tools['ffmpeg'], 'Formato atual: ' . strtoupper(Tools::audioFormat()), false],
];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = Auth::csrf();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalação · <?= $h(cfg('app_name')) ?></title>
<link rel="icon" href="assets/icon.svg">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="install">
<main class="install-card">
    <h1><img src="assets/icon.svg" alt="" width="40" height="40"> <?= $h(cfg('app_name')) ?> — instalação</h1>
    <?php if ($msg): ?><div class="alert ok"><?= $h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err"><?= $h($err) ?></div><?php endif; ?>

    <?php if (!$configured): ?>
        <h2>1. Crie a senha de acesso</h2>
        <p class="muted">Sua biblioteca fica protegida — só quem tem a senha ouve e baixa.</p>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="do" value="password">
            <input type="password" name="password" placeholder="Senha (mín. 6 caracteres)" required minlength="6">
            <input type="password" name="password2" placeholder="Repita a senha" required minlength="6">
            <button class="btn primary">Salvar senha</button>
        </form>
    <?php else: ?>
        <h2>Verificação do servidor</h2>
        <ul class="checks">
            <?php foreach ($checks as [$label, $ok, $info, $required]): ?>
                <li class="<?= $ok ? 'ok' : ($required ? 'bad' : 'warn') ?>">
                    <span><?= $ok ? '✔' : ($required ? '✖' : '!') ?></span>
                    <b><?= $h($label) ?></b> <small><?= $h($info) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>

        <h2>Ferramentas (instalação com 1 clique)</h2>
        <div class="tool-grid">
            <?php foreach ([
                ['ytdlp', 'yt-dlp', 'Obrigatório. Busca e baixa do YouTube. Clique de novo para atualizar (recomendado mensalmente).'],
                ['deno', 'Deno', 'Recomendado. O YouTube exige um runtime JavaScript para liberar os downloads.'],
                ['ffmpeg', 'ffmpeg', 'Opcional. Converte para MP3/Opus. Sem ele o áudio fica em M4A (AAC), que também é leve.'],
            ] as [$do, $name, $desc]): ?>
                <form method="post" class="tool" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Instalando… (pode levar 1-2 min)'">
                    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="do" value="<?= $do ?>">
                    <b><?= $name ?></b>
                    <p class="muted"><?= $h($desc) ?></p>
                    <button class="btn">Instalar / atualizar</button>
                </form>
            <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="do" value="detect">
            <button class="btn ghost">Verificar novamente</button>
            <a class="btn primary" href="./">Abrir o player →</a>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
