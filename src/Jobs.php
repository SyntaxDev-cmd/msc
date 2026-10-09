<?php
declare(strict_types=1);

final class Jobs
{
    public const SOURCES = ['itunes', 'youtube', 'jamendo'];

    /**
     * Coloca um item na fila — a menos que já exista na biblioteca ou já esteja na fila.
     * @return array{status:string, track_id?:int, job_id?:int}
     */
    public static function enqueue(array $item, string $kind): array
    {
        $source = (string) ($item['source'] ?? '');
        $sid = (string) ($item['source_id'] ?? '');
        if (!in_array($source, self::SOURCES, true) || !in_array($kind, ['audio', 'video'], true)) {
            throw new InvalidArgumentException('Item inválido');
        }
        $valid = $source === 'youtube' ? preg_match('/^[A-Za-z0-9_-]{11}$/', $sid) : ctype_digit($sid);
        if (!$valid) {
            throw new InvalidArgumentException('Identificador inválido');
        }
        if ($source === 'jamendo' && $kind === 'video') {
            throw new InvalidArgumentException('Jamendo só tem áudio');
        }
        $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 200);
        $artist = mb_substr(trim((string) ($item['artist'] ?? '')), 0, 200);
        $key = Text::key($artist, $title);

        $t = Library::findExisting($source, $sid, $kind, $key, $source === 'youtube' ? $sid : '');
        if ($t) {
            return ['status' => 'exists', 'track_id' => (int) $t['id']];
        }
        $j = Db::one(
            "SELECT id FROM jobs WHERE kind = ? AND status IN ('queued','running') AND ((source = ? AND source_id = ?) OR dedup_key = ?)",
            [$kind, $source, $sid, $key]
        );
        if ($j) {
            return ['status' => 'queued', 'job_id' => (int) $j['id']];
        }
        $payload = array_intersect_key($item, array_flip(['title', 'artist', 'album', 'genre', 'year', 'duration', 'thumb', 'raw_title', 'channel']));
        $id = Db::insert('jobs', [
            'source' => $source, 'source_id' => $sid, 'kind' => $kind, 'dedup_key' => $key,
            'title' => $title, 'artist' => $artist, 'thumb' => mb_substr((string) ($item['thumb'] ?? ''), 0, 500),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'queued', 'created_at' => time(), 'updated_at' => time(),
        ]);
        return ['status' => 'queued', 'job_id' => $id];
    }

    public static function list(int $limit = 100): array
    {
        return array_map(function ($j) {
            return [
                'id' => (int) $j['id'], 'source' => $j['source'], 'kind' => $j['kind'], 'title' => $j['title'],
                'artist' => $j['artist'], 'thumb' => $j['thumb'], 'status' => $j['status'],
                'progress' => round((float) $j['progress'], 1), 'message' => $j['message'],
                'track_id' => $j['track_id'] ? (int) $j['track_id'] : null, 'updated_at' => (int) $j['updated_at'],
            ];
        }, Db::all('SELECT * FROM jobs ORDER BY CASE status WHEN \'running\' THEN 0 WHEN \'queued\' THEN 1 ELSE 2 END, updated_at DESC LIMIT ' . (int) $limit));
    }

    public static function pendingCount(): int
    {
        return (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE status IN ('queued','running')")['c'] ?? 0);
    }

    public static function retry(int $id): void
    {
        Db::exec("UPDATE jobs SET status = 'queued', progress = 0, message = '', attempts = 0, updated_at = ? WHERE id = ? AND status = 'error'", [time(), $id]);
    }

    public static function cancel(int $id): void
    {
        Db::exec("DELETE FROM jobs WHERE id = ? AND status <> 'running'", [$id]);
    }

    public static function clearFinished(): void
    {
        Db::exec("DELETE FROM jobs WHERE status IN ('done','error')");
    }

    public static function update(int $id, array $fields): void
    {
        $fields['updated_at'] = time();
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $fields['id'] = $id;
        Db::pdo()->prepare("UPDATE jobs SET $set WHERE id = :id")->execute($fields);
    }
}
