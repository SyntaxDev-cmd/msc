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
        return self::$pdo = $pdo;
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
