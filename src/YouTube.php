<?php
declare(strict_types=1);

/** Busca e download via yt-dlp (https://github.com/yt-dlp/yt-dlp) */
final class YouTube
{
    public static function available(): bool
    {
        return Tools::ytdlp() !== null;
    }

    private static function baseArgs(): array
    {
        $args = ['--ignore-config', '--no-warnings', '--no-playlist'];
        if (is_file(storage_path('data/cookies.txt'))) {
            $args[] = '--cookies';
            $args[] = storage_path('data/cookies.txt');
        }
        $proxy = trim(Settings::get('yt_proxy'));
        if ($proxy !== '' && preg_match('#^(https?|socks5h?)://#i', $proxy)) {
            $args[] = '--proxy';
            $args[] = $proxy;
        }
        return $args;
    }

    /**
     * Busca no YouTube: 1) API oficial (se houver chave) 2) Innertube direto pelo PHP (rápido,
     * sem processos) 3) yt-dlp como reserva.
     */
    public static function search(string $q, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $errors = [];
        if (cfg('youtube_api_key')) {
            try {
                return self::searchApi($q, $limit);
            } catch (Throwable $e) {
                $errors[] = 'API: ' . $e->getMessage();
            }
        }
        try {
            $items = Innertube::search($q, $limit);
            if ($items) {
                return $items;
            }
        } catch (Throwable $e) {
            $errors[] = 'YouTube: ' . $e->getMessage();
        }
        if (self::available()) {
            try {
                return self::searchYtdlp($q, $limit);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) {
            throw new RuntimeException('Busca no YouTube falhou — ' . implode(' | ', $errors));
        }
        return [];
    }

    public static function searchYtdlp(string $q, int $limit): array
    {
        if (!self::available()) {
            throw new RuntimeException('yt-dlp não está instalado. Abra install.php para instalar.');
        }
        return Cache::remember('yt:' . md5($q . $limit), 3600 * 6, function () use ($q, $limit) {
            $cmd = array_merge(Tools::ytdlp(), self::baseArgs(), ['--flat-playlist', '-J', "ytsearch{$limit}:{$q}"]);
            [$code, $out, $err] = Sys::run($cmd, 90);
            $data = json_decode($out, true);
            if ($code !== 0 || !is_array($data)) {
                throw new RuntimeException('Busca no YouTube falhou: ' . Sys::lastLines($err));
            }
            $items = [];
            foreach ($data['entries'] ?? [] as $e) {
                if (empty($e['id']) || strlen((string) $e['id']) !== 11) {
                    continue;
                }
                $items[] = self::mapEntry(
                    (string) $e['id'],
                    (string) ($e['title'] ?? ''),
                    (string) ($e['channel'] ?? $e['uploader'] ?? ''),
                    (int) ($e['duration'] ?? 0),
                    (int) ($e['view_count'] ?? 0)
                );
            }
            return $items;
        });
    }

    private static function searchApi(string $q, int $limit): array
    {
        $key = (string) cfg('youtube_api_key');
        return Cache::remember('yta:' . md5($q . $limit), 3600 * 6, function () use ($q, $limit, $key) {
            $s = Http::json('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
                'part' => 'snippet', 'type' => 'video', 'maxResults' => min(50, $limit), 'q' => $q, 'key' => $key,
                'regionCode' => cfg('country', 'BR'), 'videoEmbeddable' => 'true',
            ]));
            $ids = array_values(array_filter(array_map(fn($i) => $i['id']['videoId'] ?? null, $s['items'] ?? [])));
            if (!$ids) {
                return [];
            }
            $v = Http::json('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
                'part' => 'snippet,contentDetails,statistics', 'id' => implode(',', $ids), 'key' => $key,
            ]));
            $items = [];
            foreach ($v['items'] ?? [] as $i) {
                $dur = 0;
                try {
                    $d = new DateInterval((string) ($i['contentDetails']['duration'] ?? 'PT0S'));
                    $dur = $d->d * 86400 + $d->h * 3600 + $d->i * 60 + $d->s;
                } catch (Throwable $e) {
                }
                $items[] = self::mapEntry(
                    (string) $i['id'],
                    html_entity_decode((string) $i['snippet']['title'], ENT_QUOTES),
                    (string) $i['snippet']['channelTitle'],
                    $dur,
                    (int) ($i['statistics']['viewCount'] ?? 0)
                );
            }
            return $items;
        });
    }

    public static function mapEntry(string $id, string $rawTitle, string $channel, int $duration, int $views): array
    {
        [$artist, $title] = Text::parseVideoTitle($rawTitle, $channel);
        return [
            'source' => 'youtube',
            'source_id' => $id,
            'title' => $title,
            'artist' => $artist,
            'raw_title' => $rawTitle,
            'channel' => $channel,
            'album' => '',
            'genre' => '',
            'duration' => $duration,
            'views' => $views,
            'thumb' => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
            'kinds' => ['audio', 'video'],
        ];
    }

    /** Encontra o melhor vídeo para uma música do catálogo (prefere áudio oficial) */
    public static function resolve(string $artist, string $title, int $duration): ?array
    {
        $results = self::search(Text::primaryArtist($artist) . ' - ' . $title . ' audio', 8);
        $bad = '/\b(ao vivo|live|cover|karaok[eê]|remix|instrumental|playback|8d|slowed|sped up|reaction|react|tutorial|aula|nightcore)\b/iu';
        $best = null;
        $bestScore = -INF;
        foreach ($results as $i => $r) {
            $score = 100 - $i * 4;
            $score += Text::similarity($title, $r['title']) * 0.6;
            $score += Text::similarity(Text::primaryArtist($artist), $r['artist'] . ' ' . $r['channel']) * 0.4;
            if (preg_match($bad, $r['raw_title']) && !preg_match($bad, $title)) {
                $score -= 120;
            }
            if (preg_match('/- Topic$|VEVO$/i', $r['channel'])) {
                $score += 40;
            }
            if ($duration > 0 && $r['duration'] > 0) {
                $diff = abs($r['duration'] - $duration);
                $score -= $diff <= 4 ? 0 : min(150, $diff * 3);
            }
            if ($r['duration'] > 1200) {
                $score -= 200;
            }
            if ($score > $bestScore) {
                $best = $r;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * Baixa (e converte). 1º yt-dlp direto do YouTube; se o YouTube bloquear o IP do servidor
     * ("não é um robô") ou o yt-dlp faltar, baixa por servidores alternativos (Mirrors).
     */
    public static function download(string $id, string $kind, string $tmpDir, callable $progress): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new InvalidArgumentException('ID de vídeo inválido');
        }
        $errors = [];
        $flag = storage_path('data/yt_blocked');
        $blocked = is_file($flag) && filemtime($flag) > time() - 3600;
        if (self::available() && !$blocked) {
            try {
                return self::downloadYtdlp($id, $kind, $tmpDir, $progress);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                if (str_contains($e->getMessage(), 'anti-robô')) {
                    @touch($flag); // pula o yt-dlp por 1 h (vai direto aos servidores alternativos)
                }
                foreach (glob($tmpDir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
            }
        }
        if (Settings::get('mirrors_enabled') === '1') {
            try {
                $progress(1, 'Tentando servidor alternativo');
                $file = Mirrors::download($id, $kind, $tmpDir, $progress);
                return $kind === 'video' ? $file : Mirrors::convert($file, $tmpDir, $progress);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new RuntimeException(implode(' · ', $errors) ?: 'yt-dlp não está instalado. Abra install.php.');
    }

    public static function downloadYtdlp(string $id, string $kind, string $tmpDir, callable $progress): string
    {
        $ff = Tools::ffmpeg();
        $args = array_merge(self::baseArgs(), [
            '--newline', '--no-mtime', '--concurrent-fragments', '4',
            '-o', $tmpDir . '/media.%(ext)s',
        ]);
        if ($ff) {
            $args[] = '--ffmpeg-location';
            $args[] = $ff;
        }
        if ($kind === 'video') {
            $h = (int) cfg('video_max_height', 720);
            if ($ff) {
                array_push($args, '-f', "bv*[height<={$h}][ext=mp4]+ba[ext=m4a]/b[height<={$h}][ext=mp4]/bv*[height<={$h}]+ba/b",
                    '--merge-output-format', 'mp4');
            } else {
                array_push($args, '-f', "b[height<={$h}][ext=mp4]/b[ext=mp4]/b");
            }
        } else {
            $fmt = Tools::audioFormat();
            $kbps = max(64, min(320, (int) cfg('audio_bitrate', 128)));
            if ($fmt === 'mp3') {
                array_push($args, '-f', 'bestaudio/best', '-x', '--audio-format', 'mp3', '--audio-quality', $kbps . 'K');
            } elseif ($fmt === 'opus') {
                array_push($args, '-f', 'bestaudio/best', '-x', '--audio-format', 'opus', '--audio-quality', max(64, (int) round($kbps * 0.75)) . 'K');
            } else {
                array_push($args, '-f', 'bestaudio[ext=m4a]/bestaudio[acodec^=mp4a]/bestaudio/best');
            }
        }
        $args[] = 'https://www.youtube.com/watch?v=' . $id;

        $tail = [];
        [$code, , $err] = Sys::run(array_merge(Tools::ytdlp(), $args), 1800, function (string $line) use ($progress, &$tail) {
            if (preg_match('/\[download\]\s+([\d.]+)%/', $line, $m)) {
                $progress(min(97.0, (float) $m[1]), 'Baixando');
            } elseif (preg_match('/^\[(ExtractAudio|Merger|VideoConvertor|FFmpeg)/', $line)) {
                $progress(98.0, 'Convertendo');
            }
            $tail[] = $line;
            if (count($tail) > 6) {
                array_shift($tail);
            }
        });
        $files = array_values(array_filter(
            glob($tmpDir . '/media.*') ?: [],
            fn($f) => !preg_match('/\.(part|ytdl|jpg|jpeg|png|webp|temp)$/i', $f) && filesize($f) > 0
        ));
        if ($code !== 0 || !$files) {
            $msg = Sys::lastLines($err) ?: implode(' | ', array_slice($tail, -2));
            if (stripos($msg, 'Sign in to confirm') !== false) {
                $msg = 'YouTube bloqueou o IP do servidor (anti-robô)';
            }
            throw new RuntimeException($msg ?: 'yt-dlp falhou');
        }
        return $files[0];
    }
}
