<?php
declare(strict_types=1);

final class Auth
{
    private static function file(): string
    {
        return storage_path('data/auth.json');
    }

    public static function configured(): bool
    {
        return is_file(self::file());
    }

    public static function setPassword(string $password): void
    {
        if (mb_strlen($password) < 6) {
            throw new InvalidArgumentException('A senha precisa ter pelo menos 6 caracteres.');
        }
        file_put_contents(self::file(), json_encode(['hash' => password_hash($password, PASSWORD_DEFAULT)]));
        @chmod(self::file(), 0600);
    }

    public static function attempt(string $password): bool
    {
        $data = json_decode((string) @file_get_contents(self::file()), true);
        if (!is_array($data) || !password_verify($password, (string) ($data['hash'] ?? ''))) {
            usleep(400000); // freia força bruta
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['auth']);
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
        $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
        return is_string($sent) && hash_equals(self::csrf(), $sent);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
