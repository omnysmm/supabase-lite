<?php
// Deployment probe — checks PHP + SQLite support on the hosting.
header('Content-Type: application/json; charset=utf-8');
$out = [
    'php_version' => PHP_VERSION,
    'pdo' => extension_loaded('pdo'),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'sqlite3' => extension_loaded('sqlite3'),
    'curl' => extension_loaded('curl'),
    'json' => extension_loaded('json'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
    'writable_dir' => is_writable(__DIR__),
    'sapi' => PHP_SAPI,
];
if (extension_loaded('pdo_sqlite')) {
    try {
        $dbPath = __DIR__ . '/probe.sqlite';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('ok')");
        $v = $pdo->query('SELECT COUNT(*) c FROM t')->fetch();
        $out['sqlite_write'] = true;
        $out['sqlite_rows'] = (int)$v['c'];
        @unlink($dbPath);
    } catch (Throwable $e) {
        $out['sqlite_write'] = false;
        $out['sqlite_error'] = $e->getMessage();
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
