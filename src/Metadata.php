<?php
declare(strict_types=1);

/**
 * Metadados gratuitos e sem chave:
 *  - iTunes Search API: catálogo, gênero, álbum, ano e capa em alta resolução
 *  - LRCLIB (https://github.com/tranxuanthang/lrclib): letras sincronizadas (karaokê)
 */
final class Metadata
{
    public static function itunes(array $params): array
    {
        $params += ['country' => cfg('country', 'BR'), 'media' => 'music', 'entity' => 'song', 'limit' => 25];
        $url = 'https://itunes.apple.com/search?' . http_build_query($params);
        return Cache::remember('it:' . md5($url), 86400 * 3, function () use ($url) {
            return Http::json($url)['results'] ?? [];
        });
    }

    public static function lookup(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $url = 'https://itunes.apple.com/lookup?' . http_build_query(['id' => $id, 'country' => cfg('country', 'BR')]);
        $res = Cache::remember('itl:' . $id, 86400 * 7, fn() => Http::json($url)['results'] ?? []);
        return isset($res[0]['trackId']) ? self::mapItunes($res[0]) : null;
    }

    public static function mapItunes(array $r): array
    {
        $art = (string) ($r['artworkUrl100'] ?? '');
        return [
            'source' => 'itunes',
            'source_id' => (string) $r['trackId'],
            'title' => (string) ($r['trackName'] ?? ''),
            'artist' => (string) ($r['artistName'] ?? ''),
            'album' => (string) ($r['collectionName'] ?? ''),
            'genre' => (string) ($r['primaryGenreName'] ?? ''),
            'year' => substr((string) ($r['releaseDate'] ?? ''), 0, 4),
            'duration' => (int) round(((int) ($r['trackTimeMillis'] ?? 0)) / 1000),
            'thumb' => $art ? str_replace('100x100bb', '600x600bb', $art) : '',
            'preview' => $r['previewUrl'] ?? null,
            'kinds' => ['audio', 'video'],
        ];
    }

    /** Busca no catálogo; $artistMode = todas as músicas daquele artista */
    public static function searchCatalog(string $q, bool $artistMode, int $limit): array
    {
        $params = ['term' => $q, 'limit' => $artistMode ? 200 : $limit];
        if ($artistMode) {
            $params['attribute'] = 'artistTerm';
        }
        $out = [];
        $seen = [];
        foreach (self::itunes($params) as $r) {
            if (($r['wrapperType'] ?? '') !== 'track' || ($r['kind'] ?? '') !== 'song') {
                continue;
            }
            $item = self::mapItunes($r);
            $k = Text::key($item['artist'], $item['title']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $item;
            if (count($out) >= ($artistMode ? 150 : $limit)) {
                break;
            }
        }
        return $out;
    }

    /** Descobre gênero/álbum/capa oficiais para "Artista - Música" */
    public static function enrich(string $artist, string $title): ?array
    {
        try {
            $results = self::itunes(['term' => Text::primaryArtist($artist) . ' ' . Text::cleanTitle($title), 'limit' => 15]);
        } catch (Throwable $e) {
            return null;
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($results as $r) {
            if (($r['kind'] ?? '') !== 'song') {
                continue;
            }
            $sa = Text::similarity(Text::primaryArtist($artist), (string) $r['artistName']);
            $st = Text::similarity(Text::cleanTitle($title), (string) $r['trackName']);
            $score = $sa * 0.45 + $st * 0.55;
            if ($sa >= 60 && $st >= 60 && $score > $bestScore) {
                $best = $r;
                $bestScore = $score;
            }
        }
        if ($best) {
            return self::mapItunes($best);
        }
        // Não achou a música: tenta ao menos o gênero do artista
        try {
            foreach (self::itunes(['term' => Text::primaryArtist($artist), 'entity' => 'musicArtist', 'limit' => 5]) as $r) {
                if (Text::similarity(Text::primaryArtist($artist), (string) ($r['artistName'] ?? '')) >= 80) {
                    return ['artist' => (string) $r['artistName'], 'genre' => (string) ($r['primaryGenreName'] ?? '')];
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    /** Letra sincronizada via LRCLIB */
    public static function lyrics(string $artist, string $title, string $album, int $duration): array
    {
        $base = 'https://lrclib.net/api/';
        $params = ['artist_name' => Text::primaryArtist($artist), 'track_name' => Text::cleanTitle($title)];
        try {
            $p = $params;
            if ($album !== '') {
                $p['album_name'] = $album;
            }
            if ($duration > 0) {
                $p['duration'] = $duration;
            }
            $r = Http::json($base . 'get?' . http_build_query($p), 15);
            if (!empty($r['syncedLyrics']) || !empty($r['plainLyrics'])) {
                return ['synced' => $r['syncedLyrics'] ?? null, 'plain' => $r['plainLyrics'] ?? null];
            }
        } catch (Throwable $e) {
        }
        try {
            $list = Http::json($base . 'search?' . http_build_query($params), 15);
            usort($list, fn($a, $b) => (int) !empty($b['syncedLyrics']) <=> (int) !empty($a['syncedLyrics']));
            foreach ($list as $r) {
                if ($duration > 0 && abs((int) ($r['duration'] ?? 0) - $duration) > 8) {
                    continue;
                }
                if (!empty($r['syncedLyrics']) || !empty($r['plainLyrics'])) {
                    return ['synced' => $r['syncedLyrics'] ?? null, 'plain' => $r['plainLyrics'] ?? null];
                }
            }
        } catch (Throwable $e) {
        }
        return ['synced' => null, 'plain' => null];
    }
}
