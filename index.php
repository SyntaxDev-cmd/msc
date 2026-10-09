<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
start_session();

if (!Auth::configured()) {
    header('Location: install.php');
    exit;
}
$logged = Auth::check();
$csrf = $logged ? Auth::csrf() : '';
$app = htmlspecialchars((string) cfg('app_name'), ENT_QUOTES, 'UTF-8');
$v = APP_VERSION;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0b12">
<meta name="csrf" content="<?= $csrf ?>">
<title><?= $app ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="assets/icon.svg">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body class="<?= $logged ? '' : 'logged-out' ?>">

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <symbol id="i-play" viewBox="0 0 24 24"><path fill="currentColor" d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z"/></symbol>
    <symbol id="i-pause" viewBox="0 0 24 24"><rect fill="currentColor" x="6" y="4.5" width="4" height="15" rx="1.2"/><rect fill="currentColor" x="14" y="4.5" width="4" height="15" rx="1.2"/></symbol>
    <symbol id="i-next" viewBox="0 0 24 24"><path fill="currentColor" d="M5 6.3v11.4a1 1 0 0 0 1.53.85L15 13.2a1.4 1.4 0 0 0 0-2.4L6.53 5.45A1 1 0 0 0 5 6.3z"/><rect fill="currentColor" x="16.5" y="5" width="2.5" height="14" rx="1"/></symbol>
    <symbol id="i-prev" viewBox="0 0 24 24"><path fill="currentColor" d="M19 6.3v11.4a1 1 0 0 1-1.53.85L9 13.2a1.4 1.4 0 0 1 0-2.4l8.47-5.35A1 1 0 0 1 19 6.3z"/><rect fill="currentColor" x="5" y="5" width="2.5" height="14" rx="1"/></symbol>
    <symbol id="i-shuffle" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></symbol>
    <symbol id="i-repeat" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m17 2 4 4-4 4M3 11v-1a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 13v1a4 4 0 0 1-4 4H3"/></symbol>
    <symbol id="i-vol" viewBox="0 0 24 24"><path fill="currentColor" d="M11 5 6 9H3v6h3l5 4z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></symbol>
    <symbol id="i-mute" viewBox="0 0 24 24"><path fill="currentColor" d="M11 5 6 9H3v6h3l5 4z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="m16 9 6 6m0-6-6 6"/></symbol>
    <symbol id="i-heart" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7z"/></symbol>
    <symbol id="i-heart-fill" viewBox="0 0 24 24"><path fill="currentColor" d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7z"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></g></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></symbol>
    <symbol id="i-library" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M4 4v16M9 4v16M14 4.5l4.5 15.5"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0-5-5m5 5 5-5M4 17v3h16v-3"/></symbol>
    <symbol id="i-queue" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h13M3 12h13M3 18h8"/><path fill="currentColor" d="M16 15v6l5-3z"/></symbol>
    <symbol id="i-lyrics" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4z"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 8h8M8 12h5"/></symbol>
    <symbol id="i-eq" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M5 21v-7M5 10V3M12 21v-9M12 8V3M19 21v-5M19 12V3M2 14h6M9 8h6M16 16h6"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></symbol>
    <symbol id="i-more" viewBox="0 0 24 24"><g fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></g></symbol>
    <symbol id="i-video" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="2" y="5" width="14" height="14" rx="2"/><path d="m16 10 6-3.5v11L16 14"/></g></symbol>
    <symbol id="i-music" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></g></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5 9-10"/></symbol>
    <symbol id="i-down" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></symbol>
    <symbol id="i-expand" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m6 15 6-6 6 6"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-2.64-6.36L21 8M21 3v5h-5"/></symbol>
    <symbol id="i-sparkle" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5 13.9 11.4 12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></symbol>
  </defs>
</svg>

<div id="login" class="login">
  <form id="login-form" class="login-card">
    <img src="assets/icon.svg" width="64" height="64" alt="">
    <h1><?= $app ?></h1>
    <p class="muted">Sua música. Seu servidor.</p>
    <input type="password" name="password" placeholder="Senha" autocomplete="current-password" required>
    <button class="btn primary">Entrar</button>
    <p class="login-err" role="alert"></p>
  </form>
</div>

<div id="app" class="app">
  <aside class="sidebar">
    <a href="#/home" class="brand"><img src="assets/icon.svg" width="34" height="34" alt=""><span><?= $app ?></span></a>
    <nav class="nav">
      <a href="#/home" data-nav="home"><svg><use href="#i-home"/></svg><span>Início</span></a>
      <a href="#/search" data-nav="search"><svg><use href="#i-search"/></svg><span>Buscar</span></a>
      <a href="#/library" data-nav="library"><svg><use href="#i-library"/></svg><span>Biblioteca</span></a>
      <a href="#/favorites" data-nav="favorites"><svg><use href="#i-heart"/></svg><span>Favoritas</span></a>
      <a href="#/downloads" data-nav="downloads"><svg><use href="#i-download"/></svg><span>Downloads</span><b class="badge" id="dl-badge" hidden>0</b></a>
    </nav>
    <div class="side-title">Gêneros</div>
    <div class="side-genres" id="side-genres"></div>
    <div class="side-foot">
      <a href="install.php" class="muted small">Ferramentas</a>
      <button class="icon-btn" data-action="logout" title="Sair"><svg><use href="#i-logout"/></svg></button>
    </div>
  </aside>

  <main class="main" id="main">
    <div class="view" id="view"></div>
  </main>

  <footer class="player" id="player">
    <div class="pl-track" data-action="open-np">
      <div class="pl-cover"><img data-bind="cover" alt=""><canvas id="mini-viz" width="56" height="56"></canvas></div>
      <div class="pl-meta"><b data-bind="title">Nada tocando</b><small data-bind="artist">Busque uma música para começar</small></div>
      <button class="icon-btn pl-fav" data-action="fav-current" title="Favoritar"><svg><use href="#i-heart"/></svg></button>
    </div>
    <div class="pl-center">
      <div class="pl-controls">
        <button class="icon-btn tgl-shuffle" data-action="shuffle" title="Aleatório (S)"><svg><use href="#i-shuffle"/></svg></button>
        <button class="icon-btn" data-action="prev" title="Anterior (P)"><svg><use href="#i-prev"/></svg></button>
        <button class="play-btn" data-action="toggle" title="Tocar/Pausar (Espaço)"><svg class="i-play"><use href="#i-play"/></svg><svg class="i-pause"><use href="#i-pause"/></svg></button>
        <button class="icon-btn" data-action="next" title="Próxima (N)"><svg><use href="#i-next"/></svg></button>
        <button class="icon-btn tgl-repeat" data-action="repeat" title="Repetir (R)"><svg><use href="#i-repeat"/></svg><i class="one">1</i></button>
      </div>
      <div class="pl-seek">
        <span data-bind="cur">0:00</span>
        <input type="range" class="seek" min="0" max="1000" value="0" step="1" aria-label="Posição">
        <span data-bind="dur">0:00</span>
      </div>
    </div>
    <div class="pl-right">
      <button class="icon-btn" data-action="lyrics" title="Letra (L)"><svg><use href="#i-lyrics"/></svg></button>
      <button class="icon-btn" data-action="queue" title="Fila (Q)"><svg><use href="#i-queue"/></svg></button>
      <button class="icon-btn" data-action="eq" title="Equalizador"><svg><use href="#i-eq"/></svg></button>
      <button class="icon-btn tgl-sleep" data-action="sleep" title="Timer para dormir"><svg><use href="#i-moon"/></svg></button>
      <button class="icon-btn" data-action="mute" title="Mudo (M)"><svg class="i-vol"><use href="#i-vol"/></svg><svg class="i-mute"><use href="#i-mute"/></svg></button>
      <input type="range" class="vol" min="0" max="100" value="90" aria-label="Volume">
      <button class="icon-btn" data-action="open-np" title="Tela cheia"><svg><use href="#i-expand"/></svg></button>
    </div>
    <div class="pl-mini-progress"><i></i></div>
  </footer>

  <nav class="mobile-nav">
    <a href="#/home" data-nav="home"><svg><use href="#i-home"/></svg><span>Início</span></a>
    <a href="#/search" data-nav="search"><svg><use href="#i-search"/></svg><span>Buscar</span></a>
    <a href="#/library" data-nav="library"><svg><use href="#i-library"/></svg><span>Biblioteca</span></a>
    <a href="#/downloads" data-nav="downloads"><svg><use href="#i-download"/></svg><span>Downloads</span></a>
  </nav>
</div>

<section class="np" id="np" aria-hidden="true">
  <div class="np-bg"><img data-bind="cover" alt=""></div>
  <header class="np-head">
    <button class="icon-btn" data-action="close-np" title="Fechar (Esc)"><svg><use href="#i-down"/></svg></button>
    <div class="np-tabs">
      <button data-tab="lyrics" class="active">Letra</button>
      <button data-tab="queue">Fila</button>
    </div>
    <button class="icon-btn" data-action="eq" title="Equalizador"><svg><use href="#i-eq"/></svg></button>
  </header>
  <div class="np-body">
    <div class="np-left">
      <div class="np-stage">
        <canvas id="np-viz"></canvas>
        <img class="np-art" data-bind="cover" alt="">
        <video id="np-video" playsinline preload="metadata"></video>
      </div>
      <div class="np-meta">
        <div><h2 data-bind="title">—</h2><p data-bind="artist"></p></div>
        <button class="icon-btn pl-fav" data-action="fav-current"><svg><use href="#i-heart"/></svg></button>
      </div>
      <div class="pl-seek">
        <span data-bind="cur">0:00</span>
        <input type="range" class="seek" min="0" max="1000" value="0" step="1" aria-label="Posição">
        <span data-bind="dur">0:00</span>
      </div>
      <div class="pl-controls big">
        <button class="icon-btn tgl-shuffle" data-action="shuffle"><svg><use href="#i-shuffle"/></svg></button>
        <button class="icon-btn" data-action="prev"><svg><use href="#i-prev"/></svg></button>
        <button class="play-btn" data-action="toggle"><svg class="i-play"><use href="#i-play"/></svg><svg class="i-pause"><use href="#i-pause"/></svg></button>
        <button class="icon-btn" data-action="next"><svg><use href="#i-next"/></svg></button>
        <button class="icon-btn tgl-repeat" data-action="repeat"><svg><use href="#i-repeat"/></svg><i class="one">1</i></button>
      </div>
    </div>
    <div class="np-right">
      <div class="np-pane active" data-pane="lyrics"><div class="lyrics" id="lyrics"></div></div>
      <div class="np-pane" data-pane="queue"><div id="queue-list"></div></div>
    </div>
  </div>
</section>

<div class="pop" id="pop" hidden></div>
<div class="toasts" id="toasts"></div>
<audio id="audio" preload="metadata"></audio>

<script>window.APP = { name: <?= json_encode((string) cfg('app_name')) ?>, logged: <?= $logged ? 'true' : 'false' ?> };</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
