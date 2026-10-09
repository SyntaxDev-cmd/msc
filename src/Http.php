<?php
declare(strict_types=1);

final class Http
{
    private const UA = 'Sonora/1.0 (self-hosted music library; +https://github.com/)';

    public static function get(string $url, int $timeout = 20, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($body === false || $code >= 400) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            throw new RuntimeException($code === 0
                ? "Sem conexão com {$host}. Verifique a internet do servidor. ({$err})"
                : "{$host} respondeu HTTP {$code}");
        }
        return (string) $body;
    }

    public static function json(string $url, int $timeout = 20, array $headers = []): array
    {
        $data = json_decode(self::get($url, $timeout, $headers), true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta JSON inválida');
        }
        return $data;
    }

    /** Baixa um arquivo grande direto para o disco, com callback de progresso (0-100) */
    public static function download(string $url, string $dest, ?callable $progress = null, int $timeout = 1800): void
    {
        $fp = fopen($dest, 'wb');
        if (!$fp) {
            throw new RuntimeException('Não foi possível gravar ' . basename($dest));
        }
        $last = 0.0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => $progress === null,
            CURLOPT_XFERINFOFUNCTION => function ($ch, $dlTotal, $dlNow) use ($progress, &$last) {
                if ($progress && $dlTotal > 0 && microtime(true) - $last > 1) {
                    $last = microtime(true);
                    $progress($dlNow / $dlTotal * 100);
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        fclose($fp);
        if (!$ok || $code >= 400) {
            @unlink($dest);
            throw new RuntimeException("Download falhou (HTTP {$code}) {$err}");
        }
    }
}
