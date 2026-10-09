<?php
declare(strict_types=1);

final class Cache
{
    public static function remember(string $key, int $ttl, callable $fn)
    {
        $row = Db::one('SELECT v, expires FROM cache WHERE k = ?', [$key]);
        if ($row && (int) $row['expires'] > time()) {
            return json_decode($row['v'], true);
        }
        $value = $fn();
        Db::exec(
            'INSERT INTO cache (k, v, expires) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, expires = excluded.expires',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), time() + $ttl]
        );
        if (random_int(1, 50) === 1) {
            Db::exec('DELETE FROM cache WHERE expires < ?', [time()]);
        }
        return $value;
    }
}
