<?php
declare(strict_types=1);

final class Sys
{
    public static function canExec(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    private static function env(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];
        $env['PATH'] = APP_ROOT . '/bin:' . ($env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin');
        $env['HOME'] = $env['HOME'] ?? storage_path('tmp');
        $env['XDG_CACHE_HOME'] = storage_path('tmp/cache');
        $env['TMPDIR'] = storage_path('tmp');
        $env['PYTHONIOENCODING'] = 'utf-8';
        $env['LC_ALL'] = 'C.UTF-8';
        return $env;
    }

    /**
     * Executa um comando SEM shell (argumentos em array => sem injeção).
     * $onLine recebe cada linha de saída (stdout+stderr) em tempo real.
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    public static function run(array $cmd, int $timeout = 120, ?callable $onLine = null): array
    {
        if (!self::canExec()) {
            throw new RuntimeException('proc_open está desativado neste servidor.');
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, self::env());
        if (!is_resource($proc)) {
            throw new RuntimeException('Falha ao iniciar ' . basename((string) $cmd[0]));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $buf = ['', ''];
        $start = time();
        $exit = -1;
        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], fn($p) => is_resource($p) && !feof($p));
            if ($read) {
                $w = $e = null;
                @stream_select($read, $w, $e, 1);
                foreach ($read as $p) {
                    $chunk = (string) fread($p, 65536);
                    if ($chunk === '') {
                        continue;
                    }
                    $i = $p === $pipes[1] ? 0 : 1;
                    if ($i === 0) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                    if ($onLine) {
                        $buf[$i] .= $chunk;
                        $lines = preg_split('/\r\n|\r|\n/', $buf[$i]);
                        $buf[$i] = (string) array_pop($lines);
                        foreach ($lines as $line) {
                            if ($line !== '') {
                                $onLine($line);
                            }
                        }
                    }
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException('Tempo esgotado executando ' . basename((string) $cmd[0]));
            }
            if (!$read) {
                usleep(100000);
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [$exit, $out, $err];
    }

    public static function lastLines(string $text, int $n = 3): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: [])));
        return implode(' | ', array_slice($lines, -$n));
    }
}
