<?php
declare(strict_types=1);

/**
 * Processa a fila de downloads. Roda:
 *  - automaticamente após cada pedido de download (em segundo plano, sem cron), ou
 *  - pelo cron da Hostinger:  php /caminho/worker.php
 * Um lock de arquivo garante que só um processador rode por vez.
 */
final class Worker
{
    private static $lock = null;

    private static function acquire(): bool
    {
        $fp = fopen(storage_path('data/worker.lock'), 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            return false;
        }
        self::$lock = $fp;
        return true;
    }

    public static function busy(): bool
    {
        $fp = fopen(storage_path('data/worker.lock'), 'c');
        if (!$fp) {
            return false;
        }
        $free = flock($fp, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return !$free;
    }

    public static function run(int $maxSeconds): int
    {
        if (!self::acquire()) {
            return 0;
        }
        @set_time_limit($maxSeconds + 120);
        $start = time();
        $done = 0;
        // jobs "running" órfãos (processo morto pelo servidor) voltam para a fila
        Db::exec("UPDATE jobs SET status = CASE WHEN attempts >= 3 THEN 'error' ELSE 'queued' END,
                  message = CASE WHEN attempts >= 3 THEN 'Interrompido várias vezes' ELSE message END
                  WHERE status = 'running' AND updated_at < ?", [time() - 180]);
        while (time() - $start < $maxSeconds) {
            $job = Db::one("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1");
            if (!$job) {
                break;
            }
            Jobs::update((int) $job['id'], ['status' => 'running', 'progress' => 0, 'message' => 'Preparando', 'attempts' => (int) $job['attempts'] + 1]);
            try {
                [$trackId, $msg] = self::process($job);
                Jobs::update((int) $job['id'], ['status' => 'done', 'progress' => 100, 'message' => $msg, 'track_id' => $trackId]);
            } catch (Throwable $e) {
                Jobs::update((int) $job['id'], ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 400)]);
            }
            $done++;
        }
        flock(self::$lock, LOCK_UN);
        fclose(self::$lock);
        self::$lock = null;
        return $done;
    }

    /** @return array{0:int,1:string} [track_id, mensagem] */
    private static function process(array $job): array
    {
        $id = (int) $job['id'];
        $kind = $job['kind'];
        $p = json_decode((string) $job['payload'], true) ?: [];
        $last = 0.0;
        $progress = function (float $pct, string $msg = 'Baixando') use ($id, &$last) {
            if (microtime(true) - $last >= 1.0) {
                $last = microtime(true);
                Jobs::update($id, ['progress' => round($pct, 1), 'message' => $msg]);
            }
        };

        // 1) Metadados definitivos (artista, título, gênero, capa)
        $youtubeId = '';
        $downloadUrl = '';
        switch ($job['source']) {
            case 'itunes':
                try {
                    $m = Metadata::lookup($job['source_id']);
                } catch (Throwable $e) {
                    $m = null; // iTunes fora do ar: segue com os dados da busca
                }
                $m = $m ?? [
                    'title' => (string) ($p['title'] ?? ''), 'artist' => (string) ($p['artist'] ?? ''),
                    'album' => (string) ($p['album'] ?? ''), 'genre' => (string) ($p['genre'] ?? ''),
                    'year' => (string) ($p['year'] ?? ''), 'duration' => (int) ($p['duration'] ?? 0), 'thumb' => '',
                ];
                $progress(1, 'Procurando melhor fonte');
                $yt = YouTube::resolve($m['artist'], $m['title'], (int) $m['duration']);
                if (!$yt) {
                    throw new RuntimeException('Nenhuma fonte encontrada para esta música');
                }
                $youtubeId = $yt['source_id'];
                break;
            case 'youtube':
                $youtubeId = $job['source_id'];
                [$artist, $title] = isset($p['raw_title'])
                    ? Text::parseVideoTitle((string) $p['raw_title'], (string) ($p['channel'] ?? ''))
                    : [(string) ($p['artist'] ?? ''), (string) ($p['title'] ?? '')];
                $m = ['title' => $title, 'artist' => $artist, 'album' => '', 'genre' => '', 'year' => '',
                      'duration' => (int) ($p['duration'] ?? 0), 'thumb' => "https://i.ytimg.com/vi/{$youtubeId}/hqdefault.jpg"];
                $progress(1, 'Identificando gênero');
                $e = Metadata::enrich($artist, $title);
                if ($e) {
                    $m['artist'] = $e['artist'] ?: $m['artist'];
                    $m['genre'] = $e['genre'] ?? '';
                    if (isset($e['title'])) {
                        $m['title'] = $e['title'];
                        $m['album'] = $e['album'];
                        $m['year'] = $e['year'];
                        $m['thumb'] = $e['thumb'] ?: $m['thumb'];
                        if (!$m['duration']) {
                            $m['duration'] = $e['duration'];
                        }
                    }
                }
                break;
            case 'jamendo':
                $m = Jamendo::get($job['source_id']);
                if (!$m || $m['download_url'] === '') {
                    throw new RuntimeException('Faixa Jamendo indisponível');
                }
                $downloadUrl = $m['download_url'];
                break;
            default:
                throw new RuntimeException('Origem desconhecida');
        }

        // 2) Checagem final de duplicidade (agora com o nome "oficial")
        $key = Text::key($m['artist'], $m['title']);
        $existing = Library::findExisting($job['source'], $job['source_id'], $kind, $key, $youtubeId);
        if ($existing) {
            return [(int) $existing['id'], 'Já estava na biblioteca'];
        }

        // 3) Download
        $tmp = storage_path('tmp/job_' . $id);
        self::rrmdir($tmp);
        @mkdir($tmp, 0755, true);
        try {
            if ($downloadUrl !== '') {
                $file = $tmp . '/media.mp3';
                Http::download($downloadUrl, $file, fn($pct) => $progress(min(97, $pct)));
            } else {
                $file = YouTube::download($youtubeId, $kind, $tmp, $progress);
            }
            $progress(99, 'Organizando');

            // 4) Organiza em library/Gênero/Artista/Música.ext
            $genre = Text::safeName($m['genre'] ?: 'Outros', 'Outros');
            $artistDir = Text::safeName(Text::primaryArtist($m['artist']), 'Desconhecido');
            $relDir = $genre . '/' . $artistDir;
            @mkdir(Library::abs($relDir), 0755, true);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $base = Text::safeName($m['title'], 'Faixa ' . $id) . ($kind === 'video' ? ' (vídeo)' : '');
            $name = $base;
            for ($n = 2; is_file(Library::abs("$relDir/$name.$ext")); $n++) {
                $name = "$base ($n)";
            }
            $rel = "$relDir/$name.$ext";
            if (!@rename($file, Library::abs($rel))) {
                if (!@copy($file, Library::abs($rel))) {
                    throw new RuntimeException('Sem permissão para gravar em storage/library');
                }
            }

            // capa
            $coverRel = '';
            if (!empty($m['thumb'])) {
                try {
                    $coverRel = "$relDir/$name.jpg";
                    Http::download((string) $m['thumb'], Library::abs($coverRel), null, 30);
                } catch (Throwable $e) {
                    $coverRel = '';
                }
            }
        } finally {
            self::rrmdir($tmp);
        }

        $trackId = Db::insert('tracks', [
            'source' => $job['source'], 'source_id' => $job['source_id'], 'kind' => $kind, 'dedup_key' => $key,
            'title' => $m['title'], 'artist' => $m['artist'], 'album' => (string) $m['album'], 'genre' => $genre,
            'year' => (string) $m['year'], 'duration' => (int) $m['duration'], 'file_path' => $rel,
            'mime' => Library::MIME[$ext] ?? 'application/octet-stream', 'size' => (int) filesize(Library::abs($rel)),
            'cover_path' => $coverRel, 'youtube_id' => $youtubeId, 'created_at' => time(),
        ]);
        return [$trackId, 'Pronto para ouvir'];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $p = "$dir/$f";
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
