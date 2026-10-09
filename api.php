<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
start_session();

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
}

try {
    if ($action === 'login') {
        if (!Auth::configured()) {
            json_out(['error' => 'Defina a senha em install.php'], 400);
            return;
        }
        if (!Auth::attempt((string) ($input['password'] ?? ''))) {
            json_out(['error' => 'Senha incorreta'], 401);
            return;
        }
        json_out(['ok' => true, 'csrf' => Auth::csrf()]);
        return;
    }

    if (!Auth::check()) {
        json_out(['error' => 'auth'], 401);
        return;
    }
    if ($method === 'POST' && !Auth::verifyCsrf()) {
        json_out(['error' => 'Sessão expirada, recarregue a página'], 419);
        return;
    }

    switch ($action) {
        case 'logout':
            Auth::logout();
            json_out(['ok' => true]);
            return;

        case 'status':
            $t = Tools::state();
            json_out([
                'app' => cfg('app_name'), 'version' => APP_VERSION,
                'youtube' => YouTube::available() || (bool) cfg('youtube_api_key'),
                'download' => YouTube::available(), 'ffmpeg' => (bool) $t['ffmpeg'],
                'audio_format' => Tools::audioFormat(), 'jamendo' => Jamendo::enabled(),
                'pending' => Jobs::pendingCount(),
            ]);
            return;

        case 'search':
            $q = trim((string) ($_GET['q'] ?? ''));
            $src = (string) ($_GET['source'] ?? 'catalog');
            if (mb_strlen($q) < 2) {
                json_out(['items' => []]);
                return;
            }
            $q = mb_substr($q, 0, 120);
            $limit = (int) cfg('max_results', 25);
            session_write_close();
            $items = match ($src) {
                'catalog' => Metadata::searchCatalog($q, false, $limit),
                'artist' => Metadata::searchCatalog($q, true, $limit),
                'youtube' => YouTube::search($q, $limit),
                'jamendo' => Jamendo::search($q, $limit),
                default => throw new InvalidArgumentException('Fonte inválida'),
            };
            json_out(['items' => Library::annotate($items)]);
            return;

        case 'download':
            $kind = (string) ($input['kind'] ?? 'audio');
            $items = $input['items'] ?? (isset($input['item']) ? [$input['item']] : []);
            $res = ['queued' => 0, 'exists' => 0, 'errors' => 0, 'results' => []];
            foreach (array_slice((array) $items, 0, 200) as $item) {
                try {
                    $r = Jobs::enqueue((array) $item, $kind);
                    $res[$r['status']]++;
                    $res['results'][] = $r;
                } catch (Throwable $e) {
                    $res['errors']++;
                    $res['results'][] = ['status' => 'error', 'error' => $e->getMessage()];
                }
            }
            $res['jobs'] = Jobs::list(30);
            if ($res['queued'] > 0 && !Worker::busy()) {
                respond_and_continue($res);
                Worker::run((int) cfg('worker_max_seconds', 270));
                return;
            }
            json_out($res);
            return;

        case 'jobs':
            $res = ['jobs' => Jobs::list(100), 'pending' => Jobs::pendingCount()];
            // "auto-cura": se há fila e nenhum processador rodando, inicia um
            if ($res['pending'] > 0 && !Worker::busy()) {
                respond_and_continue($res);
                Worker::run((int) cfg('worker_max_seconds', 270));
                return;
            }
            json_out($res);
            return;

        case 'job_retry':
            Jobs::retry((int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'job_cancel':
            Jobs::cancel((int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'jobs_clear':
            Jobs::clearFinished();
            json_out(['ok' => true]);
            return;

        case 'library':
            json_out(['tracks' => Library::all()]);
            return;

        case 'favorite':
            Db::exec('UPDATE tracks SET favorite = ? WHERE id = ?', [!empty($input['value']) ? 1 : 0, (int) ($input['id'] ?? 0)]);
            json_out(['ok' => true]);
            return;

        case 'played':
            Db::exec('UPDATE tracks SET plays = plays + 1, last_played = ? WHERE id = ?', [time(), (int) ($input['id'] ?? 0)]);
            json_out(['ok' => true]);
            return;

        case 'delete':
            Library::delete((int) ($input['id'] ?? 0));
            json_out(['ok' => true]);
            return;

        case 'lyrics':
            $t = Library::get((int) ($_GET['id'] ?? 0));
            if (!$t) {
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

        default:
            json_out(['error' => 'Ação desconhecida'], 404);
    }
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 500);
}
