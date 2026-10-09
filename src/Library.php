<?php
declare(strict_types=1);

final class Library
{
    public const MIME = [
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'opus' => 'audio/ogg', 'ogg' => 'audio/ogg',
        'webm' => 'audio/webm', 'flac' => 'audio/flac', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    ];

    public static function root(): string
    {
        return storage_path('library');
    }

    public static function abs(string $rel): string
    {
        return self::root() . '/' . ltrim($rel, '/');
    }

    public static function get(int $id): ?array
    {
        return Db::one('SELECT * FROM tracks WHERE id = ?', [$id]);
    }

    /** Procura a música já baixada: mesma origem, mesmo vídeo ou mesmo artista+título */
    public static function findExisting(string $source, string $sourceId, string $kind, string $key, string $youtubeId = ''): ?array
    {
        $row = Db::one('SELECT * FROM tracks WHERE source = ? AND source_id = ? AND kind = ?', [$source, $sourceId, $kind]);
        if (!$row && $youtubeId !== '') {
            $row = Db::one('SELECT * FROM tracks WHERE youtube_id = ? AND kind = ?', [$youtubeId, $kind]);
        }
        if (!$row && $key !== '|' && !str_ends_with($key, '|')) {
            $row = Db::one('SELECT * FROM tracks WHERE dedup_key = ? AND kind = ?', [$key, $kind]);
        }
        if ($row && !is_file(self::abs($row['file_path']))) {
            // arquivo apagado manualmente via FTP: limpa o registro para poder baixar de novo
            Db::exec('DELETE FROM user_tracks WHERE track_id = ?', [$row['id']]);
            Db::exec('DELETE FROM tracks WHERE id = ?', [$row['id']]);
            return null;
        }
        return $row;
    }

    /**
     * Acervo compartilhado: o que qualquer usuário baixou fica disponível para todos ouvirem
     * na hora (sem baixar de novo). O controle de acesso é a conta estar ativa e em dia.
     */
    public static function canAccess(array $user, int $trackId): bool
    {
        return $user['status'] === 'active' && !Account::expired($user) && (bool) Db::one('SELECT 1 FROM tracks WHERE id = ?', [$trackId]);
    }

    public static function inLibrary(array $user, int $trackId): bool
    {
        return (bool) Db::one('SELECT 1 FROM user_tracks WHERE user_id = ? AND track_id = ?', [$user['id'], $trackId]);
    }

    public static function link(int $userId, int $trackId): void
    {
        Db::exec('INSERT OR IGNORE INTO user_tracks (user_id, track_id, added_at) VALUES (?, ?, ?)', [$userId, $trackId, time()]);
    }

    public static function unlink(int $userId, int $trackId): void
    {
        Db::exec('DELETE FROM user_tracks WHERE user_id = ? AND track_id = ?', [$userId, $trackId]);
    }

    /**
     * Marca nos resultados o que o usuário já tem, o que já existe no servidor (adição instantânea)
     * e o que está na fila.
     */
    public static function annotate(array $items, array $user): array
    {
        $admin = Account::isAdmin($user);
        foreach ($items as &$it) {
            if ($it['source'] === 'local') { // música só do servidor (sem vídeo de origem)
                $it['library'] = ['audio' => $it['local_id'] ?? (int) $it['source_id'], 'video' => null];
                $it['server'] = $it['library'];
                $it['job'] = ['audio' => null, 'video' => null];
                continue;
            }
            $it['stream_fav'] = $it['source'] === 'youtube'
                && (bool) Db::one('SELECT 1 FROM stream_favs WHERE user_id = ? AND youtube_id = ?', [$user['id'], $it['source_id']]);
            $key = Text::key($it['artist'], $it['title']);
            $yt = $it['source'] === 'youtube' ? $it['source_id'] : '';
            $it['library'] = [];
            $it['server'] = [];
            $it['job'] = [];
            foreach (['audio', 'video'] as $kind) {
                $t = self::findExisting($it['source'], $it['source_id'], $kind, $key, $yt);
                // já está no servidor = todo mundo ouve na hora
                $it['library'][$kind] = $t ? (int) $t['id'] : null;
                $it['server'][$kind] = $t ? (int) $t['id'] : null;
                $it['job'][$kind] = null;
                if (!$t) {
                    $j = Db::one(
                        "SELECT id, status, progress, message FROM jobs WHERE kind = ? AND status IN ('queued','running')
                         AND ((source = ? AND source_id = ?) OR dedup_key = ?) ORDER BY id DESC LIMIT 1",
                        [$kind, $it['source'], $it['source_id'], $key]
                    );
                    if ($j && ($admin || Db::one('SELECT 1 FROM job_users WHERE job_id = ? AND user_id = ?', [$j['id'], $user['id']]))) {
                        $it['job'][$kind] = $j;
                    }
                }
            }
        }
        return $items;
    }

    public static function publicRow(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'title' => $t['title'],
            'artist' => $t['artist'],
            'album' => $t['album'],
            'genre' => $t['genre'],
            'year' => $t['year'],
            'duration' => (int) $t['duration'],
            'kind' => $t['kind'],
            'mime' => $t['mime'],
            'size' => (int) $t['size'],
            'cover' => $t['cover_path'] !== '',
            'plays' => (int) ($t['u_plays'] ?? 0),
            'favorite' => (bool) ($t['u_favorite'] ?? 0),
            'source' => $t['source'],
            'youtube_id' => $t['youtube_id'],
            'created_at' => (int) ($t['added_at'] ?? $t['created_at']),
            'last_played' => (int) ($t['u_last_played'] ?? 0),
            'owners' => isset($t['owners']) ? (int) $t['owners'] : null,
        ];
    }

    /** Biblioteca do usuário (admin: acervo completo do servidor) */
    public static function all(array $user): array
    {
        $order = ' ORDER BY t.genre COLLATE NOCASE, t.artist COLLATE NOCASE, t.album COLLATE NOCASE, t.title COLLATE NOCASE';
        if (Account::isAdmin($user)) {
            $rows = Db::all('SELECT t.*, ut.favorite u_favorite, ut.plays u_plays, ut.last_played u_last_played, COALESCE(ut.added_at, t.created_at) added_at,
                (SELECT COUNT(*) FROM user_tracks x WHERE x.track_id = t.id) owners
                FROM tracks t LEFT JOIN user_tracks ut ON ut.track_id = t.id AND ut.user_id = ?' . $order, [$user['id']]);
        } else {
            $rows = Db::all('SELECT t.*, ut.favorite u_favorite, ut.plays u_plays, ut.last_played u_last_played, ut.added_at
                FROM user_tracks ut JOIN tracks t ON t.id = ut.track_id WHERE ut.user_id = ?' . $order, [$user['id']]);
        }
        return array_map([self::class, 'publicRow'], $rows);
    }

    /** Faixas do acervo com os dados pessoais do usuário (favorita, plays) */
    public static function rows(array $user, string $join, string $where, array $args, string $order, int $limit): array
    {
        $rows = Db::all('SELECT t.*, ut.favorite u_favorite, ut.plays u_plays, ut.last_played u_last_played, COALESCE(ut.added_at, t.created_at) added_at
            FROM tracks t ' . $join . ' LEFT JOIN user_tracks ut ON ut.track_id = t.id AND ut.user_id = ?'
            . ($where !== '' ? ' WHERE ' . $where : '') . ' ORDER BY ' . $order . ' LIMIT ' . (int) $limit, array_merge([$user['id']], $args));
        return array_map([self::class, 'publicRow'], $rows);
    }

    public static function touch(array $user, int $trackId, array $fields): void
    {
        // ouviu ou favoritou uma música do acervo: ela entra na biblioteca do usuário
        self::link((int) $user['id'], $trackId);
        if (isset($fields['favorite'])) {
            Db::exec('UPDATE user_tracks SET favorite = ? WHERE user_id = ? AND track_id = ?', [(int) $fields['favorite'], $user['id'], $trackId]);
        }
        if (!empty($fields['played'])) {
            Db::exec('UPDATE user_tracks SET plays = plays + 1, last_played = ? WHERE user_id = ? AND track_id = ?', [time(), $user['id'], $trackId]);
            Db::exec('UPDATE tracks SET plays = plays + 1, last_played = ? WHERE id = ?', [time(), $trackId]);
        }
    }

    public static function delete(int $id): void
    {
        $t = self::get($id);
        if (!$t) {
            return;
        }
        foreach ([$t['file_path'], $t['cover_path']] as $rel) {
            if ($rel === '') {
                continue;
            }
            // não apaga a capa se outra faixa usa o mesmo arquivo
            if ($rel === $t['cover_path'] && Db::one('SELECT id FROM tracks WHERE cover_path = ? AND id <> ?', [$rel, $id])) {
                continue;
            }
            @unlink(self::abs($rel));
        }
        Db::exec('DELETE FROM user_tracks WHERE track_id = ?', [$id]);
        Db::exec('DELETE FROM tracks WHERE id = ?', [$id]);
        // remove pastas vazias (artista / gênero)
        $dir = dirname(self::abs($t['file_path']));
        for ($i = 0; $i < 2 && $dir !== self::root(); $i++) {
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                @rmdir($dir);
            }
            $dir = dirname($dir);
        }
    }
}
