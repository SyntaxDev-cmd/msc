<?php
declare(strict_types=1);

/** Configurações globais editáveis pelo painel admin (guardadas no SQLite) */
final class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'brand_name' => (string) cfg('app_name', 'Sonora'),
            'brand_tagline' => 'Sua música. Seu servidor.',
            'brand_color' => '#8b5cf6',
            'brand_color2' => '#22d3ee',
            'brand_logo' => '',
            'support_url' => '',
            'mp_access_token' => '',
            'mp_public_key' => '',
            'credit_packages' => "10=90\n30=240\n100=700",
            'signup_enabled' => '0',
            'trial_days' => '1',
            'trials_per_day' => '10',
            'default_plan_id' => '1',
            'public_url' => '',
            'reseller_mp' => '1',
            'mirrors_enabled' => '1',
            'mirror_invidious' => '',
            'mirror_piped' => '',
            'cobalt_url' => '',
            'cobalt_key' => '',
            'yt_proxy' => '',
            'yt_clients' => '',
            'rapidapi_key' => '',
            'apify_token' => '',
            'agent_token' => '',
            'agent_seen' => '0',
            'apify_actor' => 'myagizm/youtube-mp3-downloader',
            'referral_enabled' => '1',
            'referral_new_pct' => '10',
            'referral_reward_pct' => '20',
            'referral_max_pct' => '50',
            // App Android (WebView): o app abre o site, então quase tudo atualiza sozinho
            'app_store_mode' => '0',          // 1 = esconde downloads do YouTube dentro do app (regras da Play Store)
            'app_apk_url' => '',              // vazio = download/zmusic.apk deste site
            'app_latest_version_code' => '1', // versão nativa mais nova (o app avisa quem tiver uma menor)
            'app_min_version_code' => '0',    // abaixo disso o app exige atualizar
            'app_message' => '',
            'app_sha256' => '10:BF:6C:EF:22:70:1A:B1:3A:88:16:25:CD:5D:5E:D6:46:B8:90:86:F0:33:C9:8B:EB:6F:77:A1:A7:C1:CF:7B',
            'app_package' => 'sbs.zcloudpro.zmusic',
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Db::all('SELECT k, v FROM settings');
            self::$cache = array_replace(self::defaults(), array_column($rows, 'v', 'k'));
        }
        return self::$cache;
    }

    public static function get(string $k): string
    {
        return (string) (self::all()[$k] ?? '');
    }

    public static function set(array $values): void
    {
        $allowed = array_keys(self::defaults());
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            Db::exec('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, (string) $v]);
        }
        self::$cache = null;
    }

    /** Link de download do APK (o configurado no painel ou o arquivo download/zmusic.apk do site) */
    public static function apkUrl(): string
    {
        $u = trim(self::get('app_apk_url'));
        if ($u !== '') {
            return $u;
        }
        return is_file(APP_ROOT . '/download/zmusic.apk') ? self::baseUrl() . 'download/zmusic.apk' : '';
    }

    /** A requisição veio de dentro do app Android? */
    public static function inApp(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'zMusicApp/');
    }

    /** URL pública do sistema (para webhooks e links de convite) */
    public static function baseUrl(): string
    {
        $u = trim(self::get('public_url'));
        if ($u !== '') {
            return rtrim($u, '/') . '/';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
    }

    /** "10=90\n30=240" -> [['qty'=>10,'price'=>90.0], ...] */
    public static function creditPackages(): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', self::get('credit_packages')) as $line) {
            if (preg_match('/^\s*(\d+)\s*=\s*([\d.,]+)\s*$/', $line, $m)) {
                $out[] = ['qty' => (int) $m[1], 'price' => round((float) str_replace(',', '.', $m[2]), 2)];
            }
        }
        return $out;
    }
}

final class Audit
{
    public static function log(int $actorId, string $action, int $targetId = 0, string $info = ''): void
    {
        Db::insert('audit', [
            'actor_id' => $actorId, 'action' => $action, 'target_id' => $targetId,
            'info' => mb_substr($info, 0, 500), 'ip' => client_ip(), 'created_at' => time(),
        ]);
    }
}

/** Limite de tentativas (login, cadastro) por chave */
final class Throttle
{
    public static function hit(string $key): void
    {
        Db::insert('throttle', ['k' => $key, 'at' => time()]);
        if (random_int(1, 30) === 1) {
            Db::exec('DELETE FROM throttle WHERE at < ?', [time() - 86400 * 2]);
        }
    }

    public static function count(string $key, int $seconds): int
    {
        return (int) (Db::one('SELECT COUNT(*) c FROM throttle WHERE k = ? AND at > ?', [$key, time() - $seconds])['c'] ?? 0);
    }

    public static function clear(string $key): void
    {
        Db::exec('DELETE FROM throttle WHERE k = ?', [$key]);
    }
}
