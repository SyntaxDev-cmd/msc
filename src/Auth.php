<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;

    public static function configured(): bool
    {
        return Account::hasAdmin();
    }

    /** @return string|null mensagem de erro */
    public static function attempt(string $username, string $password): ?string
    {
        $key = 'login:' . client_ip();
        if (Throttle::count($key, 900) >= 8) {
            return 'Muitas tentativas. Aguarde 15 minutos.';
        }
        $a = Account::byUsername($username);
        if (!$a || !password_verify($password, (string) $a['password_hash'])) {
            Throttle::hit($key);
            usleep(300000);
            return 'Usuário ou senha incorretos';
        }
        if ($a['status'] === 'blocked') {
            return 'Conta bloqueada. Fale com seu revendedor.';
        }
        Throttle::clear($key);
        self::loginAs((int) $a['id']);
        if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE accounts SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $a['id']]);
        }
        return null;
    }

    public static function loginAs(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        Db::exec('UPDATE accounts SET last_login = ? WHERE id = ?', [time(), $id]);
        self::$user = null;
    }

    /** Suporte: admin/revenda entra na conta de um cliente abaixo dele */
    public static function impersonate(array $actor, int $id): void
    {
        $t = Account::managed($actor, $id);
        $origin = $_SESSION['impersonator'] ?? $actor['id'];
        Audit::log((int) $actor['id'], 'account.impersonate', $id, $t['username']);
        self::loginAs($id);
        $_SESSION['impersonator'] = $origin;
    }

    public static function stopImpersonating(): bool
    {
        $origin = (int) ($_SESSION['impersonator'] ?? 0);
        if (!$origin) {
            return false;
        }
        unset($_SESSION['impersonator']);
        self::loginAs($origin);
        return true;
    }

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            self::$user = Account::find((int) $_SESSION['uid']);
            if (!self::$user || self::$user['status'] === 'blocked') {
                self::$user = null;
                unset($_SESSION['uid']);
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): bool
    {
        $sent = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
        return is_string($sent) && $sent !== '' && hash_equals(self::csrf(), $sent);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        self::$user = null;
    }
}
