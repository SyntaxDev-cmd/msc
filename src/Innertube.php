<?php
declare(strict_types=1);

/**
 * Cliente PHP da API interna do YouTube / YouTube Music ("Innertube"), a mesma usada pelo site.
 * Busca sem abrir processos no servidor (ideal para hospedagem compartilhada) e monta o catálogo
 * completo de um artista. Inspirado em https://github.com/sigma67/ytmusicapi e no yt-dlp.
 *
 * Os parsers procuram os "renderers" de forma recursiva (em vez de caminhos fixos), para continuar
 * funcionando quando o YouTube muda o layout das respostas.
 */
final class Innertube
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    private const WEB_VERSION = '2.20250917.01.00';
    private const MUSIC_VERSION = '1.20250915.01.00';
    private const P_VIDEOS = 'EgIQAQ==';                       // filtro: só vídeos
    private const P_CHANNELS = 'EgIQAg==';                     // filtro: só canais
    private const P_MUSIC_SONGS = 'EgWKAQIIAWoKEAkQBRAKEAMQBA=='; // YouTube Music: só músicas
    private const P_MUSIC_ARTISTS = 'EgWKAQIgAWoKEAkQBRAKEAMQBA=='; // YouTube Music: só artistas

    private static function clientVersion(bool $music): string
    {
        return (string) Cache::remember('it_ver_' . ($music ? 'm' : 'w'), 86400, function () use ($music) {
            try {
                $html = Http::get($music ? 'https://music.youtube.com/' : 'https://www.youtube.com/', 15, [
                    'User-Agent: ' . self::UA, 'Accept-Language: pt-BR,pt;q=0.9', 'Cookie: CONSENT=YES+1; SOCS=CAI',
                ]);
                if (preg_match('/"INNERTUBE_CLIENT_VERSION":"([\d.]+)"/', $html, $m)) {
                    return $m[1];
                }
            } catch (Throwable $e) {
            }
            return $music ? self::MUSIC_VERSION : self::WEB_VERSION;
        });
    }

    public static function call(string $endpoint, array $body, bool $music = false, string $extraQs = ''): array
    {
        return self::callMany([[$endpoint, $body, $extraQs]], $music)[0];
    }

    /** Várias chamadas em paralelo (curl_multi) — usado para abrir todos os álbuns de uma vez */
    /** Só para testes automatizados: fn(endpoint, body, music) => resposta */
    public static ?Closure $fake = null;

    public static function callMany(array $calls, bool $music = false, int $concurrency = 8): array
    {
        if (self::$fake) {
            return array_values(array_map(fn($c) => (self::$fake)($c[0], $c[1], $music), $calls));
        }
        $results = [];
        foreach (array_chunk($calls, $concurrency, true) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $i => [$endpoint, $body, $extraQs]) {
                $handles[$i] = self::handle($endpoint, $body, $music, $extraQs ?? '');
                curl_multi_add_handle($mh, $handles[$i]);
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $st === CURLM_OK);
            foreach ($handles as $i => $ch) {
                $raw = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                $data = json_decode((string) $raw, true);
                if (count($calls) === 1 && ($code >= 400 || !is_array($data))) {
                    throw new RuntimeException($raw === '' || $raw === null ? 'Sem conexão com o YouTube: ' . $err : "YouTube respondeu HTTP {$code}");
                }
                $results[$i] = is_array($data) && $code < 400 ? $data : [];
            }
            curl_multi_close($mh);
        }
        ksort($results);
        return array_values($results);
    }

    private static function handle(string $endpoint, array $body, bool $music, string $extraQs)
    {
        $host = $music ? 'https://music.youtube.com' : 'https://www.youtube.com';
        $ver = self::clientVersion($music);
        $body['context'] = ['client' => [
            'clientName' => $music ? 'WEB_REMIX' : 'WEB', 'clientVersion' => $ver,
            'hl' => 'pt', 'gl' => (string) cfg('country', 'BR'),
        ]];
        $ch = curl_init("{$host}/youtubei/v1/{$endpoint}?prettyPrint=false{$extraQs}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 'User-Agent: ' . self::UA, 'Origin: ' . $host, 'Referer: ' . $host . '/',
                'X-Youtube-Client-Name: ' . ($music ? '67' : '1'), 'X-Youtube-Client-Version: ' . $ver,
                'Accept-Language: pt-BR,pt;q=0.9', 'Cookie: CONSENT=YES+1; SOCS=CAI',
            ],
        ]);
        return $ch;
    }

    /* ---------------- utilitários de parsing ---------------- */

    /** Coleta (recursivamente) todos os objetos sob as chaves pedidas */
    public static function collect(array $node, array $keys, int $depth = 0): array
    {
        $out = [];
        if ($depth > 80) {
            return $out;
        }
        foreach ($node as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if (is_string($k) && in_array($k, $keys, true)) {
                $out[] = [$k, $v];
                continue;
            }
            foreach (self::collect($v, $keys, $depth + 1) as $hit) {
                $out[] = $hit;
            }
        }
        return $out;
    }

    public static function text($t): string
    {
        if (!is_array($t)) {
            return is_string($t) ? $t : '';
        }
        if (isset($t['simpleText'])) {
            return (string) $t['simpleText'];
        }
        if (isset($t['runs'])) {
            return implode('', array_map(fn($r) => (string) ($r['text'] ?? ''), $t['runs']));
        }
        return (string) ($t['content'] ?? '');
    }

    public static function seconds(string $s): int
    {
        if (!preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', trim($s), $m)) {
            return 0;
        }
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    private static function bestThumb(array $node): string
    {
        $list = $node['thumbnails'] ?? [];
        $url = (string) (end($list)['url'] ?? '');
        return str_starts_with($url, '//') ? 'https:' . $url : $url;
    }

    /** Próximo token de paginação */
    public static function continuation(array $data): ?string
    {
        foreach (self::collect($data, ['continuationItemRenderer']) as [, $c]) {
            $t = $c['continuationEndpoint']['continuationCommand']['token'] ?? null;
            if ($t) {
                return (string) $t;
            }
        }
        foreach (self::collect($data, ['nextContinuationData', 'continuationCommand']) as [, $c]) {
            $t = $c['continuation'] ?? $c['token'] ?? null;
            if ($t) {
                return (string) $t;
            }
        }
        return null;
    }

    /** Extrai vídeos de qualquer resposta (busca, canal, playlist) */
    public static function videos(array $data): array
    {
        $out = [];
        $hits = self::collect($data, ['videoRenderer', 'playlistVideoRenderer', 'gridVideoRenderer', 'compactVideoRenderer', 'lockupViewModel']);
        foreach ($hits as [$type, $v]) {
            if ($type === 'lockupViewModel') {
                if (($v['contentType'] ?? '') !== 'LOCKUP_CONTENT_TYPE_VIDEO' || empty($v['contentId'])) {
                    continue;
                }
                $meta = $v['metadata']['lockupMetadataViewModel'] ?? [];
                $badges = array_map(fn($b) => (string) ($b[1]['text'] ?? ''), self::collect($v, ['thumbnailBadgeViewModel']));
                $dur = 0;
                foreach ($badges as $b) {
                    $dur = $dur ?: self::seconds($b);
                }
                $chan = '';
                foreach (self::collect($meta, ['metadataParts']) as [, $parts]) {
                    $chan = $chan ?: (string) ($parts[0]['text']['content'] ?? '');
                }
                $out[] = ['id' => (string) $v['contentId'], 'title' => self::text($meta['title'] ?? []), 'channel' => $chan, 'duration' => $dur, 'views' => 0];
                continue;
            }
            $id = (string) ($v['videoId'] ?? '');
            if (strlen($id) !== 11) {
                continue;
            }
            $dur = isset($v['lengthSeconds']) ? (int) $v['lengthSeconds'] : self::seconds(self::text($v['lengthText'] ?? []));
            $chan = self::text($v['ownerText'] ?? $v['shortBylineText'] ?? $v['longBylineText'] ?? []);
            $views = (int) preg_replace('/\D+/', '', self::text($v['viewCountText'] ?? []));
            $out[] = ['id' => $id, 'title' => self::text($v['title'] ?? []), 'channel' => $chan, 'duration' => $dur, 'views' => $views];
        }
        return $out;
    }

    /* ---------------- busca ---------------- */

    public static function search(string $q, int $limit): array
    {
        return Cache::remember('itw:' . md5($q . $limit), 3600 * 6, function () use ($q, $limit) {
            $items = [];
            $seen = [];
            $data = self::call('search', ['query' => $q, 'params' => self::P_VIDEOS]);
            for ($page = 0; $page < 8; $page++) {
                foreach (self::videos($data) as $v) {
                    if (isset($seen[$v['id']]) || $v['duration'] <= 0) {
                        continue; // ao vivo / sem duração
                    }
                    $seen[$v['id']] = true;
                    $items[] = YouTube::mapEntry($v['id'], $v['title'], $v['channel'], $v['duration'], $v['views']);
                }
                $tok = self::continuation($data);
                if (count($items) >= $limit || !$tok) {
                    break;
                }
                $data = self::call('search', ['continuation' => $tok]);
            }
            return array_slice($items, 0, $limit);
        });
    }

    public static function searchChannels(string $q): array
    {
        $data = self::call('search', ['query' => $q, 'params' => self::P_CHANNELS]);
        $out = [];
        foreach (self::collect($data, ['channelRenderer']) as [, $c]) {
            if (empty($c['channelId'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $c['channelId'],
                'title' => self::text($c['title'] ?? []),
                'thumb' => self::bestThumb($c['thumbnail'] ?? []),
                'subscribers' => self::text($c['videoCountText'] ?? []) ?: self::text($c['subscriberCountText'] ?? []),
            ];
        }
        return $out;
    }

    /** Todos os envios de um canal (playlist "UU…" de uploads) */
    public static function channelUploads(string $channelId, int $max): array
    {
        if (!preg_match('/^UC[\w-]{22}$/', $channelId)) {
            return [];
        }
        $data = self::call('browse', ['browseId' => 'VLUU' . substr($channelId, 2)]);
        $out = [];
        $seen = [];
        for ($page = 0; $page < 10; $page++) {
            foreach (self::videos($data) as $v) {
                if (!isset($seen[$v['id']])) {
                    $seen[$v['id']] = true;
                    $out[] = $v;
                }
            }
            $tok = self::continuation($data);
            if (count($out) >= $max || !$tok) {
                break;
            }
            $data = self::call('browse', ['continuation' => $tok]);
        }
        return array_slice($out, 0, $max);
    }

    /** YouTube Music: músicas (áudio oficial) de um artista */
    public static function musicSongs(string $artist, int $max): array
    {
        $out = [];
        $seen = [];
        $data = self::call('search', ['query' => $artist, 'params' => self::P_MUSIC_SONGS], true);
        for ($page = 0; $page < 12; $page++) {
            foreach (self::collect($data, ['musicResponsiveListItemRenderer']) as [, $r]) {
                $s = self::parseMusicItem($r);
                if (!$s || isset($seen[$s['id']])) {
                    continue;
                }
                $seen[$s['id']] = true;
                $out[] = $s;
            }
            $tok = self::continuation($data);
            if (count($out) >= $max || !$tok) {
                break;
            }
            $qs = '&ctoken=' . rawurlencode($tok) . '&continuation=' . rawurlencode($tok) . '&type=next';
            $data = self::call('search', ['continuation' => $tok], true, $qs);
        }
        return $out;
    }

    public static function parseMusicItem(array $r): ?array
    {
        $id = (string) ($r['playlistItemData']['videoId'] ?? '');
        if ($id === '') {
            foreach (self::collect($r, ['watchEndpoint']) as [, $w]) {
                if (!empty($w['videoId'])) {
                    $id = (string) $w['videoId'];
                    break;
                }
            }
        }
        $cols = $r['flexColumns'] ?? [];
        if (strlen($id) !== 11 || !$cols) {
            return null;
        }
        $title = self::text($cols[0]['musicResponsiveListItemFlexColumnRenderer']['text'] ?? []);
        $runs = $cols[1]['musicResponsiveListItemFlexColumnRenderer']['text']['runs'] ?? [];
        foreach (array_slice($cols, 2) as $c) {
            $runs = array_merge($runs, [['text' => ' • ']], $c['musicResponsiveListItemFlexColumnRenderer']['text']['runs'] ?? []);
        }
        foreach ($r['fixedColumns'] ?? [] as $c) {
            $runs = array_merge($runs, [['text' => ' • ']], $c['musicResponsiveListItemFixedColumnRenderer']['text']['runs'] ?? []);
        }
        $artists = [];
        $album = '';
        $duration = 0;
        $plain = [];
        foreach ($runs as $run) {
            $t = trim((string) ($run['text'] ?? ''));
            $type = $run['navigationEndpoint']['browseEndpoint']['browseEndpointContextSupportedConfigs']['browseEndpointContextMusicConfig']['pageType'] ?? '';
            if ($t === '' || $t === '•' || $t === '&' || $t === ',') {
                continue;
            }
            if ($type === 'MUSIC_PAGE_TYPE_ARTIST' || $type === 'MUSIC_PAGE_TYPE_USER_CHANNEL') {
                $artists[] = $t;
            } elseif ($type === 'MUSIC_PAGE_TYPE_ALBUM') {
                $album = $t;
            } elseif (self::seconds($t) > 0) {
                $duration = self::seconds($t);
            } else {
                $plain[] = $t;
            }
        }
        if (!$artists) {
            $skip = ['música', 'musica', 'song', 'vídeo', 'video', 'single', 'ep'];
            foreach ($plain as $p) {
                if (!in_array(mb_strtolower($p), $skip, true) && !preg_match('/\d+\s*(mil|mi|k|m|bi)?\s*(reprodu|plays|visualiza)/iu', $p)) {
                    $artists[] = $p;
                    break;
                }
            }
        }
        $thumb = self::bestThumb($r['thumbnail']['musicThumbnailRenderer']['thumbnail'] ?? []);
        $thumb = preg_replace('/=w\d+-h\d+/', '=w544-h544', $thumb) ?? $thumb;
        return ['id' => $id, 'title' => $title, 'artists' => $artists, 'album' => $album, 'duration' => $duration, 'thumb' => $thumb];
    }

    private static function musicContinue(array $data, string $endpoint, int $maxPages, callable $each): void
    {
        for ($page = 0; $page < $maxPages; $page++) {
            foreach (self::collect($data, ['musicResponsiveListItemRenderer']) as [, $r]) {
                $each($r);
            }
            $tok = self::continuation($data);
            if (!$tok) {
                break;
            }
            $qs = '&ctoken=' . rawurlencode($tok) . '&continuation=' . rawurlencode($tok) . '&type=next';
            $data = self::call($endpoint, ['continuation' => $tok], true, $qs);
        }
    }

    /** Encontra o artista no YouTube Music (canal oficial com toda a discografia) */
    public static function musicArtist(string $name): ?array
    {
        $data = self::call('search', ['query' => $name, 'params' => self::P_MUSIC_ARTISTS], true);
        $best = null;
        $bestScore = 0.0;
        foreach (self::collect($data, ['musicResponsiveListItemRenderer']) as $i => [, $r]) {
            $id = (string) ($r['navigationEndpoint']['browseEndpoint']['browseId'] ?? '');
            if (!str_starts_with($id, 'UC')) {
                continue;
            }
            $title = self::text($r['flexColumns'][0]['musicResponsiveListItemFlexColumnRenderer']['text'] ?? []);
            $score = Text::similarity($title, $name) - $i; // empate: o primeiro resultado (mais relevante)
            if ($score > $bestScore && Text::similarity($title, $name) >= 80) {
                $bestScore = $score;
                $best = [
                    'id' => $id, 'name' => $title,
                    'thumb' => preg_replace('/=w\d+-h\d+/', '=w544-h544', self::bestThumb($r['thumbnail']['musicThumbnailRenderer']['thumbnail'] ?? [])),
                    'subscribers' => trim((string) (explode('•', self::text($r['flexColumns'][1]['musicResponsiveListItemFlexColumnRenderer']['text'] ?? []))[1] ?? '')),
                ];
            }
        }
        return $best;
    }

    /**
     * Discografia completa no YouTube Music: lista "todas as músicas" do artista +
     * as faixas de TODOS os álbuns, singles e EPs (abertos em paralelo).
     * @return array lista no formato de parseMusicItem (+ album)
     */
    public static function musicDiscography(array $artist, int $maxAlbums = 80): array
    {
        $page = self::call('browse', ['browseId' => $artist['id']], true);
        $tracks = [];
        $add = function (?array $t, string $album = '', string $thumb = '') use (&$tracks, $artist) {
            if (!$t || isset($tracks[$t['id']])) {
                return;
            }
            $t['artists'] = $t['artists'] ?: [$artist['name']];
            $t['album'] = $t['album'] ?: $album;
            $t['thumb'] = $t['thumb'] ?: $thumb;
            $tracks[$t['id']] = $t;
        };

        // 1) "Músicas" › Ver tudo (playlist com todas as músicas do artista)
        $songsId = null;
        foreach (self::collect($page, ['musicShelfRenderer']) as [, $shelf]) {
            foreach (self::collect(['t' => $shelf['title'] ?? [], 'b' => $shelf['bottomEndpoint'] ?? []], ['browseEndpoint']) as [, $be]) {
                if (str_starts_with((string) ($be['browseId'] ?? ''), 'VL')) {
                    $songsId = (string) $be['browseId'];
                    break 2;
                }
            }
            foreach (self::collect($shelf, ['musicResponsiveListItemRenderer']) as [, $r]) {
                $add(self::parseMusicItem($r));
            }
        }
        if ($songsId) {
            try {
                self::musicContinue(self::call('browse', ['browseId' => $songsId], true), 'browse', 15, fn($r) => $add(self::parseMusicItem($r)));
            } catch (Throwable $e) {
            }
        }

        // 2) Álbuns, singles e EPs (com "ver tudo" quando existir)
        $albums = [];
        foreach (self::collect($page, ['musicCarouselShelfRenderer']) as [, $car]) {
            $title = mb_strtolower(self::text($car['header']['musicCarouselShelfBasicHeaderRenderer']['title'] ?? []));
            if (!preg_match('/álbu|albu|single|ep\b|lançamento|release/u', $title)) {
                continue;
            }
            $items = self::collect($car, ['musicTwoRowItemRenderer']);
            foreach (self::collect($car['header'] ?? [], ['browseEndpoint']) as [, $more]) {
                if (!empty($more['params'])) {
                    try {
                        $items = array_merge($items, self::collect(self::call('browse', ['browseId' => $more['browseId'], 'params' => $more['params']], true), ['musicTwoRowItemRenderer']));
                    } catch (Throwable $e) {
                    }
                    break;
                }
            }
            foreach ($items as [, $it]) {
                $bid = (string) ($it['navigationEndpoint']['browseEndpoint']['browseId'] ?? '');
                if (str_starts_with($bid, 'MPREb')) {
                    $albums[$bid] = [self::text($it['title'] ?? []), self::bestThumb($it['thumbnailRenderer']['musicThumbnailRenderer']['thumbnail'] ?? [])];
                }
            }
        }
        $albums = array_slice($albums, 0, $maxAlbums, true);
        $pages = self::callMany(array_map(fn($bid) => ['browse', ['browseId' => $bid], ''], array_keys($albums)), true);
        foreach (array_values($albums) as $i => [$albumTitle, $thumb]) {
            $thumb = preg_replace('/=w\d+-h\d+/', '=w544-h544', $thumb) ?? $thumb;
            foreach (self::collect($pages[$i] ?? [], ['musicResponsiveListItemRenderer']) as [, $r]) {
                $add(self::parseMusicItem($r), $albumTitle, $thumb);
            }
        }
        return array_values($tracks);
    }

    /**
     * Catálogo completo de um artista no YouTube:
     *  1) todas as músicas dele no YouTube Music (áudio oficial)
     *  2) + os vídeos do canal oficial (clipes, ao vivo, parcerias) que ainda não apareceram
     */
    public static function artistCatalog(string $name, int $max = 800): array
    {
        return Cache::remember('ita:' . md5(mb_strtolower($name) . $max), 3600 * 12, function () use ($name, $max) {
            $errors = [];
            $items = [];
            $keys = [];
            $add = function (array $item) use (&$items, &$keys) {
                $k = Text::key($item['artist'], $item['title']);
                if (isset($keys['id:' . $item['source_id']]) || isset($keys[$k])) {
                    return;
                }
                $keys['id:' . $item['source_id']] = $keys[$k] = true;
                $items[] = $item;
            };

            $musicArtist = null;
            try {
                $musicArtist = self::musicArtist($name);
                if ($musicArtist) {
                    foreach (self::musicDiscography($musicArtist) as $s) {
                        $artist = implode(', ', $s['artists']);
                        $add([
                            'source' => 'youtube', 'source_id' => $s['id'], 'title' => $s['title'], 'artist' => $artist,
                            'raw_title' => $artist . ' - ' . $s['title'], 'channel' => 'YouTube Music', 'album' => $s['album'],
                            'genre' => '', 'duration' => $s['duration'], 'views' => 0,
                            'thumb' => $s['thumb'] ?: "https://i.ytimg.com/vi/{$s['id']}/hqdefault.jpg", 'kinds' => ['audio', 'video'],
                        ]);
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'Discografia: ' . $e->getMessage();
            }

            try {
                foreach (self::musicSongs($name, 250) as $s) {
                    $match = false;
                    foreach ($s['artists'] as $a) {
                        if (Text::similarity($a, $name) >= 80) {
                            $match = true;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                    $artist = implode(', ', $s['artists']);
                    $add([
                        'source' => 'youtube', 'source_id' => $s['id'], 'title' => $s['title'], 'artist' => $artist,
                        'raw_title' => $artist . ' - ' . $s['title'], 'channel' => 'YouTube Music', 'album' => $s['album'],
                        'genre' => '', 'duration' => $s['duration'], 'views' => 0,
                        'thumb' => $s['thumb'] ?: "https://i.ytimg.com/vi/{$s['id']}/hqdefault.jpg", 'kinds' => ['audio', 'video'],
                    ]);
                }
            } catch (Throwable $e) {
                $errors[] = 'YouTube Music: ' . $e->getMessage();
            }

            $channel = null;
            try {
                $best = 0.0;
                foreach (self::searchChannels($name) as $c) {
                    $clean = preg_replace('/\s*(-\s*Topic|VEVO|Oficial|Official|TV)$/iu', '', $c['title']) ?? $c['title'];
                    $score = Text::similarity($clean, $name) - (str_ends_with($c['title'], 'Topic') ? 5 : 0);
                    if ($score > $best && $score >= 80) {
                        $best = $score;
                        $channel = $c;
                    }
                }
                if ($channel) {
                    foreach (self::channelUploads($channel['id'], 500) as $v) {
                        if ($v['duration'] && ($v['duration'] < 60 || $v['duration'] > 1500)) {
                            continue; // shorts e lives longas
                        }
                        $add(YouTube::mapEntry($v['id'], $v['title'], $v['channel'] ?: $channel['title'], $v['duration'], $v['views']));
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'Canal: ' . $e->getMessage();
            }

            if (!$items && $errors) {
                throw new RuntimeException(implode(' | ', $errors));
            }
            $info = $musicArtist ?: ($channel ? ['name' => $channel['title'], 'thumb' => $channel['thumb'], 'subscribers' => $channel['subscribers']] : null);
            return [
                'artist' => [
                    'name' => $info['name'] ?? $name, 'thumb' => $info['thumb'] ?? ($items[0]['thumb'] ?? ''),
                    'subscribers' => $info['subscribers'] ?? '', 'channel_id' => $channel['id'] ?? '',
                ],
                'items' => array_slice($items, 0, $max),
            ];
        });
    }
}
