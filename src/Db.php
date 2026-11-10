<?php
/**
 * supabase-lite — SQLite storage with PostgREST-compatible type mapping.
 * JSON/array columns stored as TEXT, booleans as 0/1; converted on the wire
 * so @supabase/supabase-js sees the same shapes as real PostgREST.
 */

class Db
{
    private static ?PDO $pdo = null;

    /** JSON-encoded TEXT columns per table */
    public const JSON_COLS = [
        'posts'           => ['social_networks', 'scheduled_dates'],
        'social_accounts' => ['config'],
        'ad_campaigns'    => [],
        'payments'        => ['metadata'],
        'auto_tasks'      => ['schedule', 'scheduled_dates', 'networks', 'topics'],
        'site_settings'   => ['value'],
        'analytics'       => [],
    ];

    /** Integer-boolean columns per table */
    public const BOOL_COLS = [
        'posts'           => ['has_audio', 'has_video', 'has_image'],
        'social_accounts' => ['connected'],
        'user_blocks'     => ['active'],
        'auto_tasks'      => ['active'],
        'promo_codes'     => ['active'],
    ];

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $path = __DIR__ . '/../data/db.sqlite';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        self::$pdo = new PDO('sqlite:' . $path);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::initSchema();
        return self::$pdo;
    }

    private static function initSchema(): void
    {
        $sql = file_get_contents(__DIR__ . '/../schema.sql');
        self::$pdo->exec($sql);
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Encode JS value → SQLite storage value for a given table.column */
    public static function encode(string $table, string $col, $val)
    {
        if ($val === null) return null;
        $json = self::JSON_COLS[$table] ?? [];
        if (in_array($col, $json, true)) {
            return is_string($val) ? $val : json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        $bool = self::BOOL_COLS[$table] ?? [];
        if (in_array($col, $bool, true)) {
            return $val ? 1 : 0;
        }
        return $val;
    }

    /** Decode SQLite value → JS wire value */
    public static function decode(string $table, string $col, $val)
    {
        if ($val === null) return null;
        $json = self::JSON_COLS[$table] ?? [];
        if (in_array($col, $json, true)) {
            $d = json_decode((string)$val, true);
            return $d === null && $val !== 'null' ? [] : $d;
        }
        $bool = self::BOOL_COLS[$table] ?? [];
        if (in_array($col, $bool, true)) {
            return (bool)$val;
        }
        return $val;
    }

    /** Decode a whole row for the wire */
    public static function decodeRow(string $table, array $row): array
    {
        foreach ($row as $col => $val) {
            $row[$col] = self::decode($table, $col, $val);
        }
        return $row;
    }

    /** Encode a whole JS row for storage */
    public static function encodeRow(string $table, array $row): array
    {
        foreach ($row as $col => $val) {
            $row[$col] = self::encode($table, $col, $val);
        }
        return $row;
    }
}
