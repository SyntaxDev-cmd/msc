<?php
declare(strict_types=1);

/** Sinaliza que o download deve ficar para o agente (o YouTube bloqueou o servidor) */
final class AgentHandoff extends RuntimeException
{
}

/**
 * Agente de download: um programinha que roda no PC do administrador (internet residencial, que o
 * YouTube não bloqueia). Ele pede o próximo download ao servidor, baixa com o yt-dlp
 * (https://github.com/yt-dlp/yt-dlp) e envia o arquivo; o servidor converte, organiza e publica.
 */
final class Agent
{
    public static function token(): string
    {
        $t = Settings::get('agent_token');
        if ($t === '') {
            $t = bin2hex(random_bytes(20));
            Settings::set(['agent_token' => $t]);
        }
        return $t;
    }

    public static function regenerate(): string
    {
        Settings::set(['agent_token' => bin2hex(random_bytes(20))]);
        return Settings::get('agent_token');
    }

    public static function check(string $sent): bool
    {
        $t = Settings::get('agent_token');
        return $t !== '' && $sent !== '' && hash_equals($t, $sent);
    }

    /** Já foi usado alguma vez (então vale a pena deixar downloads esperando por ele) */
    public static function configured(): bool
    {
        return (int) Settings::get('agent_seen') > 0;
    }

    /** Versão do script do agente: mudou, o agente no PC se atualiza sozinho */
    public const SCRIPT_VERSION = '3';

    /** Maior pedaço de envio que o PHP desta hospedagem aceita (até 8 MB) */
    public static function chunkSize(): int
    {
        $bytes = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtoupper(substr($v, -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
        };
        $max = min($bytes((string) ini_get('upload_max_filesize')) ?: 2 << 20, $bytes((string) ini_get('post_max_size')) ?: 8 << 20);
        return max(512 << 10, min(8 << 20, $max - (64 << 10)));
    }

    public static function online(): bool
    {
        return (int) Settings::get('agent_seen') > time() - 90;
    }

    private static function seen(): void
    {
        if ((int) Settings::get('agent_seen') < time() - 15) { // grava no máximo a cada 15 s
            Settings::set(['agent_seen' => (string) time()]);
        }
    }

    /** Próximo download para o agente (ou null) */
    public static function next(): ?array
    {
        self::seen();
        // downloads presos com o agente (PC desligou no meio) voltam para a fila depois de 15 min
        Db::exec("UPDATE jobs SET status = 'agent', message = 'Aguardando o agente de download no PC'
                  WHERE status = 'running' AND message LIKE 'Agente:%' AND updated_at < ?", [time() - 1800]);
        for ($i = 0; $i < 5; $i++) {
            $job = Db::one("SELECT * FROM jobs WHERE status IN ('queued','agent') AND source IN ('youtube','itunes') ORDER BY id LIMIT 1");
            if (!$job) {
                return null;
            }
            $id = (int) $job['id'];
            // reserva o pedido (outro agente/worker não pega o mesmo)
            if (Db::exec("UPDATE jobs SET status = 'running', message = 'Agente: preparando', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status IN ('queued','agent')", [time(), $id]) !== 1) {
                continue;
            }
            try {
                $prep = Worker::prepare($job, Worker::progressFn($id));
                if ($prep['existing']) {
                    Jobs::deliver($id, $prep['existing']);
                    Jobs::update($id, ['status' => 'done', 'progress' => 100, 'message' => 'Já estava na biblioteca', 'track_id' => $prep['existing']]);
                    continue;
                }
                $p = json_decode((string) $job['payload'], true) ?: [];
                $p['_prep'] = array_diff_key($prep, ['existing' => 1]);
                Jobs::update($id, ['payload' => json_encode($p, JSON_UNESCAPED_UNICODE), 'progress' => 5, 'message' => 'Agente: baixando no PC']);
                return ['id' => $id, 'video_id' => $prep['youtubeId'], 'kind' => $job['kind'],
                        'title' => $prep['m']['title'], 'artist' => $prep['m']['artist'], 'chunk' => self::chunkSize()];
            } catch (Throwable $e) {
                Jobs::update($id, ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        return null;
    }

    /**
     * Recebe o arquivo baixado pelo agente (em pedaços de 1 MB, para caber em qualquer limite de
     * upload do PHP) e, no último pedaço, publica no acervo. Retorna o track_id (0 = pedaço recebido).
     */
    public static function upload(int $id, array $file, int $part = 0, int $parts = 1): int
    {
        self::seen();
        $job = Db::one("SELECT * FROM jobs WHERE id = ? AND status = 'running' AND message LIKE 'Agente:%'", [$id]);
        if (!$job) {
            throw new DomainException('Pedido não encontrado ou já concluído');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $max = ini_get('upload_max_filesize');
            throw new InvalidArgumentException('Arquivo não recebido' . (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? " (maior que o limite de {$max} do PHP)" : ''));
        }
        $parts = max(1, min(2000, $parts));
        $part = max(0, min($parts - 1, $part));
        $tmp = storage_path('tmp/job_' . $id);
        @mkdir($tmp, 0755, true);
        $raw = $tmp . '/upload.part';
        if ($parts > 1) {
            move_uploaded_file($file['tmp_name'], $tmp . '/chunk_' . str_pad((string) $part, 5, '0', STR_PAD_LEFT));
            Jobs::update($id, ['progress' => round(50 + ($part + 1) / $parts * 45, 1), 'message' => 'Agente: enviando para o servidor']);
            $chunks = glob($tmp . '/chunk_*') ?: [];
            if (count($chunks) < $parts) {
                return 0; // ainda faltam pedaços
            }
            sort($chunks);
            $out = fopen($raw, 'wb');
            foreach ($chunks as $c) {
                fwrite($out, (string) file_get_contents($c));
                @unlink($c);
            }
            fclose($out);
        } else {
            move_uploaded_file($file['tmp_name'], $raw);
        }
        try {
            if (!Mirrors::looksLikeMedia($raw)) {
                throw new InvalidArgumentException('O arquivo enviado não é áudio/vídeo válido');
            }
            $h = (string) file_get_contents($raw, false, null, 0, 12);
            $ext = $job['kind'] === 'video' ? 'mp4' : (substr($h, 4, 4) === 'ftyp' ? 'm4a' : (str_starts_with($h, "\x1A\x45\xDF\xA3") ? 'webm' : (str_starts_with($h, 'OggS') ? 'opus' : 'mp3')));
            rename($raw, $tmp . '/media.' . $ext);
            $p = json_decode((string) $job['payload'], true) ?: [];
            if (empty($p['_prep'])) {
                throw new RuntimeException('Pedido sem dados de preparação');
            }
            // m4a (AAC) toca em todo aparelho e já é leve: publica na hora, sem reconverter
            $trackId = Worker::finalize($job, $p['_prep'], $tmp . '/media.' . $ext, Worker::progressFn($id), $ext !== 'm4a');
            Jobs::deliver($id, $trackId);
            Jobs::update($id, ['status' => 'done', 'progress' => 100, 'message' => 'Pronto para ouvir (baixado pelo agente)', 'track_id' => $trackId]);
            return $trackId;
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    /** Falhou no PC: volta para a fila (até 3 tentativas) antes de virar erro */
    public static function fail(int $id, string $error): void
    {
        self::seen();
        Db::exec("UPDATE jobs SET status = CASE WHEN attempts >= 3 THEN 'error' ELSE 'agent' END,
                  message = CASE WHEN attempts >= 3 THEN ? ELSE 'Tentando de novo pelo agente…' END, progress = 0, updated_at = ?
                  WHERE id = ? AND status = 'running' AND message LIKE 'Agente:%'",
            ['Agente: ' . mb_substr($error, 0, 300), time(), $id]);
    }

    /** Progresso do download no PC (também mostra que o agente segue trabalhando nele) */
    public static function progress(int $id, float $pct): void
    {
        self::seen();
        Db::exec("UPDATE jobs SET progress = ?, message = 'Agente: baixando no PC', updated_at = ? WHERE id = ? AND status = 'running' AND message LIKE 'Agente:%'",
            [round(5 + max(0, min(100, $pct)) * 0.45, 1), time(), $id]);
    }

    /** Programa do agente para Windows (.bat com PowerShell embutido, já configurado) */
    public static function windowsScript(): string
    {
        $server = rtrim(Settings::baseUrl(), '/');
        $token = self::token();
        $name = Settings::get('brand_name') ?: 'Sonora';
        $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Server = '__SERVER__'
$Token = '__TOKEN__'
$Api = "$Server/api.php"
$Dir = Join-Path $env:LOCALAPPDATA 'SonoraAgente'
New-Item -ItemType Directory -Force -Path $Dir | Out-Null
$env:PATH = "$Dir;$env:PATH"
$Ytdlp = Join-Path $Dir 'yt-dlp.exe'
function Log($m) { Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) }

Log 'Agente de download __NAME__'
if (-not (Test-Path $Ytdlp)) {
  Log 'Primeira vez: baixando o yt-dlp (github.com/yt-dlp/yt-dlp)...'
  Invoke-WebRequest 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe' -OutFile $Ytdlp -UseBasicParsing
} else {
  Log 'Atualizando o yt-dlp...'
  try { & $Ytdlp -U 2>&1 | Out-Null } catch {}
}
if (-not (Test-Path (Join-Path $Dir 'deno.exe'))) {
  Log 'Primeira vez: baixando o Deno (o YouTube exige um runtime JavaScript)...'
  $zip = Join-Path $Dir 'deno.zip'
  Invoke-WebRequest 'https://github.com/denoland/deno/releases/latest/download/deno-x86_64-pc-windows-msvc.zip' -OutFile $zip -UseBasicParsing
  Expand-Archive -Path $zip -DestinationPath $Dir -Force
  Remove-Item $zip -Force
}
Log "Conectado a $Server"
Log 'Deixe esta janela aberta: os downloads pedidos no app vao chegar aqui e subir para o servidor.'
$idle = 0
function Send-Chunk($path, $url) {
  for ($t = 1; $t -le 3; $t++) {
    $out = & curl.exe -s -S --retry 3 --connect-timeout 30 -w "`n%{http_code}" -F ("file=@" + $path) $url
    $code = ($out | Select-Object -Last 1)
    if ($code -eq '200') { return }
    $err = (($out | Select-Object -SkipLast 1) -join ' ')
    if ($code -match '^4' -and $code -ne '408' -and $code -ne '429') { throw ("o servidor recusou o envio: " + $err) }
    Log ("Envio falhou ($code), tentando de novo...")
    Start-Sleep -Seconds (3 * $t)
  }
  throw ("o servidor nao recebeu o arquivo: " + $err)
}
while ($true) {
  $job = $null
  try {
    $r = Invoke-RestMethod -Uri "$Api`?action=agent_next&token=$Token" -TimeoutSec 90
    if ($r.v -and $r.v -ne '__VERSION__' -and $env:AGENT_BAT) {
      Log 'Nova versao do agente: atualizando sozinho...'
      Invoke-WebRequest -Uri "$Api`?action=agent_update&token=$Token" -OutFile $env:AGENT_BAT -UseBasicParsing
      Start-Process -FilePath $env:AGENT_BAT
      $parent = (Get-CimInstance Win32_Process -Filter "ProcessId=$PID").ParentProcessId
      Stop-Process -Id $parent -Force -ErrorAction SilentlyContinue
      exit
    }
    $job = $r.job
    if (-not $job) {
      if ($idle++ % 60 -eq 0) { Log 'Aguardando pedidos de download...' }
      Start-Sleep -Seconds 3
      continue
    }
    $idle = 0
    Log ("Baixando: {0} - {1}" -f $job.artist, $job.title)
    $tmp = Join-Path $Dir ("job_" + $job.id)
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    if ($job.kind -eq 'video') { $fmt = 'b[ext=mp4][height<=720]/b[ext=mp4]/b' } else { $fmt = 'bestaudio[ext=m4a]/bestaudio/best' }
    $url = "https://www.youtube.com/watch?v=" + $job.video_id
    $next = Get-Date
    for ($try = 1; $try -le 3; $try++) {
      & $Ytdlp --no-playlist --no-warnings --no-mtime --newline --retries 10 --fragment-retries 10 --extractor-retries 3 --socket-timeout 30 -f $fmt -o (Join-Path $tmp 'media.%(ext)s') $url | ForEach-Object {
        $line = "$_"
        if ($line -match '\[download\]\s+([\d\.]+)%') {
          if ((Get-Date) -ge $next) {
            $next = (Get-Date).AddSeconds(4)
            Write-Host ("  {0}%" -f $Matches[1])
            try { Invoke-RestMethod -Uri "$Api`?action=agent_progress&token=$Token&job_id=$($job.id)&pct=$($Matches[1])" -TimeoutSec 10 | Out-Null } catch {}
          }
        } else { Write-Host $line }
      }
      if ($LASTEXITCODE -eq 0) { break }
      if ($try -eq 3) { throw "yt-dlp falhou (codigo $LASTEXITCODE)" }
      Log "yt-dlp falhou, tentando de novo ($try/3)..."
      Get-ChildItem -Path $tmp -File | Remove-Item -Force -ErrorAction SilentlyContinue
      Start-Sleep -Seconds (4 * $try)
    }
    $file = Get-ChildItem -Path $tmp -File | Where-Object { $_.Extension -notin @('.part', '.ytdl') } | Select-Object -First 1
    if (-not $file) { throw 'o yt-dlp nao gerou o arquivo' }
    Log ("Enviando para o servidor ({0:N1} MB)..." -f ($file.Length / 1MB))
    $size = 1MB
    if ($job.chunk) { $size = [int]$job.chunk }
    $parts = [int][math]::Ceiling($file.Length / $size)
    $fs = [IO.File]::OpenRead($file.FullName)
    try {
      for ($i = 0; $i -lt $parts; $i++) {
        $len = [int][math]::Min($size, $file.Length - ($i * $size))
        $buf = New-Object byte[] $len
        $read = 0
        while ($read -lt $len) { $read += $fs.Read($buf, $read, $len - $read) }
        $chunk = Join-Path $tmp ("chunk" + $i)
        [IO.File]::WriteAllBytes($chunk, $buf)
        Send-Chunk $chunk "$Api`?action=agent_upload&token=$Token&job_id=$($job.id)&part=$i&parts=$parts"
        Remove-Item $chunk -Force
      }
    } finally { $fs.Close() }
    Log 'Pronto! A musica ja esta disponivel no app.'
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
  } catch {
    $msg = $_.Exception.Message
    Log ("Erro: " + $msg + " (o pedido volta para a fila e sera tentado de novo)")
    if ($job) { try { Invoke-RestMethod -Method Post -Uri "$Api`?action=agent_fail&token=$Token&job_id=$($job.id)" -Body @{ error = $msg } | Out-Null } catch {} }
    Start-Sleep -Seconds 5
  }
}
PS;
        $ps = strtr($ps, ['__SERVER__' => $server, '__TOKEN__' => $token, '__VERSION__' => self::SCRIPT_VERSION, '__NAME__' => preg_replace('/[^\w .-]/u', '', $name)]);
        $bat = "@echo off\r\n"
            . "title Agente de download - " . preg_replace('/[^A-Za-z0-9 .-]/', '', $name) . "\r\n"
            . "set \"AGENT_BAT=%~f0\"\r\n"
            . 'powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(Get-Content -LiteralPath \'%~f0\' -Raw -Encoding UTF8) -split \'#####PS#####\'; Invoke-Expression $s[2]"' . "\r\n"
            . "pause\r\n"
            . "exit /b\r\n"
            . "#####PS#####\r\n";
        return $bat . str_replace("\n", "\r\n", $ps);
    }
}
