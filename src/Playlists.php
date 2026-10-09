<?php
declare(strict_types=1);

/** Playlists do usuário (com faixas do acervo compartilhado) */
final class Playlists
{
    public static function owned(array $user, int $id): array
    {
        $p = Db::one('SELECT * FROM playlists WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if (!$p) {
            throw new DomainException('Playlist não encontrada');
        }
        return $p;
    }

    public static function list(array $user): array
    {
        $rows = Db::all('SELECT p.*, COUNT(pt.track_id) n,
                (SELECT x.track_id FROM playlist_tracks x JOIN tracks t ON t.id = x.track_id WHERE x.playlist_id = p.id AND t.cover_path <> \'\' ORDER BY x.pos LIMIT 1) cover_track,
                COALESCE(SUM(t2.duration), 0) duration
            FROM playlists p LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id LEFT JOIN tracks t2 ON t2.id = pt.track_id
            WHERE p.user_id = ? GROUP BY p.id ORDER BY p.updated_at DESC', [$user['id']]);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'count' => (int) $r['n'],
            'cover_track' => $r['cover_track'] ? (int) $r['cover_track'] : null, 'duration' => (int) $r['duration'], 'updated_at' => (int) $r['updated_at']], $rows);
    }

    public static function save(array $user, int $id, string $name): int
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            throw new InvalidArgumentException('Dê um nome para a playlist');
        }
        if ($id) {
            self::owned($user, $id);
            Db::exec('UPDATE playlists SET name = ?, updated_at = ? WHERE id = ?', [$name, time(), $id]);
            return $id;
        }
        if ((int) (Db::one('SELECT COUNT(*) c FROM playlists WHERE user_id = ?', [$user['id']])['c'] ?? 0) >= 300) {
            throw new DomainException('Limite de 300 playlists');
        }
        return Db::insert('playlists', ['user_id' => $user['id'], 'name' => $name, 'created_at' => time(), 'updated_at' => time()]);
    }

    public static function delete(array $user, int $id): void
    {
        self::owned($user, $id);
        Db::exec('DELETE FROM playlist_tracks WHERE playlist_id = ?', [$id]);
        Db::exec('DELETE FROM playlists WHERE id = ?', [$id]);
    }

    public static function add(array $user, int $id, array $trackIds): int
    {
        self::owned($user, $id);
        $pos = (int) (Db::one('SELECT COALESCE(MAX(pos), 0) m FROM playlist_tracks WHERE playlist_id = ?', [$id])['m'] ?? 0);
        $n = 0;
        foreach (array_slice(array_unique(array_map('intval', $trackIds)), 0, 1000) as $tid) {
            if (!Library::canAccess($user, $tid)) {
                continue;
            }
            $n += Db::exec('INSERT OR IGNORE INTO playlist_tracks (playlist_id, track_id, pos, added_at) VALUES (?, ?, ?, ?)', [$id, $tid, ++$pos, time()]);
            Library::link((int) $user['id'], $tid);
        }
        Db::exec('UPDATE playlists SET updated_at = ? WHERE id = ?', [time(), $id]);
        return $n;
    }

    public static function remove(array $user, int $id, int $trackId): void
    {
        self::owned($user, $id);
        Db::exec('DELETE FROM playlist_tracks WHERE playlist_id = ? AND track_id = ?', [$id, $trackId]);
    }

    /** Reordena: recebe a lista de ids na nova ordem */
    public static function reorder(array $user, int $id, array $order): void
    {
        self::owned($user, $id);
        Db::tx(function () use ($id, $order) {
            foreach (array_values(array_map('intval', $order)) as $i => $tid) {
                Db::exec('UPDATE playlist_tracks SET pos = ? WHERE playlist_id = ? AND track_id = ?', [$i + 1, $id, $tid]);
            }
        });
    }

    public static function tracks(array $user, int $id): array
    {
        self::owned($user, $id);
        return Library::rows($user, 'JOIN playlist_tracks pt ON pt.track_id = t.id AND pt.playlist_id = ' . $id, '', [], 'pt.pos', 2000);
    }
}

/** Indique e ganhe: o indicado ganha desconto na 1ª assinatura e quem indicou acumula desconto */
final class Referral
{
    public static function enabled(): bool
    {
        return Settings::get('referral_enabled') === '1';
    }

    public static function maxPct(): int
    {
        return max(0, min(100, (int) Settings::get('referral_max_pct')));
    }

    /** Desconto que vale AGORA para este cliente (acumulado + bônus de boas-vindas do indicado) */
    public static function discountFor(array $acc): int
    {
        $pct = (int) $acc['discount_pct'];
        if (self::enabled() && $acc['referred_by'] && !Db::one("SELECT 1 FROM payments WHERE account_id = ? AND kind = 'renew' AND status = 'approved'", [$acc['id']])) {
            $pct += (int) Settings::get('referral_new_pct');
        }
        return max(0, min(self::maxPct(), $pct));
    }

    /** Chamado quando um pagamento de plano é aprovado */
    public static function onPaid(array $acc, array $pay): void
    {
        if ((int) $pay['discount_pct'] > 0 && (int) $acc['discount_pct'] > 0) {
            Db::exec('UPDATE accounts SET discount_pct = 0 WHERE id = ?', [$acc['id']]); // desconto acumulado foi usado
        }
        if (!self::enabled() || !$acc['referred_by']) {
            return;
        }
        $paid = (int) (Db::one("SELECT COUNT(*) c FROM payments WHERE account_id = ? AND kind = 'renew' AND status = 'approved'", [$acc['id']])['c'] ?? 0);
        if ($paid !== 1) {
            return; // só a primeira assinatura do indicado gera prêmio
        }
        $reward = (int) Settings::get('referral_reward_pct');
        $ref = Account::find((int) $acc['referred_by']);
        if ($ref && $reward > 0) {
            Db::exec('UPDATE accounts SET discount_pct = MIN(?, discount_pct + ?) WHERE id = ?', [self::maxPct(), $reward, $ref['id']]);
            Audit::log((int) $ref['id'], 'referral.reward', (int) $acc['id'], "+{$reward}% de desconto pela indicação de {$acc['username']}");
        }
    }

    public static function stats(array $acc): array
    {
        $invited = (int) (Db::one('SELECT COUNT(*) c FROM accounts WHERE referred_by = ?', [$acc['id']])['c'] ?? 0);
        $paid = (int) (Db::one("SELECT COUNT(DISTINCT a.id) c FROM accounts a JOIN payments p ON p.account_id = a.id AND p.kind = 'renew' AND p.status = 'approved' WHERE a.referred_by = ?", [$acc['id']])['c'] ?? 0);
        return [
            'enabled' => self::enabled(), 'url' => Settings::baseUrl() . '?i=' . rawurlencode($acc['username']),
            'new_pct' => (int) Settings::get('referral_new_pct'), 'reward_pct' => (int) Settings::get('referral_reward_pct'),
            'max_pct' => self::maxPct(), 'discount_pct' => (int) $acc['discount_pct'], 'current_pct' => self::discountFor($acc),
            'invited' => $invited, 'paid' => $paid,
        ];
    }
}
