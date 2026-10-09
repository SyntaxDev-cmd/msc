<?php
declare(strict_types=1);

final class Jobs
{
    public const SOURCES = ['itunes', 'youtube', 'jamendo'];

    /** Garante os limites do plano antes de colocar algo na biblioteca do usuário */
    private static function checkLimits(array $user, string $kind, bool $newDownload): void
    {
        if (Account::isAdmin($user)) {
            return;
        }
        if ($kind === 'video' && !(int) $user['allow_video']) {
            throw new DomainException('Seu plano não inclui vídeos');
        }
        $u = Account::usage($user);
        if ((int) $user['max_tracks'] > 0 && $u['tracks'] >= (int) $user['max_tracks']) {
            throw new DomainException("Sua biblioteca chegou ao limite de {$user['max_tracks']} músicas");
        }
        if ($newDownload && (int) $user['dl_per_day'] > 0 && $u['downloads_today'] >= (int) $user['dl_per_day']) {
            throw new DomainException("Limite de {$user['dl_per_day']} downloads por dia atingido. Volta amanhã! 🙂");
        }
    }

    /**
     * Adiciona um item à biblioteca do usuário:
     *  - exists: ele já tinha
     *  - added:  já existia no servidor -> vinculado na hora (não gasta download)
     *  - queued: entrou na fila de download
     */
    /** @param bool $auto pedido automático (música ouvida): não conta nos limites do plano */
    public static function enqueue(array $item, string $kind, array $user, bool $auto = false): array
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
        $uid = (int) $user['id'];

        $t = Library::findExisting($source, $sid, $kind, $key, $source === 'youtube' ? $sid : '');
        if ($t) {
            if (Account::isAdmin($user) || Library::inLibrary($user, (int) $t['id'])) {
                return ['status' => 'exists', 'track_id' => (int) $t['id']];
            }
            if (!$auto) {
                self::checkLimits($user, $kind, false);
            }
            Library::link($uid, (int) $t['id']);
            return ['status' => 'added', 'track_id' => (int) $t['id']];
        }
        $j = Db::one(
            "SELECT id FROM jobs WHERE kind = ? AND status IN ('queued','running','agent') AND ((source = ? AND source_id = ?) OR dedup_key = ?)",
            [$kind, $source, $sid, $key]
        );
        if ($j) {
            if (!Db::one('SELECT 1 FROM job_users WHERE job_id = ? AND user_id = ?', [$j['id'], $uid])) {
                if (!$auto) {
                    self::checkLimits($user, $kind, false);
                }
                Db::exec('INSERT OR IGNORE INTO job_users (job_id, user_id) VALUES (?, ?)', [$j['id'], $uid]);
            }
            return ['status' => 'queued', 'job_id' => (int) $j['id']];
        }
        if (!$auto) {
            self::checkLimits($user, $kind, true);
        }
        $payload = array_intersect_key($item, array_flip(['title', 'artist', 'album', 'genre', 'year', 'duration', 'thumb', 'raw_title', 'channel']));
        if ($auto) {
            $payload['auto'] = 1;
        }
        $id = Db::insert('jobs', [
            'source' => $source, 'source_id' => $sid, 'kind' => $kind, 'dedup_key' => $key,
            'title' => $title, 'artist' => $artist, 'thumb' => mb_substr((string) ($item['thumb'] ?? ''), 0, 500),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'user_id' => $uid,
            'status' => 'queued', 'created_at' => time(), 'updated_at' => time(),
        ]);
        Db::exec('INSERT OR IGNORE INTO job_users (job_id, user_id) VALUES (?, ?)', [$id, $uid]);
        return ['status' => 'queued', 'job_id' => $id];
    }

    /** Vincula a faixa pronta a todos que pediram */
    public static function deliver(int $jobId, int $trackId): void
    {
        foreach (Db::all('SELECT user_id FROM job_users WHERE job_id = ?', [$jobId]) as $r) {
            Library::link((int) $r['user_id'], $trackId);
        }
    }

    public static function list(array $user, int $limit = 100): array
    {
        $order = " ORDER BY CASE j.status WHEN 'running' THEN 0 WHEN 'queued' THEN 1 WHEN 'agent' THEN 1 ELSE 2 END, j.updated_at DESC LIMIT " . (int) $limit;
        $rows = Account::isAdmin($user)
            ? Db::all('SELECT j.*, a.username FROM jobs j LEFT JOIN accounts a ON a.id = j.user_id' . $order)
            : Db::all('SELECT j.*, NULL username FROM jobs j JOIN job_users ju ON ju.job_id = j.id AND ju.user_id = ?' . $order, [$user['id']]);
        return array_map(fn($j) => [
            'id' => (int) $j['id'], 'source' => $j['source'], 'source_id' => $j['source_id'], 'kind' => $j['kind'], 'title' => $j['title'],
            'artist' => $j['artist'], 'thumb' => $j['thumb'], 'status' => $j['status'],
            'progress' => round((float) $j['progress'], 1), 'message' => $j['message'],
            'track_id' => $j['track_id'] ? (int) $j['track_id'] : null, 'updated_at' => (int) $j['updated_at'],
            'username' => $j['username'],
            'auto' => str_contains((string) $j['payload'], '"auto":1'),
        ], $rows);
    }

    public static function pendingCount(?array $user = null): int
    {
        if ($user && !Account::isAdmin($user)) {
            return (int) (Db::one("SELECT COUNT(*) c FROM jobs j JOIN job_users ju ON ju.job_id = j.id AND ju.user_id = ? WHERE j.status IN ('queued','running','agent')", [$user['id']])['c'] ?? 0);
        }
        return (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE status IN ('queued','running','agent')")['c'] ?? 0);
    }

    private static function owns(array $user, int $id): bool
    {
        return Account::isAdmin($user) || (bool) Db::one('SELECT 1 FROM job_users WHERE job_id = ? AND user_id = ?', [$id, $user['id']]);
    }

    public static function retry(array $user, int $id): void
    {
        $j = self::owns($user, $id) ? Db::one("SELECT payload FROM jobs WHERE id = ? AND status = 'error'", [$id]) : null;
        if ($j) {
            $p = json_decode((string) $j['payload'], true) ?: [];
            unset($p['_prep']); // prepara de novo (pode ter mudado o vídeo escolhido)
            Db::exec("UPDATE jobs SET status = 'queued', progress = 0, message = '', attempts = 0, payload = ?, updated_at = ? WHERE id = ?",
                [json_encode($p, JSON_UNESCAPED_UNICODE), time(), $id]);
        }
    }

    public static function cancel(array $user, int $id): void
    {
        if (!self::owns($user, $id)) {
            return;
        }
        if (Account::isAdmin($user)) {
            Db::exec('DELETE FROM job_users WHERE job_id = ?', [$id]);
        } else {
            Db::exec('DELETE FROM job_users WHERE job_id = ? AND user_id = ?', [$id, $user['id']]);
        }
        // ninguém mais esperando e não está rodando: remove
        if (!Db::one('SELECT 1 FROM job_users WHERE job_id = ?', [$id])) {
            Db::exec("DELETE FROM jobs WHERE id = ? AND status <> 'running'", [$id]);
        }
    }

    public static function clearFinished(array $user): void
    {
        if (Account::isAdmin($user)) {
            Db::exec("DELETE FROM job_users WHERE job_id IN (SELECT id FROM jobs WHERE status IN ('done','error'))");
            Db::exec("DELETE FROM jobs WHERE status IN ('done','error')");
            return;
        }
        Db::exec("DELETE FROM job_users WHERE user_id = ? AND job_id IN (SELECT id FROM jobs WHERE status IN ('done','error'))", [$user['id']]);
    }

    public static function update(int $id, array $fields): void
    {
        $fields['updated_at'] = time();
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $fields['id'] = $id;
        Db::pdo()->prepare("UPDATE jobs SET $set WHERE id = :id")->execute($fields);
    }
}
