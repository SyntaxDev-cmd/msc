<?php
declare(strict_types=1);

/**
 * Detecta e instala as ferramentas externas dentro de /bin (sem root, ideal p/ Hostinger):
 *  - yt-dlp  (https://github.com/yt-dlp/yt-dlp)   -> busca e download
 *  - deno    (https://github.com/denoland/deno)   -> runtime JS que o yt-dlp usa no YouTube
 *  - ffmpeg  (https://github.com/yt-dlp/FFmpeg-Builds) -> conversão MP3/Opus (opcional)
 */
final class Tools
{
    private static ?array $state = null;

    private static function file(): string
    {
        return storage_path('data/tools.json');
    }

    public static function state(bool $refresh = false): array
    {
        if (!$refresh && self::$state !== null) {
            return self::$state;
        }
        if (!$refresh && is_file(self::file())) {
            $s = json_decode((string) file_get_contents(self::file()), true);
            if (is_array($s) && ($s['checked_at'] ?? 0) > time() - 86400) {
                return self::$state = $s;
            }
        }
        return self::$state = self::detect();
    }

    public static function detect(): array
    {
        $s = ['checked_at' => time(), 'exec' => Sys::canExec(), 'ytdlp' => null, 'ytdlp_version' => null,
              'ffmpeg' => null, 'deno' => null, 'python' => null];
        if ($s['exec']) {
            $s['python'] = self::findPython();
            $candidates = [];
            if (is_file(APP_ROOT . '/bin/yt-dlp') && $s['python']) {
                $candidates[] = [$s['python'], APP_ROOT . '/bin/yt-dlp'];
            }
            if (is_file(APP_ROOT . '/bin/yt-dlp_linux')) {
                @chmod(APP_ROOT . '/bin/yt-dlp_linux', 0755);
                $candidates[] = [APP_ROOT . '/bin/yt-dlp_linux'];
            }
            $candidates[] = ['yt-dlp'];
            foreach ($candidates as $cmd) {
                $v = self::tryVersion(array_merge($cmd, ['--version']));
                if ($v) {
                    $s['ytdlp'] = $cmd;
                    $s['ytdlp_version'] = $v;
                    break;
                }
            }
            foreach ([APP_ROOT . '/bin/ffmpeg', 'ffmpeg'] as $ff) {
                if (self::tryVersion([$ff, '-version'])) {
                    $s['ffmpeg'] = $ff;
                    break;
                }
            }
            foreach ([APP_ROOT . '/bin/deno', 'deno'] as $d) {
                if (self::tryVersion([$d, '--version'])) {
                    $s['deno'] = $d;
                    break;
                }
            }
        }
        @file_put_contents(self::file(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $s;
    }

    private static function tryVersion(array $cmd): ?string
    {
        try {
            [$code, $out] = Sys::run($cmd, 60);
            $line = trim(strtok($out, "\n") ?: '');
            return $code === 0 && $line !== '' ? $line : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function findPython(): ?string
    {
        foreach (['python3', 'python3.13', 'python3.12', 'python3.11', 'python3.10', '/usr/bin/python3', '/usr/local/bin/python3'] as $py) {
            try {
                [$code, $out] = Sys::run([$py, '-c', 'import sys;print(sys.version_info >= (3, 10))'], 20);
                if ($code === 0 && trim($out) === 'True') {
                    return $py;
                }
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    public static function ytdlp(): ?array
    {
        return self::state()['ytdlp'] ?? null;
    }

    public static function ffmpeg(): ?string
    {
        return self::state()['ffmpeg'] ?? null;
    }

    /** Formato final do áudio, respeitando o que o servidor consegue fazer */
    public static function audioFormat(): string
    {
        $want = (string) cfg('audio_format', 'auto');
        $hasFf = (bool) self::ffmpeg();
        if ($want === 'auto') {
            return $hasFf ? 'mp3' : 'm4a';
        }
        if (in_array($want, ['mp3', 'opus'], true) && !$hasFf) {
            return 'm4a';
        }
        return in_array($want, ['mp3', 'opus', 'm4a'], true) ? $want : 'm4a';
    }

    private static function arch(): string
    {
        $m = strtolower(php_uname('m'));
        return (str_contains($m, 'aarch64') || str_contains($m, 'arm64')) ? 'arm64' : 'x64';
    }

    public static function installYtdlp(): string
    {
        @mkdir(APP_ROOT . '/bin', 0755, true);
        // 1) zipapp (3 MB, precisa de Python >= 3.10 — a Hostinger costuma ter)
        if (self::findPython()) {
            Http::download('https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp', APP_ROOT . '/bin/yt-dlp.new');
            rename(APP_ROOT . '/bin/yt-dlp.new', APP_ROOT . '/bin/yt-dlp');
            @chmod(APP_ROOT . '/bin/yt-dlp', 0755);
        } else {
            // 2) binário standalone (não precisa de Python)
            $name = self::arch() === 'arm64' ? 'yt-dlp_linux_aarch64' : 'yt-dlp_linux';
            Http::download("https://github.com/yt-dlp/yt-dlp/releases/latest/download/{$name}", APP_ROOT . '/bin/yt-dlp_linux.new');
            rename(APP_ROOT . '/bin/yt-dlp_linux.new', APP_ROOT . '/bin/yt-dlp_linux');
            @chmod(APP_ROOT . '/bin/yt-dlp_linux', 0755);
        }
        $s = self::detect();
        if (!$s['ytdlp']) {
            throw new RuntimeException('yt-dlp foi baixado mas não executou. Verifique se proc_open está liberado.');
        }
        return (string) $s['ytdlp_version'];
    }

    public static function installDeno(): string
    {
        $target = self::arch() === 'arm64' ? 'aarch64-unknown-linux-gnu' : 'x86_64-unknown-linux-gnu';
        $zip = storage_path('tmp/deno.zip');
        Http::download("https://github.com/denoland/deno/releases/latest/download/deno-{$target}.zip", $zip);
        self::unzip($zip, APP_ROOT . '/bin');
        @unlink($zip);
        @chmod(APP_ROOT . '/bin/deno', 0755);
        $s = self::detect();
        if (!$s['deno']) {
            throw new RuntimeException('Deno baixado mas não executou neste servidor.');
        }
        return 'ok';
    }

    public static function installFfmpeg(): string
    {
        $name = self::arch() === 'arm64' ? 'ffmpeg-master-latest-linuxarm64-gpl' : 'ffmpeg-master-latest-linux64-gpl';
        $tar = storage_path('tmp/ffmpeg.tar.xz');
        Http::download("https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/{$name}.tar.xz", $tar);
        [$code, , $err] = Sys::run(['tar', '-xJf', $tar, '-C', APP_ROOT . '/bin', '--strip-components=2', '--wildcards',
            "{$name}/bin/ffmpeg", "{$name}/bin/ffprobe"], 600);
        @unlink($tar);
        if ($code !== 0) {
            throw new RuntimeException('Falha ao extrair ffmpeg: ' . Sys::lastLines($err));
        }
        @chmod(APP_ROOT . '/bin/ffmpeg', 0755);
        @chmod(APP_ROOT . '/bin/ffprobe', 0755);
        $s = self::detect();
        if (!$s['ffmpeg']) {
            throw new RuntimeException('ffmpeg extraído mas não executou neste servidor.');
        }
        return 'ok';
    }

    private static function unzip(string $zip, string $dest): void
    {
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($zip) === true) {
                $z->extractTo($dest);
                $z->close();
                return;
            }
        }
        [$code, , $err] = Sys::run(['unzip', '-o', $zip, '-d', $dest], 300);
        if ($code !== 0) {
            throw new RuntimeException('Falha ao descompactar: ' . Sys::lastLines($err));
        }
    }
}
