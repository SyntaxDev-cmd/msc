<?php
declare(strict_types=1);

/**
 * Descoberta musical: o que todos os clientes ouvem vira "Em alta" e "Mais ouvidas";
 * o histórico de cada um alimenta o "Para você" (rádio do YouTube Music a partir do que ele curtiu).
 */
final class Discovery
{
    /** Estilos da página inicial: [nome, cor, busca usada no YouTube Music] */
    public const STYLES = [
        ['Sertanejo', '#f59e0b', 'sertanejo'], ['Funk', '#ec4899', 'funk'], ['Pagode', '#10b981', 'pagode'],
        ['Trap', '#8b5cf6', 'trap brasileiro'], ['Rap Nacional', '#6366f1', 'rap nacional'], ['Gospel', '#0ea5e9', 'gospel'],
        ['Piseiro', '#f97316', 'piseiro'], ['Forró', '#eab308', 'forró'], ['MPB', '#14b8a6', 'mpb'],
        ['Pop', '#d946ef', 'pop brasil'], ['Rock', '#ef4444', 'rock nacional'], ['Eletrônica', '#22d3ee', 'música eletrônica'],
        ['Reggaeton', '#84cc16', 'reggaeton'], ['Arrocha', '#fb7185', 'arrocha'], ['Samba', '#a3e635', 'samba'],
        ['Internacional', '#60a5fa', 'hits internacionais'], ['Romântica', '#f43f5e', 'músicas românticas'], ['Anos 80', '#c084fc', 'anos 80'],
    ];

    /** Registra uma reprodução (≥ 30 s). Mesma música ouvida por servidor ou YouTube conta junto. */
    public static function log(array $user, array $d): void
    {
        $yt = preg_match('/^[A-Za-z0-9_-]{11}$/', (string) ($d['youtube_id'] ?? '')) ? (string) $d['youtube_id'] : '';
        $tid = (int) ($d['track_id'] ?? 0);
        if ($tid && ($t = Library::get($tid))) {
            $yt = $yt ?: (string) $t['youtube_id'];
            $d = ['title' => $t['title'], 'artist' => $t['artist'], 'genre' => $t['genre'], 'duration' => $t['duration'],
                  'thumb' => $d['thumb'] ?? ''] + $d;
        }
        $k = $yt !== '' ? 'y' . $yt : ($tid ? 't' . $tid : '');
        $title = mb_substr(trim((string) ($d['title'] ?? '')), 0, 200);
        if ($k === '' || $title === '') {
            return;
        }
        if (Db::one('SELECT 1 FROM play_events WHERE user_id = ? AND k = ? AND at > ?', [$user['id'], $k, time() - 600])) {
            return; // evita contar repetições em sequência
        }
        Db::insert('play_events', [
            'user_id' => $user['id'], 'k' => $k, 'youtube_id' => $yt, 'track_id' => $tid,
            'title' => $title, 'artist' => mb_substr(trim((string) ($d['artist'] ?? '')), 0, 200),
            'thumb' => mb_substr((string) ($d['thumb'] ?? ''), 0, 500), 'genre' => mb_substr((string) ($d['genre'] ?? ''), 0, 60),
            'duration' => (int) ($d['duration'] ?? 0), 'at' => time(),
        ]);
    }

    private static function eventItems(array $rows): array
    {
        return array_map(function ($r) {
            $item = [
                'source' => $r['youtube_id'] !== '' ? 'youtube' : 'local', 'source_id' => $r['youtube_id'] ?: (string) $r['track_id'],
                'title' => $r['title'], 'artist' => $r['artist'], 'album' => '', 'genre' => $r['genre'], 'duration' => (int) $r['duration'],
                'thumb' => $r['thumb'] ?: ($r['youtube_id'] !== '' ? "https://i.ytimg.com/vi/{$r['youtube_id']}/hqdefault.jpg" : ''),
                'raw_title' => $r['artist'] . ' - ' . $r['title'], 'channel' => '', 'views' => 0, 'kinds' => ['audio', 'video'],
                'plays' => (int) ($r['plays'] ?? 0), 'listeners' => (int) ($r['listeners'] ?? 0),
            ];
            if (!empty($r['track_id']) && Library::get((int) $r['track_id'])) {
                $item['local_id'] = (int) $r['track_id'];
            }
            return $item;
        }, $rows);
    }

    /** Mais ouvidas entre TODOS os clientes no período */
    public static function trending(int $days, int $limit): array
    {
        $rows = Db::all('SELECT k, MAX(youtube_id) youtube_id, MAX(track_id) track_id, MAX(title) title, MAX(artist) artist, MAX(thumb) thumb,
                MAX(genre) genre, MAX(duration) duration, COUNT(*) plays, COUNT(DISTINCT user_id) listeners
            FROM play_events WHERE at > ? GROUP BY k ORDER BY listeners DESC, plays DESC LIMIT ' . (int) $limit, [time() - $days * 86400]);
        return self::eventItems($rows);
    }

    public static function recentByUser(array $user, int $limit): array
    {
        $rows = Db::all('SELECT k, MAX(youtube_id) youtube_id, MAX(track_id) track_id, MAX(title) title, MAX(artist) artist, MAX(thumb) thumb,
                MAX(genre) genre, MAX(duration) duration, MAX(at) last, COUNT(*) plays
            FROM play_events WHERE user_id = ? GROUP BY k ORDER BY last DESC LIMIT ' . (int) $limit, [$user['id']]);
        return self::eventItems($rows);
    }

    public static function topArtists(array $user, int $limit): array
    {
        return array_map(fn($r) => ['name' => $r['artist'], 'plays' => (int) $r['c'], 'thumb' => $r['thumb']],
            Db::all('SELECT artist, COUNT(*) c, MAX(thumb) thumb FROM play_events WHERE user_id = ? AND artist <> \'\' GROUP BY artist ORDER BY c DESC LIMIT ' . (int) $limit, [$user['id']]));
    }

    /** "Para você": rádio das músicas que o cliente mais ouviu recentemente, sem repetir o que ele já ouviu */
    public static function forYou(array $user, int $limit = 30): array
    {
        $seeds = array_values(array_filter(self::recentByUser($user, 12), fn($i) => $i['source'] === 'youtube'));
        if (!$seeds) {
            $seeds = array_values(array_filter(self::trending(30, 5), fn($i) => $i['source'] === 'youtube'));
        }
        $heard = array_flip(array_column(self::recentByUser($user, 300), 'source_id'));
        $out = [];
        foreach (array_slice($seeds, 0, 3) as $seed) {
            try {
                foreach (Innertube::radio($seed['source_id'], 30) as $it) {
                    if (!isset($heard[$it['source_id']]) && !isset($out[$it['source_id']])) {
                        $out[$it['source_id']] = $it + ['because' => $seed['title']];
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $out = array_values($out);
        // intercala as rádios para variar os artistas
        usort($out, fn($a, $b) => crc32($a['source_id'] . date('Ymd')) <=> crc32($b['source_id'] . date('Ymd')));
        return array_slice($out, 0, $limit);
    }

    /** Mais tocadas de um estilo (YouTube Music) */
    public static function style(string $name): array
    {
        $query = $name;
        foreach (self::STYLES as [$n, , $q]) {
            if (mb_strtolower($n) === mb_strtolower($name)) {
                $query = $q;
            }
        }
        return Cache::remember('sty:' . md5($query), 3600 * 12, function () use ($query) {
            return array_map([Innertube::class, 'toItem'], Innertube::musicSongs($query . ' mais tocadas', 80));
        });
    }

    /* ---------- Favoritas tocadas pelo YouTube ---------- */
    public static function setStreamFav(array $user, array $d, bool $on): void
    {
        $yt = (string) ($d['youtube_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) {
            throw new InvalidArgumentException('Música inválida');
        }
        if (!$on) {
            Db::exec('DELETE FROM stream_favs WHERE user_id = ? AND youtube_id = ?', [$user['id'], $yt]);
            return;
        }
        Db::exec('INSERT OR REPLACE INTO stream_favs (user_id, youtube_id, title, artist, album, thumb, duration, added_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $user['id'], $yt, mb_substr((string) ($d['title'] ?? ''), 0, 200), mb_substr((string) ($d['artist'] ?? ''), 0, 200),
            mb_substr((string) ($d['album'] ?? ''), 0, 200), mb_substr((string) ($d['thumb'] ?? ''), 0, 500), (int) ($d['duration'] ?? 0), time(),
        ]);
    }

    public static function streamFavs(array $user): array
    {
        return array_map(fn($r) => [
            'source' => 'youtube', 'source_id' => $r['youtube_id'], 'title' => $r['title'], 'artist' => $r['artist'], 'album' => $r['album'],
            'genre' => '', 'duration' => (int) $r['duration'], 'thumb' => $r['thumb'] ?: "https://i.ytimg.com/vi/{$r['youtube_id']}/hqdefault.jpg",
            'raw_title' => $r['artist'] . ' - ' . $r['title'], 'channel' => '', 'views' => 0, 'kinds' => ['audio', 'video'], 'favorite' => true,
        ], Db::all('SELECT * FROM stream_favs WHERE user_id = ? ORDER BY added_at DESC', [$user['id']]));
    }
}
