<?php
declare(strict_types=1);

/** Músicas livres (Creative Commons) com download liberado — https://developer.jamendo.com */
final class Jamendo
{
    public static function enabled(): bool
    {
        return (string) cfg('jamendo_client_id') !== '';
    }

    private static function api(array $params): array
    {
        $params += ['client_id' => cfg('jamendo_client_id'), 'format' => 'json', 'include' => 'musicinfo',
                    'audiodlformat' => 'mp32', 'imagesize' => 600];
        $url = 'https://api.jamendo.com/v3.0/tracks/?' . http_build_query($params);
        return Cache::remember('jm:' . md5($url), 3600 * 12, fn() => Http::json($url)['results'] ?? []);
    }

    public static function search(string $q, int $limit): array
    {
        if (!self::enabled()) {
            throw new RuntimeException('Configure jamendo_client_id no config.php para buscar músicas livres.');
        }
        $out = [];
        foreach (self::api(['search' => $q, 'limit' => min(50, $limit), 'audiodownload_allowed' => 'true']) as $r) {
            $out[] = self::map($r);
        }
        return $out;
    }

    public static function get(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $r = self::api(['id' => $id])[0] ?? null;
        return $r ? self::map($r) : null;
    }

    private static function map(array $r): array
    {
        $genres = $r['musicinfo']['tags']['genres'] ?? [];
        return [
            'source' => 'jamendo',
            'source_id' => (string) $r['id'],
            'title' => (string) $r['name'],
            'artist' => (string) $r['artist_name'],
            'album' => (string) ($r['album_name'] ?? ''),
            'genre' => $genres ? ucfirst((string) $genres[0]) : '',
            'year' => substr((string) ($r['releasedate'] ?? ''), 0, 4),
            'duration' => (int) ($r['duration'] ?? 0),
            'thumb' => (string) ($r['album_image'] ?? $r['image'] ?? ''),
            'download_url' => (string) ($r['audiodownload'] ?: $r['audio']),
            'license' => (string) ($r['license_ccurl'] ?? ''),
            'kinds' => ['audio'],
        ];
    }
}
