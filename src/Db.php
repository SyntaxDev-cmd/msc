<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $pdo = new PDO('sqlite:' . storage_path('data/sonora.sqlite'), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=15000; PRAGMA foreign_keys=ON;');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS tracks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_id TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'audio',
    dedup_key TEXT NOT NULL,
    title TEXT NOT NULL,
    artist TEXT NOT NULL,
    album TEXT DEFAULT '',
    genre TEXT DEFAULT '',
    year TEXT DEFAULT '',
    duration INTEGER DEFAULT 0,
    file_path TEXT NOT NULL,
    mime TEXT DEFAULT '',
    size INTEGER DEFAULT 0,
    cover_path TEXT DEFAULT '',
    youtube_id TEXT DEFAULT '',
    lyrics_synced TEXT,
    lyrics_plain TEXT,
    lyrics_checked INTEGER DEFAULT 0,
    plays INTEGER DEFAULT 0,
    favorite INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_played INTEGER DEFAULT 0,
    UNIQUE(source, source_id, kind)
);
CREATE INDEX IF NOT EXISTS idx_tracks_dedup ON tracks(dedup_key, kind);
CREATE INDEX IF NOT EXISTS idx_tracks_yt ON tracks(youtube_id);
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    dedup_key TEXT NOT NULL,
    title TEXT DEFAULT '',
    artist TEXT DEFAULT '',
    thumb TEXT DEFAULT '',
    payload TEXT,
    status TEXT NOT NULL DEFAULT 'queued',
    progress REAL DEFAULT 0,
    message TEXT DEFAULT '',
    track_id INTEGER,
    attempts INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
CREATE TABLE IF NOT EXISTS cache (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL,
    expires INTEGER NOT NULL
);
SQL);
        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    /** Migrações versionadas (PRAGMA user_version) — atualiza bancos antigos sem perder dados */
    private static function migrate(PDO $pdo): void
    {
        $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) {
            $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER,
    path TEXT NOT NULL DEFAULT '/',
    role TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    name TEXT DEFAULT '',
    email TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    expires_at INTEGER,
    plan_id INTEGER,
    credits INTEGER NOT NULL DEFAULT 0,
    max_users INTEGER NOT NULL DEFAULT 0,
    max_resellers INTEGER NOT NULL DEFAULT 0,
    dl_per_day INTEGER NOT NULL DEFAULT 50,
    max_tracks INTEGER NOT NULL DEFAULT 2000,
    allow_video INTEGER NOT NULL DEFAULT 1,
    allow_offline INTEGER NOT NULL DEFAULT 1,
    can_brand INTEGER NOT NULL DEFAULT 1,
    is_trial INTEGER NOT NULL DEFAULT 0,
    settings TEXT NOT NULL DEFAULT '{}',
    notes TEXT DEFAULT '',
    created_at INTEGER NOT NULL,
    last_login INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_acc_path ON accounts(path);
CREATE INDEX IF NOT EXISTS idx_acc_parent ON accounts(parent_id);
CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    days INTEGER NOT NULL DEFAULT 30,
    price REAL NOT NULL DEFAULT 0,
    credits INTEGER NOT NULL DEFAULT 1,
    dl_per_day INTEGER NOT NULL DEFAULT 50,
    max_tracks INTEGER NOT NULL DEFAULT 2000,
    allow_video INTEGER NOT NULL DEFAULT 1,
    allow_offline INTEGER NOT NULL DEFAULT 1,
    highlight INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS user_tracks (
    user_id INTEGER NOT NULL,
    track_id INTEGER NOT NULL,
    added_at INTEGER NOT NULL,
    favorite INTEGER NOT NULL DEFAULT 0,
    plays INTEGER NOT NULL DEFAULT 0,
    last_played INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, track_id)
);
CREATE INDEX IF NOT EXISTS idx_ut_track ON user_tracks(track_id);
CREATE TABLE IF NOT EXISTS job_users (
    job_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    PRIMARY KEY (job_id, user_id)
);
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    receiver_id INTEGER NOT NULL DEFAULT 0,
    kind TEXT NOT NULL,
    plan_id INTEGER,
    qty INTEGER NOT NULL DEFAULT 0,
    amount REAL NOT NULL,
    method TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    mp_id TEXT DEFAULT '',
    mp_status TEXT DEFAULT '',
    qr_code TEXT DEFAULT '',
    qr_base64 TEXT DEFAULT '',
    init_point TEXT DEFAULT '',
    note TEXT DEFAULT '',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    approved_at INTEGER DEFAULT 0,
    checked_at INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_pay_acc ON payments(account_id);
CREATE INDEX IF NOT EXISTS idx_pay_status ON payments(status);
CREATE TABLE IF NOT EXISTS credit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_id INTEGER NOT NULL DEFAULT 0,
    to_id INTEGER NOT NULL DEFAULT 0,
    amount INTEGER NOT NULL,
    reason TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT
);
CREATE TABLE IF NOT EXISTS audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER NOT NULL DEFAULT 0,
    action TEXT NOT NULL,
    target_id INTEGER DEFAULT 0,
    info TEXT DEFAULT '',
    ip TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit(actor_id);
CREATE TABLE IF NOT EXISTS throttle (
    k TEXT NOT NULL,
    at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_throttle ON throttle(k, at);
INSERT INTO plans (name, days, price, credits, dl_per_day, max_tracks, allow_video, allow_offline, highlight, sort) VALUES
    ('Mensal', 30, 19.90, 1, 50, 2000, 1, 1, 0, 1),
    ('Trimestral', 90, 49.90, 3, 80, 5000, 1, 1, 1, 2),
    ('Anual', 365, 179.90, 12, 150, 20000, 1, 1, 0, 3);
SQL);
            $cols = array_column($pdo->query('PRAGMA table_info(jobs)')->fetchAll(), 'name');
            if (!in_array('user_id', $cols, true)) {
                $pdo->exec('ALTER TABLE jobs ADD COLUMN user_id INTEGER');
            }
            // instalação antiga (senha única): vira o administrador "admin"
            $old = storage_path('data/auth.json');
            if (is_file($old)) {
                $hash = (string) (json_decode((string) file_get_contents($old), true)['hash'] ?? '');
                if ($hash !== '') {
                    $pdo->prepare("INSERT OR IGNORE INTO accounts (role, username, password_hash, name, created_at, path) VALUES ('admin', 'admin', ?, 'Administrador', ?, '/')")
                        ->execute([$hash, time()]);
                }
                @rename($old, $old . '.migrated');
            }
            $pdo->exec('PRAGMA user_version = 1');
        }
    }

    private static int $txDepth = 0;

    /** Transação (BEGIN IMMEDIATE trava a escrita: evita gastar o mesmo crédito duas vezes) */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if (self::$txDepth > 0) {
            return $fn();
        }
        $pdo->exec('BEGIN IMMEDIATE');
        self::$txDepth++;
        try {
            $r = $fn();
            $pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            self::$txDepth--;
        }
    }

    public static function all(string $sql, array $args = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function exec(string $sql, array $args = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols))
        );
        self::pdo()->prepare($sql)->execute($row);
        return (int) self::pdo()->lastInsertId();
    }
}
