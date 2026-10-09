<?php
declare(strict_types=1);

/**
 * Download alternativo quando o YouTube bloqueia o IP do servidor ("confirme que não é um robô").
 * Em vez de baixar direto do YouTube, o arquivo passa por servidores intermediários de projetos
 * open source do GitHub:
 *  - Invidious  (https://github.com/iv-org/invidious)  -> /latest_version?id=…&itag=…&local=true
 *  - Piped      (https://github.com/TeamPiped/Piped)    -> /streams/{id}
 *  - Cobalt     (https://github.com/imputnet/cobalt)    -> POST / {url, downloadMode}
 * As listas de instâncias públicas são buscadas ao vivo (e o admin pode cadastrar as próprias).
 */
final class Mirrors
{
    private const INVIDIOUS = ['https://inv.nadeko.net', 'https://invidious.nerdvpn.de', 'https://yewtu.be', 'https://invidious.privacyredirect.com', 'https://iv.melmac.space', 'https://invidious.f5.si'];
    private const PIPED = ['https://pipedapi.kavin.rocks', 'https://pipedapi.adminforge.de', 'https://api.piped.private.coffee', 'https://pipedapi.leptons.xyz'];

    private static function lines(string $key): array
    {
        return array_values(array_filter(array_map(fn($l) => rtrim(trim($l), '/'), preg_split('/[\s,]+/', Settings::get($key)) ?: []),
            fn($u) => (bool) preg_match('#^https?://[\w.-]+(:\d+)?(/.*)?$#', $u)));
    }

    public static function invidious(): array
    {
        $live = Cache::remember('mir:inv', 3600 * 6, function () {
            try {
                $list = Http::json('https://api.invidious.io/instances.json?sort_by=health', 15);
                $out = [];
                foreach ($list as $row) {
                    $i = $row[1] ?? [];
                    if (($i['type'] ?? '') === 'https' && !empty($i['api']) && !empty($i['uri'])) {
                        $out[] = rtrim((string) $i['uri'], '/');
                    }
                }
                return array_slice($out, 0, 8);
            } catch (Throwable $e) {
                return [];
            }
        });
        return array_values(array_unique(array_merge(self::lines('mirror_invidious'), $live, self::INVIDIOUS)));
    }

    public static function piped(): array
    {
        $live = Cache::remember('mir:piped', 3600 * 6, function () {
            try {
                $out = [];
                foreach (Http::json('https://piped-instances.kavin.rocks/', 15) as $i) {
                    if (!empty($i['api_url']) && ($i['up_to_date'] ?? true)) {
                        $out[] = rtrim((string) $i['api_url'], '/');
                    }
                }
                return array_slice($out, 0, 8);
            } catch (Throwable $e) {
                return [];
            }
        });
        return array_values(array_unique(array_merge(self::lines('mirror_piped'), $live, self::PIPED)));
    }

    /** Baixa com limites de velocidade/tempo para desistir rápido de servidores lentos */
    private static function fetch(string $url, string $dest, callable $progress, string $label, array $headers = []): void
    {
        $fp = fopen($dest, 'wb');
        $last = 0.0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_LOW_SPEED_LIMIT => 20000,
            CURLOPT_LOW_SPEED_TIME => 25,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function ($c, $total, $now) use ($progress, &$last, $label) {
                if ($total > 0 && microtime(true) - $last > 1) {
                    $last = microtime(true);
                    $progress(min(95.0, $now / $total * 95), $label);
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        fclose($fp);
        if (!$ok || $code >= 400 || str_contains($type, 'text/html') || str_contains($type, 'json')) {
            @unlink($dest);
            throw new RuntimeException($err ?: "HTTP {$code}");
        }
        if (!self::looksLikeMedia($dest)) {
            @unlink($dest);
            throw new RuntimeException('arquivo inválido');
        }
    }

    /** Confere a "assinatura" do arquivo (evita salvar página de erro como música) */
    public static function looksLikeMedia(string $file): bool
    {
        if (!is_file($file) || filesize($file) < 50000) {
            return false;
        }
        $h = (string) file_get_contents($file, false, null, 0, 12);
        return substr($h, 4, 4) === 'ftyp'                       // mp4 / m4a
            || str_starts_with($h, "\x1A\x45\xDF\xA3")           // webm / mkv
            || str_starts_with($h, 'ID3') || (ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0) // mp3
            || str_starts_with($h, 'OggS');                      // ogg / opus
    }

    private static function extFor(string $file): string
    {
        $h = (string) file_get_contents($file, false, null, 0, 12);
        if (substr($h, 4, 4) === 'ftyp') {
            return 'm4a';
        }
        if (str_starts_with($h, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }
        return str_starts_with($h, 'OggS') ? 'opus' : 'mp3';
    }

    /**
     * Tenta Cobalt, Invidious e Piped até um funcionar. Retorna o caminho do arquivo baixado.
     * @param array $log recebe uma linha por tentativa (para diagnóstico)
     */
    public static function download(string $id, string $kind, string $tmpDir, callable $progress, array &$log = []): string
    {
        $video = $kind === 'video';
        $raw = $tmpDir . '/mirror.part';
        $tries = 0;
        $done = function () use ($raw, $tmpDir, $video) {
            $final = $tmpDir . '/media.' . ($video ? 'mp4' : self::extFor($raw));
            rename($raw, $final);
            return $final;
        };

        // 1) Cobalt (servidor configurado pelo admin)
        $cobalt = rtrim(Settings::get('cobalt_url'), '/');
        if ($cobalt !== '') {
            try {
                $tries++;
                $headers = ['Accept: application/json', 'Content-Type: application/json'];
                if (Settings::get('cobalt_key') !== '') {
                    $headers[] = 'Authorization: Api-Key ' . Settings::get('cobalt_key');
                }
                $ch = curl_init($cobalt . '/');
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POSTFIELDS => json_encode(['url' => "https://www.youtube.com/watch?v={$id}", 'downloadMode' => $video ? 'auto' : 'audio',
                        'audioFormat' => 'best', 'videoQuality' => (string) cfg('video_max_height', 720)])]);
                $r = json_decode((string) curl_exec($ch), true) ?: [];
                if (!in_array($r['status'] ?? '', ['tunnel', 'redirect'], true) || empty($r['url'])) {
                    throw new RuntimeException((string) ($r['error']['code'] ?? 'sem link'));
                }
                $progress(2, 'Baixando (Cobalt)');
                self::fetch((string) $r['url'], $raw, $progress, 'Baixando (Cobalt)');
                $log[] = "✔ Cobalt {$cobalt}";
                return $done();
            } catch (Throwable $e) {
                $log[] = "✖ Cobalt {$cobalt}: " . $e->getMessage();
            }
        }

        // 2) Invidious: itag 140 = áudio AAC 128k (m4a) · itag 18 = vídeo mp4 360p com áudio
        foreach (self::invidious() as $inst) {
            if ($tries >= 8) {
                break;
            }
            try {
                $tries++;
                $label = 'Baixando (servidor ' . parse_url($inst, PHP_URL_HOST) . ')';
                $progress(2, $label);
                self::fetch("{$inst}/latest_version?id={$id}&itag=" . ($video ? '18' : '140') . '&local=true', $raw, $progress, $label);
                $log[] = "✔ Invidious {$inst}";
                return $done();
            } catch (Throwable $e) {
                $log[] = "✖ Invidious {$inst}: " . $e->getMessage();
            }
        }

        // 3) Piped
        foreach (self::piped() as $api) {
            if ($tries >= 12) {
                break;
            }
            try {
                $tries++;
                $s = Http::json("{$api}/streams/{$id}", 15);
                $url = null;
                if ($video) {
                    foreach ($s['videoStreams'] ?? [] as $v) {
                        if (empty($v['videoOnly']) && str_contains((string) ($v['mimeType'] ?? ''), 'mp4')) {
                            $url = $v['url'];
                            break;
                        }
                    }
                } else {
                    $streams = $s['audioStreams'] ?? [];
                    usort($streams, fn($a, $b) => [(int) str_contains((string) ($b['mimeType'] ?? ''), 'mp4'), (int) ($b['bitrate'] ?? 0)]
                        <=> [(int) str_contains((string) ($a['mimeType'] ?? ''), 'mp4'), (int) ($a['bitrate'] ?? 0)]);
                    $url = $streams[0]['url'] ?? null;
                }
                if (!$url) {
                    throw new RuntimeException('sem stream');
                }
                $label = 'Baixando (servidor ' . parse_url($api, PHP_URL_HOST) . ')';
                self::fetch((string) $url, $raw, $progress, $label);
                $log[] = "✔ Piped {$api}";
                return $done();
            } catch (Throwable $e) {
                $log[] = "✖ Piped {$api}: " . $e->getMessage();
            }
        }
        throw new RuntimeException('Nenhum servidor alternativo conseguiu baixar (' . $tries . ' tentativas)');
    }

    /** Converte para o formato configurado (mp3/opus) se houver ffmpeg */
    public static function convert(string $file, string $tmpDir, callable $progress): string
    {
        $fmt = Tools::audioFormat();
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $ff = Tools::ffmpeg();
        if (!$ff || !in_array($fmt, ['mp3', 'opus'], true) || $ext === $fmt) {
            return $file;
        }
        $progress(97, 'Convertendo');
        $kbps = max(64, min(320, (int) cfg('audio_bitrate', 128)));
        $out = $tmpDir . '/converted.' . $fmt;
        $args = $fmt === 'mp3'
            ? ['-c:a', 'libmp3lame', '-b:a', $kbps . 'k']
            : ['-c:a', 'libopus', '-b:a', max(64, (int) round($kbps * 0.75)) . 'k'];
        [$code, , $err] = Sys::run(array_merge([$ff, '-y', '-loglevel', 'error', '-i', $file, '-vn'], $args, [$out]), 900);
        if ($code !== 0 || !is_file($out) || filesize($out) < 10000) {
            return $file; // mantém o original (m4a toca em todo navegador)
        }
        @unlink($file);
        return $out;
    }
}
