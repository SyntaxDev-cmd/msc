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
            Db::exec('DELETE FROM tracks WHERE id = ?', [$row['id']]);
            return null;
        }
        return $row;
    }

    /** Marca nos resultados da busca o que já está na biblioteca ou na fila */
    public static function annotate(array $items): array
    {
        foreach ($items as &$it) {
            $key = Text::key($it['artist'], $it['title']);
            $yt = $it['source'] === 'youtube' ? $it['source_id'] : '';
            $it['library'] = [];
            $it['job'] = [];
            foreach (['audio', 'video'] as $kind) {
                $t = self::findExisting($it['source'], $it['source_id'], $kind, $key, $yt);
                $it['library'][$kind] = $t ? (int) $t['id'] : null;
                if (!$t) {
                    $j = Db::one(
                        "SELECT id, status, progress, message FROM jobs WHERE kind = ? AND status IN ('queued','running')
                         AND ((source = ? AND source_id = ?) OR dedup_key = ?) ORDER BY id DESC LIMIT 1",
                        [$kind, $it['source'], $it['source_id'], $key]
                    );
                    $it['job'][$kind] = $j ?: null;
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
            'plays' => (int) $t['plays'],
            'favorite' => (bool) $t['favorite'],
            'source' => $t['source'],
            'youtube_id' => $t['youtube_id'],
            'path' => $t['file_path'],
            'created_at' => (int) $t['created_at'],
            'last_played' => (int) $t['last_played'],
        ];
    }

    public static function all(): array
    {
        $rows = Db::all('SELECT * FROM tracks ORDER BY genre COLLATE NOCASE, artist COLLATE NOCASE, album COLLATE NOCASE, title COLLATE NOCASE');
        return array_map([self::class, 'publicRow'], $rows);
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
