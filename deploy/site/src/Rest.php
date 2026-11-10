<?php
/**
 * supabase-lite — PostgREST-compatible query layer.
 * Parses the PostgREST wire format that @supabase/postgrest-js emits.
 */

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';

class Rest
{
    /** Tables that carry a user_id owner column (RLS-style scoping). */
    public const OWNER_COL = [
        'posts' => 'user_id',
        'social_accounts' => 'user_id',
        'ad_campaigns' => 'user_id',
        'ad_events' => 'user_id',
        'payments' => 'user_id',
        'billing_entries' => 'user_id',
        'tickets' => 'user_id',
        'analytics' => 'user_id',
        'user_blocks' => 'user_id',
        'auto_tasks' => 'user_id',
        'profiles' => 'id',
    ];

    public static function error(int $status, string $message, string $code = '', string $details = '', string $hint = ''): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'message' => $message,
            'code' => $code,
            'details' => $details,
            'hint' => $hint,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Does the table have a column with this name? */
    private static function tableHasColumn(string $table, string $col): bool
    {
        static $cache = [];
        $key = $table . '.' . $col;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $st = Db::pdo()->prepare("PRAGMA table_info(`{$table}`)");
            $st->execute();
            foreach ($st->fetchAll() as $info) {
                if (($info['name'] ?? '') === $col) return $cache[$key] = true;
            }
        } catch (Throwable $e) { /* unknown table */ }
        return $cache[$key] = false;
    }

    /** Primary key column name of a table (single-column PKs only). */
    private static function tablePk(string $table): string
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $st = Db::pdo()->prepare("PRAGMA table_info(`{$table}`)");
            $st->execute();
            foreach ($st->fetchAll() as $info) {
                if (!empty($info['pk'])) return $cache[$table] = (string)$info['name'];
            }
        } catch (Throwable $e) { /* unknown table */ }
        return $cache[$table] = 'id';
    }

    /** Split "col=eq.val" style query params into filter tuples. */
    private static function parseFilters(array $query, array &$rest): array
    {
        $filters = [];
        $known = ['select', 'order', 'limit', 'offset', 'on_conflict', 'columns'];
        foreach ($query as $key => $value) {
            if (in_array($key, $known, true)) { $rest[$key] = $value; continue; }
            $filters[] = ['col' => $key, 'raw' => $value];
        }
        return $filters;
    }

    /** Build WHERE clause from PostgREST filter syntax. */
    private static function buildWhere(string $table, array $filters, ?string $ownerCol, ?string $uid, ?string $id): array
    {
        $where = [];
        $params = [];

        // RLS-style owner scoping (admin bypasses)
        if ($ownerCol && $uid !== null && !Auth::isAdmin()) {
            $where[] = "`{$ownerCol}` = ?";
            $params[] = $uid;
        }

        foreach ($filters as $f) {
            $col = $f['col'];
            $val = $f['raw'];
            // PostgREST: not.is.null / is.null / eq.x / gte.x / in.(a,b)
            if (preg_match('/^(not\.)?is\.(not\.)?null$/', $val, $m)) {
                $negate = !empty($m[1]) || !empty($m[3]);
                $where[] = "`{$col}` IS " . ($negate ? 'NOT ' : '') . 'NULL';
                continue;
            }
            if (strpos($val, 'not.') === 0) {
                $val = substr($val, 4);
                $neg = true;
            } else {
                $neg = false;
            }
            if (strpos($val, 'in.(') === 0 && substr($val, -1) === ')') {
                $list = substr($val, 4, -1);
                $parts = $list === '' ? [] : explode(',', $list);
                if (!$parts) continue;
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $where[] = "`{$col}` " . ($neg ? 'NOT ' : '') . "IN ($ph)";
                foreach ($parts as $p) $params[] = trim($p, '"');
                continue;
            }
            $dot = strpos($val, '.');
            if ($dot === false) { $op = 'eq'; $operand = $val; }
            else { $op = substr($val, 0, $dot); $operand = substr($val, $dot + 1); }
            if ($op === 'like' || $op === 'ilike') {
                $where[] = "`{$col}` LIKE ?";
                $params[] = str_replace(['%', '_'], ['\\%', '\\_'], strtr($operand, ['*' => '%', '%%' => '%']));
            } else {
                $map = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'like' => 'LIKE', 'ilike' => 'LIKE'];
                $sqlOp = $map[$op] ?? '=';
                $where[] = "`{$col}` {$sqlOp} ?";
                $params[] = $operand;
            }
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public static function handle(string $table, string $method, array $query, $body): void
    {
        $pdo = Db::pdo();
        $uid = Auth::currentUserId();
        $admin = Auth::isAdmin();
        $single = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/vnd.pgrst.object+json') !== false);

        // ── RPC ──
        if ($table === 'rpc') {
            self::handleRpc($method, $body);
            return;
        }

        $ownerCols = self::OWNER_COL[$table] ?? null;

        if ($method === 'GET' || $method === 'HEAD') {
            $rest = [];
            $filters = self::parseFilters($query, $rest);
            [$where, $params] = self::buildWhere($table, $filters, $ownerCols, $uid, null);

            // count=exact + HEAD → Content-Range with total
            $prefer = $_SERVER['HTTP_PREFER'] ?? '';
            if (stripos($prefer, 'count=exact') !== false && $method === 'HEAD') {
                $st = $pdo->prepare("SELECT COUNT(*) c FROM `{$table}` {$where}");
                $st->execute($params);
                $c = (int)$st->fetch()['c'];
                header("Content-Range: 0-0/{$c}");
                http_response_code(200);
                return;
            }

            $sel = $rest['select'] ?? '*';
            if ($sel === '*') $sel = '*';
            $order = '';
            if (!empty($rest['order'])) {
                $parts = [];
                foreach (explode(',', $rest['order']) as $o) {
                    $bits = explode('.', $o);
                    $col = $bits[0];
                    $dir = strtoupper($bits[1] ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';
                    $parts[] = "`{$col}` {$dir}";
                }
                $order = 'ORDER BY ' . implode(', ', $parts);
            }
            $lim = isset($rest['limit']) ? ' LIMIT ' . (int)$rest['limit'] : '';
            $off = isset($rest['offset']) ? ' OFFSET ' . (int)$rest['offset'] : '';

            $st = $pdo->prepare("SELECT {$sel} FROM `{$table}` {$where} {$order}{$lim}{$off}");
            $st->execute($params);
            $rows = array_map(fn($r) => Db::decodeRow($table, $r), $st->fetchAll());

            header('Content-Type: application/json');
            if ($single) {
                if (count($rows) === 1) { echo json_encode($rows[0], JSON_UNESCAPED_UNICODE); }
                elseif (count($rows) === 0) { self::error(406, 'JSON object requested, multiple (or no) rows returned', 'PGRST116'); }
                else { self::error(406, 'JSON object requested, multiple (or no) rows returned', 'PGRST116'); }
                return;
            }
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($method === 'POST') {
            $prefer = $_SERVER['HTTP_PREFER'] ?? '';
            $upsert = (stripos($prefer, 'merge-duplicates') !== false) || isset($query['on_conflict']);
            $returnRep = (stripos($prefer, 'return=representation') !== false) || $single;

            $rows = is_array($body) && array_is_list($body) ? $body : [$body];
            if (!$rows) { self::error(400, 'empty body'); return; }

            $out = [];
            foreach ($rows as $row) {
                $row = (array)$row;
                // Generate id only for tables whose PK column is `id`
                $hasIdCol = self::tableHasColumn($table, 'id');
                if ($hasIdCol && empty($row['id'])) $row['id'] = Db::uuid();
                $enc = Db::encodeRow($table, $row);
                $cols = array_keys($enc);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $colList = implode(',', array_map(fn($c) => "`{$c}`", $cols));

                if ($upsert) {
                    // ON CONFLICT upsert. on_conflict=col1,col2 → unique index target
                    $conflict = $query['on_conflict'] ?? 'id';
                    $conflictCols = implode(',', array_map(fn($c) => "`{$c}`", explode(',', $conflict)));
                    $updates = array_filter($cols, fn($c) => $c !== 'id' && !in_array($c, explode(',', $conflict), true));
                    if ($updates) {
                        $setSql = implode(', ', array_map(fn($c) => "`{$c}`=excluded.`{$c}`", $updates));
                        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$ph}) ON CONFLICT({$conflictCols}) DO UPDATE SET {$setSql}";
                    } else {
                        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$ph}) ON CONFLICT({$conflictCols}) DO NOTHING";
                    }
                    $pdo->prepare($sql)->execute(array_values($enc));
                } else {
                    $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$ph})";
                    try {
                        $pdo->prepare($sql)->execute(array_values($enc));
                    } catch (PDOException $e) {
                        self::error(409, $e->getMessage(), '23505');
                        return;
                    }
                }

                if ($returnRep) {
                    // Re-read the row by its primary key
                    $pkCol = $hasIdCol ? 'id' : (self::tablePk($table) ?: 'id');
                    $pkVal = $enc[$pkCol] ?? ($row[$pkCol] ?? null);
                    if ($pkVal !== null) {
                        $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$pkCol}` = ?");
                        $st->execute([$pkVal]);
                        $out[] = Db::decodeRow($table, $st->fetch() ?: []);
                    }
                }
            }

            http_response_code($returnRep ? 201 : 201);
            if ($returnRep) {
                header('Content-Type: application/json');
                echo json_encode($single ? ($out[0] ?? null) : $out, JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        if ($method === 'PATCH') {
            $rest = [];
            $filters = self::parseFilters($query, $rest);
            [$where, $params] = self::buildWhere($table, $filters, $ownerCols, $uid, null);
            $row = Db::encodeRow($table, (array)$body);
            $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($row)));
            $st = $pdo->prepare("UPDATE `{$table}` SET {$sets} {$where}");
            $st->execute([...array_values($row), ...$params]);

            $prefer = $_SERVER['HTTP_PREFER'] ?? '';
            $returnRep = (stripos($prefer, 'return=representation') !== false) || $single;
            if ($returnRep) {
                $rest2 = [];
                $f2 = self::parseFilters($query, $rest2);
                [$w2, $p2] = self::buildWhere($table, $f2, $ownerCols, $uid, null);
                $st = $pdo->prepare("SELECT * FROM `{$table}` {$w2}");
                $st->execute($p2);
                $rows = array_map(fn($r) => Db::decodeRow($table, $r), $st->fetchAll());
                header('Content-Type: application/json');
                echo json_encode($single ? ($rows[0] ?? null) : $rows, JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(204);
            }
            return;
        }

        if ($method === 'DELETE') {
            $rest = [];
            $filters = self::parseFilters($query, $rest);
            [$where, $params] = self::buildWhere($table, $filters, $ownerCols, $uid, null);
            $st = $pdo->prepare("DELETE FROM `{$table}` {$where}");
            $st->execute($params);
            http_response_code(204);
            return;
        }

        self::error(405, "method {$method} not allowed");
    }

    private static function handleRpc(string $method, $body): void
    {
        // POST /rest/v1/rpc/{fn} routed by path below; this is a fallback.
        self::error(404, 'rpc function not found');
    }

    /** RPC implementations used by the frontend. */
    public static function rpc(string $fn, array $args): void
    {
        $pdo = Db::pdo();
        header('Content-Type: application/json');
        if ($fn === 'use_promo_code') {
            $code = strtoupper((string)($args['code_text'] ?? ''));
            $uid = (string)($args['user_id'] ?? Auth::currentUserId() ?? '');
            $st = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? AND active = 1");
            $st->execute([$code]);
            $row = $st->fetch();
            if (!$row) { echo json_encode(false); return; }
            if ($row['expires_at'] && strtotime($row['expires_at']) < time()) { echo json_encode(false); return; }
            if ((int)$row['max_uses'] > 0 && (int)$row['used_count'] >= (int)$row['max_uses']) { echo json_encode(false); return; }
            $pdo->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE code = ?")->execute([$code]);
            echo json_encode(true);
            return;
        }
        if ($fn === 'expire_subscriptions') {
            $now = Db::now();
            $pdo->exec("UPDATE profiles SET subscription = 'free', pending_subscription = NULL, updated_at = '{$now}' WHERE pending_subscription IS NOT NULL AND free_trial_end IS NOT NULL AND free_trial_end < '{$now}'");
            $n = $pdo->exec("UPDATE profiles SET subscription = 'free', updated_at = '{$now}' WHERE subscription <> 'free' AND pending_subscription IS NULL AND free_trial_end IS NOT NULL AND free_trial_end < '{$now}'");
            echo json_encode((int)$n);
            return;
        }
        self::error(404, "function {$fn} does not exist");
    }
}
