<?php
declare(strict_types=1);

/**
 * Contas e hierarquia:  Administrador › Revenda Master › Revenda › Cliente
 *
 * Regras de permissão
 *  - Cada conta só enxerga/gerencia as contas ABAIXO dela na árvore (coluna path = "/1/5/").
 *  - Admin cria Master, Revenda e Cliente; Master cria Revenda e Cliente; Revenda cria Cliente.
 *  - Criar/renovar cliente consome créditos do plano (admin não consome — ele "emite" créditos).
 *  - Créditos são transferidos de cima para baixo (e podem ser recolhidos).
 *  - Limites: max_users (clientes na sub-árvore), max_resellers (revendas na sub-árvore),
 *    testes grátis por dia, downloads por dia, músicas na biblioteca, vídeo e offline.
 */
final class Account
{
    public const ROLES = ['admin' => 'Administrador', 'master' => 'Revenda Master', 'reseller' => 'Revenda', 'user' => 'Cliente'];
    public const RANK = ['user' => 1, 'reseller' => 2, 'master' => 3, 'admin' => 4];
    public const CHILDREN = ['admin' => ['master', 'reseller', 'user'], 'master' => ['reseller', 'user'], 'reseller' => ['user'], 'user' => []];

    public static function find(int $id): ?array
    {
        return $id > 0 ? Db::one('SELECT * FROM accounts WHERE id = ?', [$id]) : null;
    }

    public static function byUsername(string $u): ?array
    {
        return Db::one('SELECT * FROM accounts WHERE username = ? COLLATE NOCASE', [trim($u)]);
    }

    public static function isAdmin(?array $a): bool
    {
        return ($a['role'] ?? '') === 'admin';
    }

    public static function isReseller(?array $a): bool
    {
        return in_array($a['role'] ?? '', ['master', 'reseller'], true);
    }

    public static function hasAdmin(): bool
    {
        return (bool) Db::one("SELECT id FROM accounts WHERE role = 'admin' LIMIT 1");
    }

    public static function manages(array $actor, array $target): bool
    {
        if ((int) $actor['id'] === (int) $target['id']) {
            return false;
        }
        if (self::isAdmin($actor)) {
            return !self::isAdmin($target);
        }
        return str_starts_with((string) $target['path'], $actor['path'] . $actor['id'] . '/');
    }

    public static function managed(array $actor, int $id): array
    {
        $t = self::find($id);
        if (!$t || !self::manages($actor, $t)) {
            throw new DomainException('Conta não encontrada ou sem permissão');
        }
        return $t;
    }

    public static function expired(array $a): bool
    {
        return !self::isAdmin($a) && $a['expires_at'] !== null && (int) $a['expires_at'] > 0 && (int) $a['expires_at'] < time();
    }

    public static function daysLeft(array $a): ?int
    {
        if ($a['expires_at'] === null || (int) $a['expires_at'] === 0) {
            return null;
        }
        return (int) floor(((int) $a['expires_at'] - time()) / 86400);
    }

    public static function settings(array $a): array
    {
        return json_decode((string) ($a['settings'] ?? '{}'), true) ?: [];
    }

    public static function saveSettings(int $id, array $s): void
    {
        Db::exec('UPDATE accounts SET settings = ? WHERE id = ?', [json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    /** Ancestrais, do mais próximo ao mais distante */
    public static function ancestors(array $a): array
    {
        $ids = array_reverse(array_filter(array_map('intval', explode('/', (string) $a['path']))));
        $out = [];
        foreach ($ids as $id) {
            if ($p = self::find($id)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function validUsername(string $u): string
    {
        $u = trim($u);
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u)) {
            throw new InvalidArgumentException('Usuário deve ter 3-32 caracteres (letras, números, ponto, _ ou -)');
        }
        if (self::byUsername($u)) {
            throw new InvalidArgumentException('Este usuário já existe');
        }
        return $u;
    }

    private static function validPassword(string $p): string
    {
        if (mb_strlen($p) < 6) {
            throw new InvalidArgumentException('A senha precisa ter pelo menos 6 caracteres');
        }
        return password_hash($p, PASSWORD_DEFAULT);
    }

    private static function subtreeCount(array $acc, string $role): int
    {
        return (int) (Db::one('SELECT COUNT(*) c FROM accounts WHERE role = ? AND path LIKE ?', [$role, $acc['path'] . $acc['id'] . '/%'])['c'] ?? 0);
    }

    /** Verifica limites de todos os ancestrais (um master limita a árvore inteira dele) */
    private static function checkCapacity(array $parent, string $role): void
    {
        foreach (array_merge([$parent], self::ancestors($parent)) as $acc) {
            if ($role === 'user' && (int) $acc['max_users'] > 0 && self::subtreeCount($acc, 'user') >= (int) $acc['max_users']) {
                throw new DomainException("Limite de {$acc['max_users']} clientes atingido para {$acc['username']}");
            }
            if ($role === 'reseller' && (int) $acc['max_resellers'] > 0 && self::subtreeCount($acc, 'reseller') >= (int) $acc['max_resellers']) {
                throw new DomainException("Limite de {$acc['max_resellers']} revendas atingido para {$acc['username']}");
            }
        }
    }

    private static function debit(array $actor, int $amount, string $reason, int $targetId): void
    {
        if ($amount <= 0 || self::isAdmin($actor)) {
            return;
        }
        $n = Db::exec('UPDATE accounts SET credits = credits - ? WHERE id = ? AND credits >= ?', [$amount, $actor['id'], $amount]);
        if ($n === 0) {
            throw new DomainException("Créditos insuficientes (necessário: {$amount})");
        }
        Db::insert('credit_log', ['from_id' => $actor['id'], 'to_id' => $targetId, 'amount' => $amount, 'reason' => $reason, 'created_at' => time()]);
    }

    public static function planLimits(array $plan): array
    {
        return [
            'plan_id' => (int) $plan['id'], 'dl_per_day' => (int) $plan['dl_per_day'], 'max_tracks' => (int) $plan['max_tracks'],
            'allow_video' => (int) $plan['allow_video'], 'allow_offline' => (int) $plan['allow_offline'],
        ];
    }

    public static function create(array $actor, array $d): int
    {
        $role = (string) ($d['role'] ?? 'user');
        $parent = $actor;
        if (self::isAdmin($actor) && !empty($d['parent_id']) && (int) $d['parent_id'] !== (int) $actor['id']) {
            $parent = self::find((int) $d['parent_id']);
            if (!$parent || !self::isReseller($parent)) {
                throw new DomainException('Conta superior inválida');
            }
        } elseif (!empty($d['parent_id']) && (int) $d['parent_id'] !== (int) $actor['id']) {
            $parent = self::managed($actor, (int) $d['parent_id']);
        }
        if (!in_array($role, self::CHILDREN[$actor['role']], true) || !in_array($role, self::CHILDREN[$parent['role']], true)) {
            throw new DomainException('Você não tem permissão para criar este tipo de conta aqui');
        }
        if ((int) $parent['id'] !== (int) $actor['id'] && !self::isAdmin($actor) && !self::manages($actor, $parent)) {
            throw new DomainException('Sem permissão');
        }

        return Db::tx(function () use ($actor, $parent, $role, $d) {
            self::checkCapacity($parent, $role);
            $now = time();
            $row = [
                'parent_id' => (int) $parent['id'],
                'path' => $parent['path'] . $parent['id'] . '/',
                'role' => $role,
                'username' => self::validUsername((string) ($d['username'] ?? '')),
                'password_hash' => self::validPassword((string) ($d['password'] ?? '')),
                'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 80),
                'email' => mb_substr(trim((string) ($d['email'] ?? '')), 0, 120),
                'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30),
                'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500),
                'created_at' => $now,
            ];
            $cost = 0;
            $default = Plans::find((int) Settings::get('default_plan_id')) ?? Plans::all(true)[0] ?? null;

            if ($role === 'user') {
                if (!empty($d['trial'])) {
                    $limit = (int) Settings::get('trials_per_day');
                    $today = (int) (Db::one('SELECT COUNT(*) c FROM accounts WHERE is_trial = 1 AND parent_id = ? AND created_at > ?', [$parent['id'], strtotime('today')])['c'] ?? 0);
                    if (!self::isAdmin($actor) && $limit > 0 && $today >= $limit) {
                        throw new DomainException("Limite de {$limit} testes grátis por dia atingido");
                    }
                    if (!$default) {
                        throw new DomainException('Cadastre um plano antes');
                    }
                    $row += self::planLimits($default);
                    $row['is_trial'] = 1;
                    $row['expires_at'] = $now + max(1, (int) Settings::get('trial_days')) * 86400;
                } else {
                    $plan = Plans::find((int) ($d['plan_id'] ?? 0));
                    if (!$plan || !$plan['active']) {
                        throw new DomainException('Escolha um plano válido');
                    }
                    $row += self::planLimits($plan);
                    $row['expires_at'] = $now + (int) $plan['days'] * 86400;
                    $cost = (int) $plan['credits'];
                }
            } else {
                $days = (int) ($d['days'] ?? 0);
                $exp = $days > 0 ? $now + $days * 86400 : null;
                if (!self::isAdmin($actor) && $actor['expires_at']) {
                    $exp = $exp ? min($exp, (int) $actor['expires_at']) : (int) $actor['expires_at'];
                }
                $row['expires_at'] = $exp;
                $row['max_users'] = max(0, (int) ($d['max_users'] ?? 0));
                $row['max_resellers'] = $role === 'master' ? max(0, (int) ($d['max_resellers'] ?? 0)) : 0;
                $row['can_brand'] = (int) (!empty($d['can_brand']) && (self::isAdmin($actor) || (int) $actor['can_brand'] === 1));
                if ($default) {
                    $row += self::planLimits($default);
                }
            }

            self::debit($actor, $cost, 'Criação de ' . $row['username'], 0);
            $id = Db::insert('accounts', $row);
            if ($cost) {
                Db::exec('UPDATE credit_log SET to_id = ? WHERE id = (SELECT MAX(id) FROM credit_log)', [$id]);
            }
            $initial = (int) ($d['credits'] ?? 0);
            if ($initial > 0 && $role !== 'user') {
                self::transfer($actor, $id, $initial);
            }
            Audit::log((int) $actor['id'], 'account.create', $id, self::ROLES[$role] . ' ' . $row['username'] . ($cost ? " (-{$cost} créditos)" : '') . (!empty($row['is_trial']) ? ' [teste]' : ''));
            return $id;
        });
    }

    public static function update(array $actor, int $id, array $d): void
    {
        $t = self::managed($actor, $id);
        $set = [];
        foreach (['name' => 80, 'email' => 120, 'phone' => 30, 'notes' => 500] as $f => $max) {
            if (array_key_exists($f, $d)) {
                $set[$f] = mb_substr(trim((string) $d[$f]), 0, $max);
            }
        }
        if (isset($d['status']) && in_array($d['status'], ['active', 'blocked'], true)) {
            $set['status'] = $d['status'];
        }
        if (!empty($d['password'])) {
            $set['password_hash'] = self::validPassword((string) $d['password']);
        }
        foreach (['dl_per_day', 'max_tracks'] as $f) {
            if (isset($d[$f])) {
                $set[$f] = max(0, (int) $d[$f]);
            }
        }
        foreach (['allow_video', 'allow_offline'] as $f) {
            if (isset($d[$f])) {
                $set[$f] = (int) (bool) $d[$f];
            }
        }
        if (self::isReseller($t)) {
            if (isset($d['max_users'])) {
                $set['max_users'] = max(0, (int) $d['max_users']);
            }
            if (isset($d['max_resellers']) && $t['role'] === 'master') {
                $set['max_resellers'] = max(0, (int) $d['max_resellers']);
            }
            if (isset($d['can_brand']) && (self::isAdmin($actor) || (int) $actor['can_brand'] === 1)) {
                $set['can_brand'] = (int) (bool) $d['can_brand'];
            }
        }
        if (self::isAdmin($actor) && array_key_exists('expires_at', $d)) {
            $set['expires_at'] = $d['expires_at'] ? (int) strtotime($d['expires_at'] . ' 23:59:59') : null;
        }
        if (!$set) {
            return;
        }
        $sql = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($set)));
        Db::pdo()->prepare("UPDATE accounts SET $sql WHERE id = :id")->execute($set + ['id' => $id]);
        $info = implode(', ', array_diff(array_keys($set), ['password_hash'])) . (isset($set['password_hash']) ? ', senha' : '');
        Audit::log((int) $actor['id'], 'account.update', $id, $t['username'] . ': ' . $info);
    }

    /** Estende o vencimento aplicando o plano (usado por renovação manual e por pagamento) */
    public static function extendWithPlan(int $id, array $plan, int $periods = 1): void
    {
        $a = self::find($id);
        $base = max(time(), (int) ($a['expires_at'] ?? 0));
        $limits = self::planLimits($plan);
        Db::pdo()->prepare('UPDATE accounts SET expires_at = :e, is_trial = 0, plan_id = :plan_id, dl_per_day = :dl_per_day,
            max_tracks = :max_tracks, allow_video = :allow_video, allow_offline = :allow_offline WHERE id = :id')
            ->execute($limits + ['e' => $base + (int) $plan['days'] * 86400 * max(1, $periods), 'id' => $id]);
    }

    public static function renew(array $actor, int $id, int $planId, int $periods = 1): void
    {
        $t = self::managed($actor, $id);
        $plan = Plans::find($planId);
        if (!$plan) {
            throw new DomainException('Plano inválido');
        }
        $periods = max(1, min(24, $periods));
        Db::tx(function () use ($actor, $t, $plan, $periods) {
            $cost = (int) $plan['credits'] * $periods;
            self::debit($actor, $cost, "Renovação {$plan['name']} x{$periods}", (int) $t['id']);
            self::extendWithPlan((int) $t['id'], $plan, $periods);
            Audit::log((int) $actor['id'], 'account.renew', (int) $t['id'], "{$t['username']}: {$plan['name']} x{$periods}" . ($cost && !self::isAdmin($actor) ? " (-{$cost} créditos)" : ''));
        });
    }

    /** amount > 0 envia créditos; amount < 0 recolhe créditos da conta abaixo */
    public static function transfer(array $actor, int $id, int $amount): void
    {
        $t = self::managed($actor, $id);
        if (!self::isReseller($t)) {
            throw new DomainException('Só revendas recebem créditos');
        }
        if ($amount === 0) {
            return;
        }
        Db::tx(function () use ($actor, $t, $amount) {
            $admin = self::isAdmin($actor);
            if ($amount > 0) {
                if (!$admin && Db::exec('UPDATE accounts SET credits = credits - ? WHERE id = ? AND credits >= ?', [$amount, $actor['id'], $amount]) === 0) {
                    throw new DomainException('Você não tem créditos suficientes');
                }
                Db::exec('UPDATE accounts SET credits = credits + ? WHERE id = ?', [$amount, $t['id']]);
                Db::insert('credit_log', ['from_id' => $admin ? 0 : $actor['id'], 'to_id' => $t['id'], 'amount' => $amount, 'reason' => 'Transferência', 'created_at' => time()]);
            } else {
                $n = -$amount;
                if (Db::exec('UPDATE accounts SET credits = credits - ? WHERE id = ? AND credits >= ?', [$n, $t['id'], $n]) === 0) {
                    throw new DomainException("{$t['username']} não tem {$n} créditos para recolher");
                }
                if (!$admin) {
                    Db::exec('UPDATE accounts SET credits = credits + ? WHERE id = ?', [$n, $actor['id']]);
                }
                Db::insert('credit_log', ['from_id' => $t['id'], 'to_id' => $admin ? 0 : $actor['id'], 'amount' => $n, 'reason' => 'Recolhimento', 'created_at' => time()]);
            }
            Audit::log((int) $actor['id'], 'credits.transfer', (int) $t['id'], ($amount > 0 ? "+{$amount}" : (string) $amount) . " créditos para {$t['username']}");
        });
    }

    public static function delete(array $actor, int $id): void
    {
        $t = self::managed($actor, $id);
        if (Db::one('SELECT id FROM accounts WHERE parent_id = ? LIMIT 1', [$id])) {
            throw new DomainException('Esta conta tem contas abaixo dela. Exclua ou mova-as primeiro.');
        }
        Db::tx(function () use ($id) {
            Db::exec('DELETE FROM user_tracks WHERE user_id = ?', [$id]);
            Db::exec('DELETE FROM job_users WHERE user_id = ?', [$id]);
            Db::exec('DELETE FROM artist_follows WHERE user_id = ?', [$id]);
            Db::exec('DELETE FROM accounts WHERE id = ?', [$id]);
        });
        Audit::log((int) $actor['id'], 'account.delete', $id, self::ROLES[$t['role']] . ' ' . $t['username']);
    }

    /** O próprio usuário exclui a conta (exigência das lojas de apps). Pagamentos ficam guardados por obrigação fiscal. */
    public static function deleteSelf(array $u, string $password): void
    {
        if (!password_verify($password, (string) $u['password_hash'])) {
            throw new DomainException('Senha incorreta');
        }
        if ($u['role'] === 'admin') {
            throw new DomainException('O administrador principal não pode se excluir por aqui');
        }
        if (Db::one('SELECT id FROM accounts WHERE parent_id = ? LIMIT 1', [$u['id']])) {
            throw new DomainException('Sua revenda tem clientes. Fale com o suporte para transferi-los antes de excluir a conta.');
        }
        $id = (int) $u['id'];
        Db::tx(function () use ($id) {
            Db::exec('DELETE FROM playlist_tracks WHERE playlist_id IN (SELECT id FROM playlists WHERE user_id = ?)', [$id]);
            foreach (['playlists', 'play_events', 'stream_favs', 'artist_follows', 'user_tracks', 'job_users'] as $t) {
                Db::exec("DELETE FROM $t WHERE user_id = ?", [$id]);
            }
            Db::exec('UPDATE accounts SET referred_by = NULL WHERE referred_by = ?', [$id]);
            Db::exec('DELETE FROM accounts WHERE id = ?', [$id]);
        });
        Audit::log($id, 'account.self_delete', $id, $u['username']);
    }

    public static function list(array $actor, array $f): array
    {
        $where = ['a.id <> ?'];
        $args = [$actor['id']];
        if (!self::isAdmin($actor)) {
            $where[] = 'a.path LIKE ?';
            $args[] = $actor['path'] . $actor['id'] . '/%';
        }
        if (!empty($f['parent'])) {
            $where[] = 'a.parent_id = ?';
            $args[] = (int) $f['parent'];
        }
        if (!empty($f['role']) && isset(self::ROLES[$f['role']])) {
            $where[] = 'a.role = ?';
            $args[] = $f['role'];
        }
        if (!empty($f['q'])) {
            $where[] = '(a.username LIKE ? OR a.name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)';
            $q = '%' . $f['q'] . '%';
            array_push($args, $q, $q, $q, $q);
        }
        $now = time();
        switch ($f['status'] ?? '') {
            case 'active': $where[] = "a.status = 'active' AND (a.expires_at IS NULL OR a.expires_at > $now)"; break;
            case 'expired': $where[] = "a.expires_at IS NOT NULL AND a.expires_at <= $now"; break;
            case 'expiring': $where[] = 'a.expires_at > ' . $now . ' AND a.expires_at <= ' . ($now + 7 * 86400); break;
            case 'blocked': $where[] = "a.status = 'blocked'"; break;
            case 'trial': $where[] = 'a.is_trial = 1'; break;
        }
        $rows = Db::all('SELECT a.*, p.username AS parent_username, pl.name AS plan_name,
                (SELECT COUNT(*) FROM accounts c WHERE c.parent_id = a.id) AS children
            FROM accounts a LEFT JOIN accounts p ON p.id = a.parent_id LEFT JOIN plans pl ON pl.id = a.plan_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at DESC LIMIT 1000', $args);
        return array_map(fn($r) => self::publicRow($r) + ['parent_username' => $r['parent_username'], 'plan_name' => $r['plan_name'], 'children' => (int) $r['children']], $rows);
    }

    public static function usage(array $a): array
    {
        return [
            'downloads_today' => (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE user_id = ? AND created_at >= ? AND status <> 'error'", [$a['id'], strtotime('today')])['c'] ?? 0),
            'tracks' => (int) (Db::one('SELECT COUNT(*) c FROM user_tracks WHERE user_id = ?', [$a['id']])['c'] ?? 0),
        ];
    }

    public static function publicRow(array $a): array
    {
        $exp = self::expired($a);
        return [
            'id' => (int) $a['id'], 'parent_id' => (int) $a['parent_id'], 'role' => $a['role'], 'role_label' => self::ROLES[$a['role']] ?? $a['role'],
            'username' => $a['username'], 'name' => $a['name'], 'email' => $a['email'], 'phone' => $a['phone'],
            'status' => $a['status'], 'expires_at' => $a['expires_at'] !== null ? (int) $a['expires_at'] : null,
            'days_left' => self::daysLeft($a), 'expired' => $exp, 'plan_id' => (int) $a['plan_id'],
            'credits' => (int) $a['credits'], 'max_users' => (int) $a['max_users'], 'max_resellers' => (int) $a['max_resellers'],
            'dl_per_day' => (int) $a['dl_per_day'], 'max_tracks' => (int) $a['max_tracks'],
            'allow_video' => (bool) $a['allow_video'], 'allow_offline' => (bool) $a['allow_offline'],
            'can_brand' => (bool) $a['can_brand'], 'is_trial' => (bool) $a['is_trial'], 'notes' => $a['notes'],
            'created_at' => (int) $a['created_at'], 'last_login' => (int) $a['last_login'],
        ];
    }

    /** Marca exibida no login/cadastro (link de convite ?r=usuario_da_revenda) */
    public static function publicBrand(string $slug): array
    {
        $acc = $slug !== '' ? self::byUsername($slug) : null;
        if ($acc && (!self::isReseller($acc) || $acc['status'] !== 'active' || self::expired($acc))) {
            $acc = null;
        }
        $signup = Settings::get('signup_enabled') === '1' && (!$acc || (self::settings($acc)['signup'] ?? true));
        return self::brand($acc) + ['signup' => $signup, 'ref' => $acc['username'] ?? '', 'trial_days' => (int) Settings::get('trial_days')];
    }

    /** Marca efetiva: a da revenda mais próxima com white-label, senão a global */
    public static function brand(?array $a): array
    {
        $g = Settings::all();
        $brand = [
            'name' => $g['brand_name'], 'tagline' => $g['brand_tagline'], 'color' => $g['brand_color'], 'color2' => $g['brand_color2'],
            'logo' => $g['brand_logo'] ? Brand::logoUrl('global', $g['brand_logo']) : '', 'support_url' => $g['support_url'], 'owner_id' => 0,
        ];
        if (!$a) {
            return $brand;
        }
        foreach (array_merge([$a], self::ancestors($a)) as $acc) {
            if (!self::isReseller($acc) || !(int) $acc['can_brand']) {
                continue;
            }
            $b = self::settings($acc)['brand'] ?? [];
            if (empty($b['name'])) {
                continue;
            }
            return [
                'name' => $b['name'], 'tagline' => $b['tagline'] ?? '', 'color' => $b['color'] ?? $brand['color'],
                'color2' => $b['color2'] ?? $brand['color2'],
                'logo' => !empty($b['logo']) ? Brand::logoUrl('acc' . $acc['id'], $b['logo']) : $brand['logo'],
                'support_url' => $b['support_url'] ?? '', 'owner_id' => (int) $acc['id'],
            ];
        }
        return $brand;
    }
}

final class Plans
{
    public static function all(bool $activeOnly = false): array
    {
        return Db::all('SELECT * FROM plans' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, days');
    }

    public static function find(int $id): ?array
    {
        return $id > 0 ? Db::one('SELECT * FROM plans WHERE id = ?', [$id]) : null;
    }

    public static function save(array $d): int
    {
        $row = [
            'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 60),
            'days' => max(1, (int) ($d['days'] ?? 30)),
            'price' => max(0, round((float) ($d['price'] ?? 0), 2)),
            'credits' => max(0, (int) ($d['credits'] ?? 1)),
            'dl_per_day' => max(0, (int) ($d['dl_per_day'] ?? 50)),
            'max_tracks' => max(0, (int) ($d['max_tracks'] ?? 2000)),
            'allow_video' => (int) !empty($d['allow_video']),
            'allow_offline' => (int) !empty($d['allow_offline']),
            'highlight' => (int) !empty($d['highlight']),
            'active' => (int) !empty($d['active']),
            'sort' => (int) ($d['sort'] ?? 0),
        ];
        if ($row['name'] === '') {
            throw new InvalidArgumentException('Dê um nome ao plano');
        }
        if (!empty($d['id'])) {
            $sql = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
            Db::pdo()->prepare("UPDATE plans SET $sql WHERE id = :id")->execute($row + ['id' => (int) $d['id']]);
            return (int) $d['id'];
        }
        return Db::insert('plans', $row);
    }

    public static function delete(int $id): void
    {
        if (Db::one('SELECT id FROM accounts WHERE plan_id = ? LIMIT 1', [$id])) {
            Db::exec('UPDATE plans SET active = 0 WHERE id = ?', [$id]); // em uso: só desativa
            return;
        }
        Db::exec('DELETE FROM plans WHERE id = ?', [$id]);
    }

    public static function publicRow(array $p, ?float $price = null): array
    {
        return [
            'id' => (int) $p['id'], 'name' => $p['name'], 'days' => (int) $p['days'], 'price' => $price ?? (float) $p['price'],
            'base_price' => (float) $p['price'], 'credits' => (int) $p['credits'], 'dl_per_day' => (int) $p['dl_per_day'],
            'max_tracks' => (int) $p['max_tracks'], 'allow_video' => (bool) $p['allow_video'], 'allow_offline' => (bool) $p['allow_offline'],
            'highlight' => (bool) $p['highlight'], 'active' => (bool) $p['active'], 'sort' => (int) $p['sort'],
        ];
    }
}

/** Logos da marca (global e white-label por revenda) */
final class Brand
{
    public static function dir(): string
    {
        $d = storage_path('data/brand');
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        return $d;
    }

    public static function logoUrl(string $key, string $file): string
    {
        $path = self::dir() . '/' . basename($file);
        return is_file($path) ? 'brand.php?k=' . rawurlencode($key) . '&v=' . filemtime($path) : '';
    }

    /** Salva upload de logo (PNG/JPG/WEBP até 1 MB). Retorna o nome do arquivo */
    public static function saveUpload(array $file, string $key): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Envie uma imagem');
        }
        if ($file['size'] > 1024 * 1024) {
            throw new InvalidArgumentException('A imagem deve ter no máximo 1 MB');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) {
            throw new InvalidArgumentException('Formato aceito: PNG, JPG ou WEBP');
        }
        foreach (glob(self::dir() . '/' . $key . '.*') ?: [] as $old) {
            @unlink($old);
        }
        $name = $key . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], self::dir() . '/' . $name)) {
            throw new RuntimeException('Falha ao salvar a imagem');
        }
        return $name;
    }
}
