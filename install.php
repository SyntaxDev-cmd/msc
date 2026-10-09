<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
start_session();

$msg = '';
$err = '';
$configured = Auth::configured();

if ($configured && !Account::isAdmin(Auth::user())) {
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
                    if (Auth::configured()) {
                        throw new DomainException('O administrador já existe.');
                    }
                    $u = trim((string) $_POST['username']);
                    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u) || mb_strlen((string) $_POST['password']) < 6) {
                        throw new InvalidArgumentException('Usuário (3-32 letras/números) e senha (6+ caracteres) obrigatórios.');
                    }
                    $id = Db::insert('accounts', ['role' => 'admin', 'username' => $u, 'name' => 'Administrador', 'path' => '/',
                        'password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT), 'created_at' => time()]);
                    Auth::loginAs($id);
                    $configured = true;
                    $msg = 'Administrador criado! Agora instale as ferramentas abaixo.';
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
                    @unlink(storage_path('data/yt_blocked'));
                    $msg = 'Ferramentas verificadas novamente.';
                    break;
                case 'cookies':
                    $f = $_FILES['cookies'] ?? [];
                    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
                        throw new InvalidArgumentException('Envie o arquivo cookies.txt (até 2 MB).');
                    }
                    $txt = (string) file_get_contents($f['tmp_name']);
                    if (!str_contains($txt, 'youtube.com') || !str_contains($txt, "\t")) {
                        throw new InvalidArgumentException('Esse arquivo não parece um cookies.txt do YouTube (formato Netscape). Veja as instruções abaixo.');
                    }
                    file_put_contents(storage_path('data/cookies.txt'), $txt);
                    @chmod(storage_path('data/cookies.txt'), 0600);
                    @unlink(storage_path('data/yt_blocked'));
                    $msg = 'Cookies salvos! Clique em "Testar YouTube" para conferir.';
                    break;
                case 'cookies_rm':
                    @unlink(storage_path('data/cookies.txt'));
                    $msg = 'Cookies removidos.';
                    break;
                case 'yttest':
                    $diag = [];
                    $vid = 'dQw4w9WgXcQ';
                    try {
                        $res = Innertube::videos(Innertube::call('search', ['query' => 'Henrique e Juliano', 'params' => 'EgIQAQ==']));
                        $vid = $res[0]['id'] ?? $vid;
                        $diag[] = [(bool) $res, 'Busca no YouTube (pelo PHP)', count($res) . ' vídeos encontrados'];
                    } catch (Throwable $e) {
                        $diag[] = [false, 'Busca no YouTube (pelo PHP)', $e->getMessage()];
                    }
                    try {
                        $songs = Innertube::musicSongs('Henrique e Juliano', 20);
                        $diag[] = [(bool) $songs, 'Catálogo de artista (YouTube Music)', count($songs) . ' músicas encontradas'];
                    } catch (Throwable $e) {
                        $diag[] = [false, 'Catálogo de artista (YouTube Music)', $e->getMessage()];
                    }
                    if (Tools::ytdlp()) {
                        $try = function (string $clients, string $proxy = '') use ($vid): array {
                            $proxy = $proxy !== '' ? $proxy : Settings::get('yt_proxy');
                            $cmd = array_merge(Tools::ytdlp(), ['--ignore-config', '--no-warnings', '--no-playlist', '--simulate', '-f', 'bestaudio/best', '--print', '%(title)s'],
                                is_file(storage_path('data/cookies.txt')) ? ['--cookies', storage_path('data/cookies.txt')] : [],
                                $proxy !== '' ? ['--proxy', $proxy] : [],
                                $clients !== '' ? ['--extractor-args', 'youtube:player_client=' . $clients] : [],
                                ['https://www.youtube.com/watch?v=' . $vid]);
                            try {
                                [$code, $out, $e2] = Sys::run($cmd, 60);
                            } catch (Throwable $e) {
                                return [false, $e->getMessage()];
                            }
                            if ($code === 0) {
                                return [true, 'OK: ' . trim($out)];
                            }
                            return [false, stripos($e2, 'confirm') !== false ? 'O YouTube bloqueou o IP do servidor (anti-robô)' : Sys::lastLines($e2, 2)];
                        };
                        $current = Settings::get('yt_clients');
                        [$ok, $detail] = $try($current);
                        if (!$ok) {
                            // auto-ajuste: testa outros "aplicativos" do YouTube; o primeiro que passar fica salvo
                            $tested = [];
                            foreach (['tv_simply', 'tv', 'web_embedded', 'mweb', 'android_vr', 'web_safari', 'ios'] as $c) {
                                if ($c === $current) {
                                    continue;
                                }
                                [$ok2] = $try($c);
                                $tested[] = $c . ($ok2 ? ' ✔' : ' ✖');
                                if ($ok2) {
                                    Settings::set(['yt_clients' => $c]);
                                    [$ok, $detail] = [true, "Funcionou se apresentando como “{$c}” — ajuste salvo, os downloads diretos voltam a funcionar"];
                                    break;
                                }
                            }
                            if (!$ok) {
                                $detail .= ' · também testei: ' . implode(', ', $tested);
                            }
                        }
                        $diag[] = [$ok, 'Download direto (yt-dlp)', $detail];
                        if ($ok) {
                            @unlink(storage_path('data/yt_blocked'));
                        }
                    }
                    $tmp = storage_path('tmp/diag_' . bin2hex(random_bytes(4)));
                    @mkdir($tmp, 0755, true);
                    $log = [];
                    try {
                        $file = Mirrors::download($vid, 'audio', $tmp, fn() => null, $log);
                        $diag[] = [true, 'Download por servidor alternativo', 'OK (' . round(filesize($file) / 1048576, 1) . ' MB) · ' . end($log)];
                    } catch (Throwable $e) {
                        $diag[] = [false, 'Download por servidor alternativo', $e->getMessage() . ' · ' . implode(' · ', array_slice($log, 0, 4))];
                    }
                    foreach (glob($tmp . '/*') ?: [] as $f2) {
                        @unlink($f2);
                    }
                    @rmdir($tmp);
                    $msg = 'Teste do YouTube concluído — veja o resultado abaixo.';
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
    ['Python (opcional — o yt-dlp instalado já vem com Python embutido)', true, $tools['python'] ? (string) $tools['python'] : 'não precisa', false],
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
        <h2>1. Crie a conta de administrador</h2>
        <p class="muted">Com ela você gerencia revendas, clientes, planos, pagamentos e a marca.</p>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="do" value="password">
            <input type="text" name="username" placeholder="Usuário (ex.: admin)" required pattern="[A-Za-z0-9._\-]{3,32}" autocomplete="username">
            <input type="password" name="password" placeholder="Senha (mín. 6 caracteres)" required minlength="6">
            <input type="password" name="password2" placeholder="Repita a senha" required minlength="6">
            <button class="btn primary">Criar administrador</button>
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
        <h2>YouTube</h2>
        <?php if (!empty($diag)): ?>
            <ul class="checks">
                <?php foreach ($diag as [$ok, $label, $detail]): ?>
                    <li class="<?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✔' : '✖' ?></span><b><?= $h($label) ?></b> <small><?= $h($detail) ?></small></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted small">Basta <b>um</b> dos dois downloads funcionar. Se os dois falharem, as soluções: <b>★ recomendado:</b> o <b>agente de download</b> no seu PC (Painel › Marca e config. — grátis e sem limite); <b>1)</b> enviar o cookies.txt abaixo; <b>2)</b> colocar uma chave grátis da API <a href="https://rapidapi.com/ytjar/api/youtube-mp36" target="_blank" rel="noopener">youtube-mp36</a> em Painel › Marca e config. › Download do YouTube; <b>3)</b> usar um proxy residencial no mesmo lugar.</p>
        <?php endif; ?>
        <div class="tool-grid">
            <form method="post" class="tool" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Testando… (até 3 min)'">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="do" value="yttest">
                <b>Testar YouTube</b>
                <p class="muted">Confere busca, catálogo de artista e os dois caminhos de download, mostrando o erro real se algo falhar.</p>
                <button class="btn primary">Testar agora</button>
            </form>
            <form method="post" class="tool" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="do" value="cookies">
                <b>Cookies do YouTube <?= is_file(storage_path('data/cookies.txt')) ? '<small style="color:var(--ok)">✔ enviados</small>' : '' ?></b>
                <p class="muted">Opcional. Faz o download direto funcionar mesmo com bloqueio: no Chrome instale a extensão “Get cookies.txt LOCALLY”, abra youtube.com logado (de preferência numa conta secundária), exporte e envie aqui.</p>
                <input type="file" name="cookies" accept=".txt,text/plain" required>
                <button class="btn">Enviar cookies.txt</button>
            </form>
        </div>
        <?php if (is_file(storage_path('data/cookies.txt'))): ?>
            <form method="post" style="margin-top:.5rem"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="cookies_rm"><button class="btn ghost sm">Remover cookies</button></form>
        <?php endif; ?>
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
