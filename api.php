<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
}

/* ---------- Webhook do Mercado Pago (público, sem sessão) ---------- */
if ($action === 'mp_webhook') {
    try {
        Payments::webhook((int) ($_GET['r'] ?? 0), $_GET, is_array($input) ? $input : []);
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        json_out(['error' => 'retry'], 500); // o MP reenvia depois
    }
    return;
}

/* ---------- App Android: configuração pública (app.json) e verificação de links (assetlinks.json) ---------- */
if ($action === 'app_config') {
    header('Access-Control-Allow-Origin: *');
    json_out([
        'name' => Settings::get('brand_name'),
        'latest_version_code' => (int) Settings::get('app_latest_version_code'),
        'min_version_code' => (int) Settings::get('app_min_version_code'),
        'apk_url' => Settings::apkUrl(),
        'message' => Settings::get('app_message'),
        'site_version' => APP_VERSION,
    ]);
    return;
}
if ($action === 'assetlinks') {
    $fps = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', strtoupper(Settings::get('app_sha256'))) ?: []), fn($f) => preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f)));
    json_out($fps ? [[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => Settings::get('app_package'), 'sha256_cert_fingerprints' => $fps],
    ]] : []);
    return;
}

/* ---------- Agente de download (programa no PC do administrador; autentica por token) ---------- */
if (str_starts_with($action, 'agent_') && in_array($action, ['agent_next', 'agent_upload', 'agent_fail'], true)) {
    try {
        if (!Agent::check((string) ($_GET['token'] ?? ''))) {
            json_out(['error' => 'Token do agente inválido. Baixe o agente de novo no painel.'], 403);
            return;
        }
        @set_time_limit(300);
        switch ($action) {
            case 'agent_next':
                json_out(['job' => Agent::next()]);
                break;
            case 'agent_upload':
                json_out(['ok' => true, 'track_id' => Agent::upload((int) ($_GET['job_id'] ?? 0), $_FILES['file'] ?? [], (int) ($_GET['part'] ?? 0), (int) ($_GET['parts'] ?? 1))]);
                break;
            case 'agent_fail':
                Agent::fail((int) ($_GET['job_id'] ?? 0), (string) ($input['error'] ?? $_POST['error'] ?? 'erro desconhecido'));
                json_out(['ok' => true]);
                break;
        }
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], $e instanceof DomainException || $e instanceof InvalidArgumentException ? 422 : 500);
    }
    return;
}

start_session();

function me_payload(array $u): array
{
    $plan = Plans::find((int) $u['plan_id']);
    $panel = Account::isAdmin($u) || Account::isReseller($u);
    $settings = Account::settings($u);
    [, $token] = Payments::receiverFor($u);
    return [
        'user' => Account::publicRow($u) + ['plan_name' => $plan['name'] ?? null],
        'usage' => Account::usage($u),
        'brand' => Account::brand($u),
        'impersonating' => !empty($_SESSION['impersonator']),
        'panel' => $panel,
        'can_create' => Account::CHILDREN[$u['role']],
        'plans' => Account::isAdmin($u) ? [] : Payments::plansFor($u),
        'packages' => Account::isReseller($u) ? Settings::creditPackages() : [],
        'mp_enabled' => $token !== '' || (Account::isReseller($u) && Settings::get('mp_access_token') !== ''),
        'invite_url' => $panel && Settings::get('signup_enabled') === '1' && ($settings['signup'] ?? true)
            ? Settings::baseUrl() . (Account::isAdmin($u) ? '' : '?r=' . rawurlencode($u['username'])) : null,
        'roles' => Account::ROLES,
        'referral' => Referral::stats($u),
        'app' => [
            'in_app' => Settings::inApp(),
            'store_mode' => Settings::inApp() && Settings::get('app_store_mode') === '1',
            'apk_url' => Settings::apkUrl(),
        ],
        'csrf' => Auth::csrf(),
    ];
}

function need(bool $cond, string $msg = 'Sem permissão'): void
{
    if (!$cond) {
        throw new DomainException($msg);
    }
}

function mask(string $s): string
{
    return $s === '' ? '' : str_repeat('•', 8) . substr($s, -6);
}

try {
    /* ---------- Ações públicas ---------- */
    switch ($action) {
        case 'brand':
            json_out(Account::publicBrand((string) ($_GET['r'] ?? '')));
            return;

        case 'login':
            if (!Auth::configured()) {
                json_out(['error' => 'Conclua a instalação em install.php'], 400);
                return;
            }
            $err = Auth::attempt((string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''));
            if ($err) {
                json_out(['error' => $err], 401);
                return;
            }
            json_out(['ok' => true, 'csrf' => Auth::csrf()]);
            return;

        case 'signup':
            $b = Account::publicBrand((string) ($input['ref'] ?? ''));
            $referrer = !empty($input['invite']) && Referral::enabled() ? Account::byUsername((string) $input['invite']) : null;
            if ($referrer && $referrer['status'] !== 'active') {
                $referrer = null;
            }
            need($b['signup'] || $referrer, 'Cadastro desativado');
            $ipKey = 'signup:' . client_ip();
            need(Throttle::count($ipKey, 86400) < 3, 'Limite de cadastros atingido para esta rede. Tente amanhã.');
            $parent = $b['ref'] !== '' ? Account::byUsername($b['ref']) : Db::one("SELECT * FROM accounts WHERE role = 'admin' ORDER BY id LIMIT 1");
            if ($referrer) {
                // o indicado fica com a mesma revenda de quem indicou (ou com a própria revenda que indicou)
                $parent = $referrer['role'] === 'user' ? (Account::find((int) $referrer['parent_id']) ?? $parent) : $referrer;
            }
            $id = Account::create($parent, [
                'role' => 'user', 'trial' => 1, 'username' => $input['username'] ?? '', 'password' => $input['password'] ?? '',
                'name' => $input['name'] ?? '', 'email' => $input['email'] ?? '', 'phone' => $input['phone'] ?? '',
                'notes' => 'Cadastro pelo site',
            ]);
            Throttle::hit($ipKey);
            if ($referrer) {
                Db::exec('UPDATE accounts SET referred_by = ? WHERE id = ?', [$referrer['id'], $id]);
                Audit::log((int) $referrer['id'], 'referral.signup', $id, 'Novo indicado: ' . ($input['username'] ?? ''));
            }
            Auth::loginAs($id);
            json_out(['ok' => true, 'csrf' => Auth::csrf()]);
            return;
    }

    /* ---------- Daqui para baixo: precisa estar logado ---------- */
    $user = Auth::user();
    if (!$user) {
        json_out(['error' => 'auth'], 401);
        return;
    }
    if ($method === 'POST' && !Auth::verifyCsrf()) {
        json_out(['error' => 'Sessão expirada, recarregue a página'], 419);
        return;
    }
    $admin = Account::isAdmin($user);
    $reseller = Account::isReseller($user);
    $panel = $admin || $reseller;

    // Conta vencida: só pode ver a conta e pagar
    $whenExpired = ['me', 'logout', 'stop_impersonate', 'profile_save', 'pay_create', 'pay_status', 'payments', 'status', 'delete_me'];
    if (Account::expired($user) && !in_array($action, $whenExpired, true)) {
        json_out(['error' => 'expired', 'message' => 'Seu plano venceu. Renove para continuar ouvindo.'], 402);
        return;
    }

    switch ($action) {
        /* ===== Conta ===== */
        case 'me':
            json_out(me_payload($user));
            return;

        case 'logout':
            Auth::logout();
            json_out(['ok' => true]);
            return;

        case 'delete_me':
            need(empty($_SESSION['impersonator']), 'Saia do modo "entrar como" antes');
            Account::deleteSelf($user, (string) ($input['password'] ?? ''));
            Auth::logout();
            json_out(['ok' => true]);
            return;

        case 'stop_impersonate':
            json_out(['ok' => Auth::stopImpersonating(), 'csrf' => Auth::csrf()]);
            return;

        case 'profile_save':
            $set = [];
            foreach (['name' => 80, 'email' => 120, 'phone' => 30] as $f => $max) {
                if (isset($input[$f])) {
                    $set[$f] = mb_substr(trim((string) $input[$f]), 0, $max);
                }
            }
            if (!empty($set['email']) && !filter_var($set['email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('E-mail inválido');
            }
            if (!empty($input['password_new'])) {
                need(password_verify((string) ($input['password_current'] ?? ''), $user['password_hash']), 'Senha atual incorreta');
                need(mb_strlen((string) $input['password_new']) >= 6, 'A nova senha precisa ter 6+ caracteres');
                $set['password_hash'] = password_hash((string) $input['password_new'], PASSWORD_DEFAULT);
            }
            if ($set) {
                $sql = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($set)));
                Db::pdo()->prepare("UPDATE accounts SET $sql WHERE id = :id")->execute($set + ['id' => $user['id']]);
            }
            json_out(['ok' => true]);
            return;

        case 'status':
            $t = Tools::state();
            json_out([
                'version' => APP_VERSION,
                'youtube' => YouTube::available() || (bool) cfg('youtube_api_key'),
                'download' => YouTube::available(), 'ffmpeg' => (bool) $t['ffmpeg'],
                'audio_format' => Tools::audioFormat(), 'jamendo' => Jamendo::enabled(),
                'pending' => Jobs::pendingCount($user),
            ]);
            return;

        /* ===== Busca e downloads ===== */
        case 'search':
            $q = trim((string) ($_GET['q'] ?? ''));
            $src = (string) ($_GET['source'] ?? 'youtube');
            if (mb_strlen($q) < 2) {
                json_out(['items' => []]);
                return;
            }
            $q = mb_substr($q, 0, 120);
            $limit = max(1, min(100, (int) ($_GET['limit'] ?? cfg('max_results', 25))));
            session_write_close();
            @set_time_limit(240);
            $fallback = false;
            $artistInfo = null;
            if ($src === 'artist') {
                try {
                    $cat = Innertube::artistCatalog($q, 800);
                    $items = $cat['items'];
                    $artistInfo = $cat['artist'] + ['count' => count($items)];
                } catch (Throwable $e) {
                    $items = [];
                }
                if (!$items) {
                    $src = 'catalog_artist'; // reserva: discografia pelo catálogo iTunes
                }
            }
            if ($src === 'artist') {
                // já resolvido acima
            } elseif (in_array($src, ['catalog', 'catalog_artist'], true)) {
                try {
                    $items = Metadata::searchCatalog($q, $src === 'catalog_artist', $limit);
                } catch (Throwable $e) {
                    $items = [];
                }
                if (!$items) {
                    $items = YouTube::search($q, $limit);
                    $fallback = true;
                }
            } else {
                $items = match ($src) {
                    'youtube' => YouTube::search($q, $limit),
                    'jamendo' => Jamendo::search($q, $limit),
                    default => throw new InvalidArgumentException('Fonte inválida'),
                };
            }
            json_out(['items' => Library::annotate($items, $user), 'fallback' => $fallback, 'limit' => $limit, 'artist' => $artistInfo]);
            return;

        case 'download':
            $kind = (string) ($input['kind'] ?? 'audio');
            $items = $input['items'] ?? (isset($input['item']) ? [$input['item']] : []);
            $res = ['queued' => 0, 'exists' => 0, 'added' => 0, 'errors' => 0, 'results' => [], 'error_msg' => ''];
            foreach (array_slice((array) $items, 0, 300) as $item) {
                try {
                    $r = Jobs::enqueue((array) $item, $kind, $user);
                    $res[$r['status']]++;
                    $res['results'][] = $r;
                } catch (Throwable $e) {
                    $res['errors']++;
                    $res['error_msg'] = $e->getMessage();
                    $res['results'][] = ['status' => 'error', 'error' => $e->getMessage()];
                }
            }
            $res['jobs'] = Jobs::list($user, 30);
            if ($res['queued'] > 0 && !Worker::busy()) {
                respond_and_continue($res);
                Worker::run((int) cfg('worker_max_seconds', 270));
                return;
            }
            json_out($res);
            return;

        case 'jobs':
            $res = ['jobs' => Jobs::list($user, 100), 'pending' => Jobs::pendingCount($user)];
            if ((int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE status = 'queued'")['c'] ?? 0) > 0 && !Worker::busy()) {
                respond_and_continue($res);
                Worker::run((int) cfg('worker_max_seconds', 270));
                return;
            }
            json_out($res);
            return;

        case 'job_retry':
            Jobs::retry($user, (int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'job_cancel':
            Jobs::cancel($user, (int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'jobs_clear':
            Jobs::clearFinished($user);
            json_out(['ok' => true]);
            return;

        /* ===== Biblioteca ===== */
        case 'library':
            json_out(['tracks' => Library::all($user)]);
            return;

        case 'favorite':
            $tid = (int) ($input['id'] ?? 0);
            need(Library::canAccess($user, $tid));
            Library::touch($user, $tid, ['favorite' => !empty($input['value'])]);
            json_out(['ok' => true]);
            return;

        case 'played':
            $tid = (int) ($input['id'] ?? 0);
            if (Library::canAccess($user, $tid)) {
                Library::touch($user, $tid, ['played' => true]);
                Discovery::log($user, ['track_id' => $tid]);
            }
            json_out(['ok' => true]);
            return;

        case 'remove': // tira só da biblioteca do usuário
            Library::unlink((int) $user['id'], (int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'delete': // apaga o arquivo do servidor (só admin)
            need($admin, 'Apenas o administrador apaga arquivos do servidor');
            Library::delete((int) ($input['id'] ?? 0));
            Audit::log((int) $user['id'], 'track.delete', (int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'lyrics':
            if (empty($_GET['id']) && !empty($_GET['title'])) {
                // música tocando direto do YouTube (ainda não baixada)
                session_write_close();
                $a = mb_substr((string) ($_GET['artist'] ?? ''), 0, 120);
                $ti = mb_substr((string) $_GET['title'], 0, 160);
                $du = (int) ($_GET['duration'] ?? 0);
                json_out(Cache::remember('lyr:' . md5($a . '|' . $ti), 86400 * 7, fn() => Metadata::lyrics($a, $ti, '', $du)));
                return;
            }
            $t = Library::get((int) ($_GET['id'] ?? 0));
            if (!$t || !Library::canAccess($user, (int) $t['id'])) {
                json_out(['error' => 'not found'], 404);
                return;
            }
            session_write_close();
            if (!(int) $t['lyrics_checked'] || ((int) $t['lyrics_checked'] < time() - 86400 * 14 && !$t['lyrics_synced'] && !$t['lyrics_plain'])) {
                $l = Metadata::lyrics($t['artist'], $t['title'], (string) $t['album'], (int) $t['duration']);
                Db::exec('UPDATE tracks SET lyrics_synced = ?, lyrics_plain = ?, lyrics_checked = ? WHERE id = ?',
                    [$l['synced'], $l['plain'], time(), $t['id']]);
                $t['lyrics_synced'] = $l['synced'];
                $t['lyrics_plain'] = $l['plain'];
            }
            json_out(['synced' => $t['lyrics_synced'], 'plain' => $t['lyrics_plain']]);
            return;

        /* ===== Descoberta ===== */
        case 'play_log': // música tocada direto do YouTube
            Discovery::log($user, $input);
            json_out(['ok' => true]);
            return;

        case 'home':
            session_write_close();
            @set_time_limit(90);
            $safe = function (callable $fn) {
                try {
                    return $fn();
                } catch (Throwable $e) {
                    return [];
                }
            };
            $foryou = $safe(fn() => Discovery::forYou($user, 30));
            json_out([
                'trending' => Library::annotate(Discovery::trending(7, 24), $user),
                'top' => Library::annotate(Discovery::trending(3650, 30), $user),
                'recent' => Library::annotate(Discovery::recentByUser($user, 16), $user),
                'foryou' => Library::annotate($foryou, $user),
                'artists' => Discovery::topArtists($user, 12),
                'styles' => array_map(fn($s) => ['name' => $s[0], 'color' => $s[1]], Discovery::STYLES),
                'server_total' => (int) (Db::one('SELECT COUNT(*) c FROM tracks')['c'] ?? 0),
            ]);
            return;

        case 'style':
            session_write_close();
            json_out(['items' => Library::annotate(Discovery::style(mb_substr(trim((string) ($_GET['name'] ?? '')), 0, 60)), $user)]);
            return;

        case 'radio':
            session_write_close();
            $exclude = array_flip(explode(',', (string) ($_GET['exclude'] ?? '')));
            $items = array_values(array_filter(Innertube::radio((string) ($_GET['video_id'] ?? ''), 40), fn($i) => !isset($exclude[$i['source_id']])));
            json_out(['items' => Library::annotate($items, $user)]);
            return;

        case 'suggest_artists':
            session_write_close();
            $q = trim((string) ($_GET['q'] ?? ''));
            json_out(['artists' => mb_strlen($q) < 2 ? [] : Innertube::searchArtists(mb_substr($q, 0, 60), 8)]);
            return;

        case 'stream_fav':
            Discovery::setStreamFav($user, $input, !empty($input['value']));
            json_out(['ok' => true]);
            return;

        case 'stream_favs':
            json_out(['items' => Library::annotate(Discovery::streamFavs($user), $user)]);
            return;

        /* ===== Acervo compartilhado ===== */
        case 'tracks':
            $ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))), 0, 500);
            json_out(['tracks' => $ids ? Library::rows($user, '', 't.id IN (' . implode(',', $ids) . ')', [], 't.id', 500) : []]);
            return;

        case 'explore':
            $q = trim((string) ($_GET['q'] ?? ''));
            $genre = trim((string) ($_GET['genre'] ?? ''));
            if ($q !== '' || $genre !== '') {
                $like = '%' . $q . '%';
                $tracks = $genre !== ''
                    ? Library::rows($user, '', 't.genre = ?', [$genre], 't.plays DESC, t.created_at DESC', 1000)
                    : Library::rows($user, '', '(t.title LIKE ? OR t.artist LIKE ? OR t.album LIKE ?)', [$like, $like, $like], 't.plays DESC', 400);
                json_out(['tracks' => $tracks]);
                return;
            }
            json_out([
                'top' => Library::rows($user, '', 't.plays > 0', [], 't.plays DESC', 30),
                'recent' => Library::rows($user, '', '', [], 't.created_at DESC', 30),
                'genres' => array_map(fn($r) => ['name' => $r['genre'], 'count' => (int) $r['c']], Db::all('SELECT genre, COUNT(*) c FROM tracks GROUP BY genre ORDER BY c DESC LIMIT 40')),
                'artists' => array_map(fn($r) => ['name' => $r['artist'], 'count' => (int) $r['c'], 'cover' => (int) $r['cover']],
                    Db::all("SELECT artist, COUNT(*) c, MAX(CASE WHEN cover_path <> '' THEN id END) cover FROM tracks GROUP BY artist ORDER BY SUM(plays) DESC, c DESC LIMIT 24")),
                'total' => (int) (Db::one('SELECT COUNT(*) c FROM tracks')['c'] ?? 0),
            ]);
            return;

        case 'playlists':
            json_out(['playlists' => Playlists::list($user)]);
            return;

        case 'playlist':
            $p = Playlists::owned($user, (int) ($_GET['id'] ?? 0));
            json_out(['playlist' => ['id' => (int) $p['id'], 'name' => $p['name']], 'tracks' => Playlists::tracks($user, (int) $p['id'])]);
            return;

        case 'playlist_save':
            $id = Playlists::save($user, (int) ($input['id'] ?? 0), (string) ($input['name'] ?? ''));
            $added = !empty($input['track_ids']) ? Playlists::add($user, $id, (array) $input['track_ids']) : 0;
            json_out(['ok' => true, 'id' => $id, 'added' => $added]);
            return;

        case 'playlist_add':
            json_out(['ok' => true, 'added' => Playlists::add($user, (int) ($input['id'] ?? 0), (array) ($input['track_ids'] ?? []))]);
            return;

        case 'playlist_remove':
            Playlists::remove($user, (int) ($input['id'] ?? 0), (int) ($input['track_id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'playlist_reorder':
            Playlists::reorder($user, (int) ($input['id'] ?? 0), (array) ($input['order'] ?? []));
            json_out(['ok' => true]);
            return;

        case 'playlist_delete':
            Playlists::delete($user, (int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        /* ===== Pagamentos ===== */
        case 'pay_create':
            json_out(['payment' => Payments::create($user, $input)]);
            return;

        case 'pay_status':
            $p = Db::one('SELECT * FROM payments WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
            need($p && ((int) $p['account_id'] === (int) $user['id'] || $admin), 'Pagamento não encontrado');
            session_write_close();
            json_out(['payment' => Payments::publicRow(Payments::refresh($p))]);
            return;

        case 'payments':
            json_out(['payments' => Payments::list($user)]);
            return;
    }

    /* ======================= Painel (admin / revendas) ======================= */
    need($panel, 'Área restrita a revendas e administradores');

    switch ($action) {
        case 'dashboard':
            $scope = $admin ? "role <> 'admin'" : 'path LIKE ' . Db::pdo()->quote($user['path'] . $user['id'] . '/%');
            $now = time();
            $count = fn(string $w) => (int) (Db::one("SELECT COUNT(*) c FROM accounts WHERE $scope AND $w")['c'] ?? 0);
            $recv = $admin ? 0 : (int) $user['id'];
            $month = strtotime(date('Y-m-01'));
            $series = [];
            for ($i = 13; $i >= 0; $i--) {
                $d0 = strtotime("today -$i days");
                $series[] = [
                    'day' => date('d/m', $d0),
                    'signups' => $count("created_at >= $d0 AND created_at < " . ($d0 + 86400)),
                    'revenue' => (float) (Db::one('SELECT COALESCE(SUM(amount),0) s FROM payments WHERE status = \'approved\' AND receiver_id = ? AND approved_at >= ? AND approved_at < ?', [$recv, $d0, $d0 + 86400])['s'] ?? 0),
                ];
            }
            if ($admin) {
                Payments::refreshPending();
            }
            $expiring = Db::all("SELECT * FROM accounts WHERE $scope AND expires_at > ? AND expires_at <= ? ORDER BY expires_at LIMIT 12", [$now, $now + 7 * 86400]);
            json_out([
                'users' => $count("role = 'user'"),
                'active' => $count("role = 'user' AND status = 'active' AND (expires_at IS NULL OR expires_at > $now)"),
                'expired' => $count("role = 'user' AND expires_at IS NOT NULL AND expires_at <= $now"),
                'trials' => $count("role = 'user' AND is_trial = 1 AND expires_at > $now"),
                'expiring' => $count("expires_at > $now AND expires_at <= " . ($now + 7 * 86400)),
                'resellers' => $count("role = 'reseller'"),
                'masters' => $count("role = 'master'"),
                'credits' => $admin ? null : (int) $user['credits'],
                'credits_out' => (int) (Db::one("SELECT COALESCE(SUM(credits),0) s FROM accounts WHERE $scope")['s'] ?? 0),
                'revenue_month' => (float) (Db::one("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE status = 'approved' AND receiver_id = ? AND approved_at >= ?", [$recv, $month])['s'] ?? 0),
                'series' => $series,
                'expiring_list' => array_map([Account::class, 'publicRow'], $expiring),
                'tracks' => $admin ? (int) (Db::one('SELECT COUNT(*) c FROM tracks')['c'] ?? 0) : null,
                'storage' => $admin ? (int) (Db::one('SELECT COALESCE(SUM(size),0) s FROM tracks')['s'] ?? 0) : null,
                'disk_free' => $admin ? @disk_free_space(storage_path()) : null,
                'jobs_pending' => $admin ? Jobs::pendingCount() : null,
                'tools' => $admin ? ['ytdlp' => (bool) Tools::ytdlp(), 'ffmpeg' => (bool) Tools::ffmpeg()] : null,
            ]);
            return;

        case 'accounts':
            json_out(['accounts' => Account::list($user, $_GET)]);
            return;

        case 'account_save':
            if (!empty($input['id'])) {
                Account::update($user, (int) $input['id'], $input);
                $id = (int) $input['id'];
            } else {
                $id = Account::create($user, $input);
            }
            json_out(['ok' => true, 'id' => $id, 'me' => Account::publicRow(Account::find((int) $user['id']))]);
            return;

        case 'account_renew':
            Account::renew($user, (int) ($input['id'] ?? 0), (int) ($input['plan_id'] ?? 0), (int) ($input['periods'] ?? 1));
            json_out(['ok' => true, 'me' => Account::publicRow(Account::find((int) $user['id']))]);
            return;

        case 'account_credits':
            Account::transfer($user, (int) ($input['id'] ?? 0), (int) ($input['amount'] ?? 0));
            json_out(['ok' => true, 'me' => Account::publicRow(Account::find((int) $user['id']))]);
            return;

        case 'account_delete':
            Account::delete($user, (int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'impersonate':
            Auth::impersonate($user, (int) ($input['id'] ?? 0));
            json_out(['ok' => true, 'csrf' => Auth::csrf()]);
            return;

        case 'plans':
            json_out(['plans' => array_map(fn($p) => Plans::publicRow($p), Plans::all(!$admin))]);
            return;

        case 'plan_save':
            need($admin);
            json_out(['ok' => true, 'id' => Plans::save($input)]);
            return;

        case 'plan_delete':
            need($admin);
            Plans::delete((int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'settings':
            $s = Account::settings($user);
            $out = [
                'mine' => [
                    'brand' => ($s['brand'] ?? []) + ['logo_url' => !empty($s['brand']['logo']) ? Brand::logoUrl('acc' . $user['id'], $s['brand']['logo']) : ''],
                    'mp_token' => mask((string) ($s['mp']['token'] ?? '')),
                    'prices' => $s['prices'] ?? new stdClass(),
                    'signup' => $s['signup'] ?? true,
                    'can_brand' => (bool) $user['can_brand'],
                    'reseller_mp' => Settings::get('reseller_mp') === '1',
                ],
                'webhook' => Settings::baseUrl() . 'api.php?action=mp_webhook&r=' . ($admin ? 0 : (int) $user['id']),
            ];
            if ($admin) {
                $g = Settings::all();
                $g['mp_access_token'] = mask($g['mp_access_token']);
                $g['logo_url'] = $g['brand_logo'] ? Brand::logoUrl('global', $g['brand_logo']) : '';
                $out['global'] = $g;
            }
            json_out($out);
            return;

        case 'settings_save':
            need($admin);
            $vals = (array) ($input['global'] ?? []);
            if (isset($vals['mp_access_token']) && str_contains((string) $vals['mp_access_token'], '•')) {
                unset($vals['mp_access_token']); // não alterado
            }
            foreach (['brand_color', 'brand_color2'] as $c) {
                if (isset($vals[$c]) && !preg_match('/^#[0-9a-f]{6}$/i', (string) $vals[$c])) {
                    unset($vals[$c]);
                }
            }
            unset($vals['brand_logo']);
            Settings::set($vals);
            Audit::log((int) $user['id'], 'settings.save', 0, implode(', ', array_keys($vals)));
            json_out(['ok' => true]);
            return;

        case 'my_settings_save':
            need($reseller, 'Use as configurações globais');
            $s = Account::settings($user);
            $in = (array) ($input['mine'] ?? []);
            if ((int) $user['can_brand'] && isset($in['brand'])) {
                $b = (array) $in['brand'];
                $s['brand'] = [
                    'name' => mb_substr(trim((string) ($b['name'] ?? '')), 0, 40),
                    'tagline' => mb_substr(trim((string) ($b['tagline'] ?? '')), 0, 80),
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($b['color'] ?? '')) ? $b['color'] : '#8b5cf6',
                    'color2' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($b['color2'] ?? '')) ? $b['color2'] : '#22d3ee',
                    'support_url' => filter_var($b['support_url'] ?? '', FILTER_VALIDATE_URL) ? $b['support_url'] : '',
                    'logo' => $s['brand']['logo'] ?? '',
                ];
            }
            if (isset($in['mp_token']) && !str_contains((string) $in['mp_token'], '•')) {
                $s['mp'] = ['token' => trim((string) $in['mp_token'])];
            }
            if (isset($in['prices'])) {
                $s['prices'] = [];
                foreach ((array) $in['prices'] as $pid => $price) {
                    if ((float) $price > 0) {
                        $s['prices'][(string) (int) $pid] = round((float) $price, 2);
                    }
                }
            }
            if (isset($in['signup'])) {
                $s['signup'] = (bool) $in['signup'];
            }
            Account::saveSettings((int) $user['id'], $s);
            Audit::log((int) $user['id'], 'settings.mine', 0);
            json_out(['ok' => true]);
            return;

        case 'brand_upload':
            $target = (string) ($_POST['target'] ?? 'mine');
            if ($target === 'global') {
                need($admin);
                Settings::set(['brand_logo' => Brand::saveUpload($_FILES['logo'] ?? [], 'global')]);
            } else {
                need($reseller && (int) $user['can_brand'], 'Seu plano não permite marca própria');
                $s = Account::settings($user);
                $s['brand']['logo'] = Brand::saveUpload($_FILES['logo'] ?? [], 'acc' . $user['id']);
                Account::saveSettings((int) $user['id'], $s);
            }
            json_out(['ok' => true]);
            return;

        case 'brand_logo_remove':
            if (($input['target'] ?? '') === 'global') {
                need($admin);
                Settings::set(['brand_logo' => '']);
            } else {
                $s = Account::settings($user);
                unset($s['brand']['logo']);
                Account::saveSettings((int) $user['id'], $s);
            }
            json_out(['ok' => true]);
            return;

        case 'mp_test':
            $token = trim((string) ($input['token'] ?? ''));
            if ($token === '' || str_contains($token, '•')) {
                $token = $admin && ($input['target'] ?? '') === 'global' ? Settings::get('mp_access_token') : (string) (Account::settings($user)['mp']['token'] ?? '');
            }
            json_out(['ok' => true, 'account' => MercadoPago::whoami($token)]);
            return;

        case 'agent_status':
            need($admin);
            $seen = (int) Settings::get('agent_seen');
            json_out([
                'online' => Agent::online(), 'last_seen' => $seen ?: null, 'configured' => Agent::configured(),
                'waiting' => (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE status IN ('queued','agent') AND source IN ('youtube','itunes')")['c'] ?? 0),
                'done' => (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE status = 'done' AND message LIKE '%agente%'")['c'] ?? 0),
                'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size'),
            ]);
            return;

        case 'agent_script':
            need($admin);
            if (!empty($_GET['regen'])) {
                Agent::regenerate();
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="Agente de download.bat"');
            header('Cache-Control: no-store');
            echo Agent::windowsScript();
            return;

        case 'logs':
            if ($admin) {
                $rows = Db::all('SELECT l.*, a.username actor, t.username target FROM audit l LEFT JOIN accounts a ON a.id = l.actor_id LEFT JOIN accounts t ON t.id = l.target_id ORDER BY l.id DESC LIMIT 300');
            } else {
                $rows = Db::all('SELECT l.*, a.username actor, t.username target FROM audit l LEFT JOIN accounts a ON a.id = l.actor_id LEFT JOIN accounts t ON t.id = l.target_id
                    WHERE l.actor_id = ? OR a.path LIKE ? ORDER BY l.id DESC LIMIT 300', [$user['id'], $user['path'] . $user['id'] . '/%']);
            }
            json_out(['logs' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'action' => $r['action'], 'actor' => $r['actor'], 'target' => $r['target'],
                'info' => $r['info'], 'ip' => $admin ? $r['ip'] : '', 'created_at' => (int) $r['created_at'],
            ], $rows)]);
            return;

        default:
            json_out(['error' => 'Ação desconhecida'], 404);
    }
} catch (DomainException | InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 500);
}
