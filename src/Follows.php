<?php
declare(strict_types=1);

/** Seguir artistas: lista do usuário, contagem de seguidores e as músicas dos artistas seguidos */
final class Follows
{
    public static function key(string $name): string
    {
        $k = Text::key($name, '');
        return trim($k, '|') !== '' ? $k : 'u' . md5(mb_strtolower(trim($name)));
    }

    /** Liga/desliga. Retorna [seguindo?, total de seguidores] */
    public static function toggle(array $user, string $name, string $thumb = '', ?bool $on = null): array
    {
        $name = mb_substr(trim($name), 0, 120);
        $k = self::key($name);
        if ($name === '' || $k === '') {
            throw new InvalidArgumentException('Artista inválido');
        }
        $exists = (bool) Db::one('SELECT 1 FROM artist_follows WHERE user_id = ? AND name_key = ?', [$user['id'], $k]);
        $on = $on ?? !$exists;
        if ($on && !$exists) {
            if ((int) (Db::one('SELECT COUNT(*) c FROM artist_follows WHERE user_id = ?', [$user['id']])['c'] ?? 0) >= 300) {
                throw new DomainException('Você já segue 300 artistas');
            }
            Db::insert('artist_follows', ['user_id' => $user['id'], 'name_key' => $k, 'name' => $name,
                'thumb' => mb_substr($thumb, 0, 500), 'created_at' => time()]);
        } elseif (!$on && $exists) {
            Db::exec('DELETE FROM artist_follows WHERE user_id = ? AND name_key = ?', [$user['id'], $k]);
        } elseif ($on && $thumb !== '') {
            Db::exec("UPDATE artist_follows SET thumb = ? WHERE user_id = ? AND name_key = ? AND thumb = ''", [$thumb, $user['id'], $k]);
        }
        return [$on, self::followers($name)];
    }

    public static function followers(string $name): int
    {
        return (int) (Db::one('SELECT COUNT(*) c FROM artist_follows WHERE name_key = ?', [self::key($name)])['c'] ?? 0);
    }

    public static function isFollowing(array $user, string $name): bool
    {
        return (bool) Db::one('SELECT 1 FROM artist_follows WHERE user_id = ? AND name_key = ?', [$user['id'], self::key($name)]);
    }

    public static function list(array $user): array
    {
        $rows = Db::all('SELECT f.name, f.thumb, f.created_at, (SELECT COUNT(*) FROM artist_follows x WHERE x.name_key = f.name_key) followers
                         FROM artist_follows f WHERE f.user_id = ? ORDER BY f.created_at DESC', [$user['id']]);
        return array_map(fn($r) => ['name' => $r['name'], 'thumb' => $r['thumb'], 'followers' => (int) $r['followers'],
            'since' => (int) $r['created_at']], $rows);
    }

    /**
     * Músicas dos artistas seguidos (YouTube Music), intercaladas entre eles.
     * Cada artista fica 12 h em cache: a tela inicial abre rápido e o YouTube é pouco consultado.
     */
    public static function feed(array $user, int $artists = 10, int $perArtist = 8): array
    {
        $follows = array_slice(self::list($user), 0, $artists);
        $lists = [];
        foreach ($follows as $f) {
            try {
                $songs = Cache::remember('follow:' . self::key($f['name']), 43200, fn() => array_map(
                    [Innertube::class, 'toItem'],
                    Innertube::musicSongs($f['name'], 12)
                ));
            } catch (Throwable $e) {
                $songs = [];
            }
            $lists[] = array_slice((array) $songs, 0, $perArtist);
        }
        $out = [];
        $seen = [];
        for ($i = 0; $i < $perArtist; $i++) {
            foreach ($lists as $l) {
                if (isset($l[$i]) && !isset($seen[$l[$i]['source_id']])) {
                    $seen[$l[$i]['source_id']] = true;
                    $out[] = $l[$i];
                }
            }
        }
        return $out;
    }
}
