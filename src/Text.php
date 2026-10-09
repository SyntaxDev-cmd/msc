<?php
declare(strict_types=1);

final class Text
{
    /** minúsculas + remove acentos */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
            if (is_string($t)) {
                return $t;
            }
        }
        $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
        return strtr($s, $map);
    }

    /** Remove o "lixo" típico de títulos do YouTube */
    public static function cleanTitle(string $t): string
    {
        $junk = 'official|oficial|video|v[ií]deo|clipe|videoclipe|audio|[áa]udio|lyrics?|letra|legendad[oa]|visualizer|hd|hq|4k|remaster(ed)?|mv|music video|lyric video|clip officiel';
        $t = preg_replace('/[\(\[\{][^\)\]\}]*\b(' . $junk . ')\b[^\)\]\}]*[\)\]\}]/iu', '', $t) ?? $t;
        $t = preg_replace('/\s*[|｜].*$/u', '', $t) ?? $t;
        $t = preg_replace('/\s+-\s+(official|oficial)\b.*$/iu', '', $t) ?? $t;
        $t = preg_replace('/#\S+/u', '', $t) ?? $t;
        $t = trim($t, " \t\n\r\0\x0B\"'“”‘’-–—");
        return preg_replace('/\s{2,}/u', ' ', $t) ?? $t;
    }

    public static function primaryArtist(string $artist): string
    {
        $parts = preg_split('/\s*(,|&|\bfeat\.?|\bft\.?|\bpart\.?|\bx\b|\bvs\.?)\s*/iu', $artist);
        return trim($parts[0] ?? $artist) ?: $artist;
    }

    /** Chave de deduplicação: mesmo artista + mesma música = mesma chave */
    public static function key(string $artist, string $title): string
    {
        $a = self::fold(self::primaryArtist($artist));
        $t = self::fold(self::cleanTitle($title));
        $t = preg_replace('/[\(\[]?\b(feat|ft|part|participa[cç][aã]o|with)\b.*$/u', '', $t) ?? $t;
        $a = preg_replace('/[^a-z0-9]+/', '', $a) ?? $a;
        $t = preg_replace('/[^a-z0-9]+/', '', $t) ?? $t;
        return $a . '|' . $t;
    }

    /** Nome seguro de arquivo/pasta (mantém acentos) */
    public static function safeName(string $s, string $fallback = 'Desconhecido'): string
    {
        $s = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? '';
        $s = trim($s, " .\t");
        if (mb_strlen($s) > 100) {
            $s = rtrim(mb_substr($s, 0, 100), ' .');
        }
        return $s === '' ? $fallback : $s;
    }

    /** "Artista - Música (Clipe Oficial)" -> [artista, música] */
    public static function parseVideoTitle(string $title, string $channel): array
    {
        $clean = self::cleanTitle($title);
        $parts = preg_split('/\s+[-–—~]\s+/u', $clean, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return [trim($parts[0]), self::cleanTitle($parts[1])];
        }
        $artist = preg_replace('/\s*-\s*Topic$|VEVO$|\s*(official|oficial)$|\s*TV$/iu', '', $channel) ?? $channel;
        return [trim($artist) ?: 'Desconhecido', $clean ?: $title];
    }

    public static function similarity(string $a, string $b): float
    {
        $a = preg_replace('/[^a-z0-9]+/', '', self::fold($a)) ?? '';
        $b = preg_replace('/[^a-z0-9]+/', '', self::fold($b)) ?? '';
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return 100.0;
        }
        similar_text($a, $b, $pct);
        return $pct;
    }
}
