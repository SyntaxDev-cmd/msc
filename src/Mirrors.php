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
            $head = is_file($dest) ? (string) file_get_contents($dest, false, null, 0, 4000) : '';
            @unlink($dest);
            throw new RuntimeException($err ?: self::explain($code, $type, $head));
        }
        if (!self::looksLikeMedia($dest)) {
            @unlink($dest);
            throw new RuntimeException('arquivo inválido');
        }
    }

    /** Traduz respostas que não são áudio em algo compreensível no diagnóstico */
    private static function explain(int $code, string $type, string $head): string
    {
        if (preg_match('/anubis|not a bot|captcha|challenge|cf-chl|just a moment|ddos-guard|verify you are human/i', $head)) {
            return "HTTP {$code}: instância com proteção anti-robô (bloqueia downloads automáticos)";
        }
        if (str_contains($type, 'json')) {
            $j = json_decode($head, true);
            $msg = is_array($j) ? (string) ($j['error'] ?? $j['message'] ?? '') : '';
            return "HTTP {$code}: " . ($msg !== '' ? mb_substr($msg, 0, 120) : 'respondeu JSON em vez de áudio');
        }
        if (preg_match('/<title>([^<]{1,80})/i', $head, $m)) {
            return "HTTP {$code}: respondeu a página “" . trim($m[1]) . '” em vez do áudio';
        }
        return "HTTP {$code}: respondeu " . ($type ?: 'conteúdo desconhecido') . ' em vez do áudio';
    }

    /** Procura o link do áudio em qualquer formato de resposta (campos download/mp3/audio/link/url) */
    public static function findLink($node, int $depth = 0): ?string
    {
        if ($depth > 8 || !is_array($node)) {
            return null;
        }
        $fallback = null;
        foreach ($node as $k => $v) {
            if (is_string($v) && preg_match('#^https?://#', $v) && !preg_match('#youtube\.com|youtu\.be|ytimg|\.(jpe?g|png|webp)(\?|$)#i', $v)) {
                if (is_string($k) && preg_match('/download|mp3|audio|file|link/i', $k)) {
                    return $v;
                }
                $fallback ??= is_string($k) && preg_match('/url/i', $k) ? $v : null;
            }
        }
        foreach ($node as $v) {
            if (is_array($v) && ($found = self::findLink($v, $depth + 1))) {
                return $found;
            }
        }
        return $fallback;
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

        // 0) API de conversão no RapidAPI (youtube-mp36) — o download sai do servidor deles, não do seu
        $rapid = trim(Settings::get('rapidapi_key'));
        if ($rapid !== '' && !$video) {
            try {
                $tries++;
                $progress(2, 'Convertendo no servidor da API');
                $r = [];
                for ($i = 0; $i < 15; $i++) {
                    $r = Http::json("https://youtube-mp36.p.rapidapi.com/dl?id={$id}", 30, [
                        'X-RapidAPI-Key: ' . $rapid, 'X-RapidAPI-Host: youtube-mp36.p.rapidapi.com',
                    ]);
                    $busy = ($r['status'] ?? '') === 'processing' || (empty($r['link']) && (int) ($r['progress'] ?? 100) < 100 && ($r['status'] ?? '') !== 'fail');
                    if (!$busy) {
                        break;
                    }
                    sleep(2); // a API ainda está convertendo
                }
                if (($r['status'] ?? '') !== 'ok' || empty($r['link'])) {
                    throw new RuntimeException((string) ($r['msg'] ?? 'sem link'));
                }
                self::fetch((string) $r['link'], $raw, $progress, 'Baixando (API de conversão)', ['Referer: https://youtube-mp36.p.rapidapi.com/']);
                $log[] = '✔ API youtube-mp36';
                return $done();
            } catch (Throwable $e) {
                $log[] = '✖ API youtube-mp36: ' . $e->getMessage();
            }
        }

        // 0b) Apify (ator "YouTube to MP3") — também converte fora do seu servidor
        $apify = trim(Settings::get('apify_token'));
        if ($apify !== '' && !$video) {
            try {
                $tries++;
                $progress(2, 'Convertendo na Apify');
                $actor = str_replace('/', '~', trim(Settings::get('apify_actor')) ?: 'myagizm/youtube-mp3-downloader');
                $url = "https://www.youtube.com/watch?v={$id}";
                // envia o link nos nomes de campo mais comuns dos atores (os que o ator não usa são ignorados)
                $input = ['urls' => [$url], 'videoUrls' => [$url], 'youtubeUrls' => [$url], 'startUrls' => [['url' => $url]], 'url' => $url, 'videoUrl' => $url, 'format' => 'mp3'];
                $ch = curl_init('https://api.apify.com/v2/acts/' . rawurlencode($actor) . '/run-sync-get-dataset-items?timeout=240&token=' . rawurlencode($apify));
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 260,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($input)]);
                $resp = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $items = json_decode($resp, true);
                if ($code >= 400 || !is_array($items)) {
                    throw new RuntimeException("HTTP {$code} " . mb_substr((string) ($items['error']['message'] ?? $resp), 0, 150));
                }
                $link = self::findLink($items);
                if (!$link) {
                    throw new RuntimeException('o ator não devolveu link de áudio');
                }
                self::fetch($link, $raw, $progress, 'Baixando (Apify)');
                $log[] = '✔ Apify ' . $actor;
                return $done();
            } catch (Throwable $e) {
                $log[] = '✖ Apify: ' . $e->getMessage();
            }
        }

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
            if ($tries >= 6) {
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
            if ($tries >= 9) {
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
