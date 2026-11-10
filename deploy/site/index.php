<?php
/**
 * supabase-lite — single entry point.
 * Routes:
 *   /auth/v1/*      GoTrue-compatible auth
 *   /rest/v1/*      PostgREST-compatible data API (+ /rest/v1/rpc/*)
 *   /functions/v1/* edge functions (ai-generate-*, social-*, payment, cron)
 *   /api/*          Vite-middleware replacements (AI proxy, telegram, social)
 *   anything else   static files (the built blogpost SPA lives in ../site or ./)
 */

// Works both as public/index.php (local dev) and flat index.php (shared hosting deploy):
// src/ and schema.sql sit next to index.php in the flat layout, one level up in the dev layout.
$SLITE_ROOT = is_dir(__DIR__ . '/src') ? __DIR__ : dirname(__DIR__);

require_once $SLITE_ROOT . '/src/Db.php';
require_once $SLITE_ROOT . '/src/Jwt.php';
require_once $SLITE_ROOT . '/src/Auth.php';
require_once $SLITE_ROOT . '/src/Rest.php';
require_once $SLITE_ROOT . '/src/Functions.php';
require_once $SLITE_ROOT . '/src/ApiProxy.php';

// ── CORS (frontend may be on another origin during dev) ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, PUT, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Authorization, apikey, X-Client-Info, X-Supabase-Api-Version, Prefer, Accept, Range, X-Telegram-Bot-Token, X-Title');
header('Access-Control-Expose-Headers: Content-Range, Range, Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = rawurldecode($uri);

$body = null;
$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') {
    $json = json_decode($raw, true);
    $body = ($json !== null) ? $json : $raw;
} else {
    $body = [];
}

// ── Auth (GoTrue) ──
if (preg_match('#^/auth/v1/(.+)$#', $uri, $m)) {
    $sub = $m[1];
    $q = $_GET;
    if ($sub === 'signup' && $method === 'POST') {
        $r = Auth::signup((array)$body);
    } elseif ($sub === 'token' && $method === 'POST') {
        $grant = $q['grant_type'] ?? ($body['grant_type'] ?? 'password');
        $r = ($grant === 'refresh_token') ? Auth::refresh((array)$body) : Auth::login((array)$body);
    } elseif ($sub === 'logout' && $method === 'POST') {
        Auth::logout();
        http_response_code(204);
        exit;
    } elseif ($sub === 'user') {
        $u = Auth::getUser();
        if (!$u) { Rest::error(401, 'invalid token', 'bad_jwt'); exit; }
        $r = ['status' => 200, 'json' => $u];
    } elseif ($sub === 'recover' && $method === 'POST') {
        $r = Auth::recover((array)$body);
    } elseif ($sub === 'verify' && $method === 'POST') {
        $r = Auth::verifyOtp((array)$body);
    } elseif ($sub === 'authorize') {
        // OAuth entry: redirect to the social-auth edge function
        $provider = $q['provider'] ?? '';
        $redirect = $q['redirect_to'] ?? ($q['redirect'] ?? '/');
        header('Location: /functions/v1/social-auth?provider=' . urlencode($provider) . '&redirect=' . urlencode($redirect));
        exit;
    } elseif ($sub === 'health') {
        $r = ['status' => 200, 'json' => ['ok' => true]];
    } elseif ($sub === 'settings') {
        $r = ['status' => 200, 'json' => []];
    } else {
        $r = ['status' => 404, 'json' => ['msg' => "auth endpoint {$sub} not found"]];
    }
    http_response_code($r['status']);
    header('Content-Type: application/json');
    echo json_encode($r['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── REST (PostgREST) ──
if (preg_match('#^/rest/v1/rpc/([^/?]+)#', $uri, $m)) {
    Rest::rpc($m[1], (array)$body);
    exit;
}
if (preg_match('#^/rest/v1/([^/?]+)#', $uri, $m)) {
    Rest::handle($m[1], $method, $_GET, $body);
    exit;
}

// ── Edge functions ──
if (preg_match('#^/functions/v1/([^/?]+)#', $uri, $m)) {
    Functions::handle($m[1], $method, $_GET, $body);
    exit;
}

// ── Vite middleware replacements ──
if (preg_match('#^/api(/|$)#', $uri)) {
    ApiProxy::handle($uri, $method, $_GET, $body, $raw);
    exit;
}

// ── Static SPA ──
$docroot = __DIR__ ;
$file = $docroot . $uri;
if ($uri !== '/' && is_file($file) && !str_contains($file, '..')) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['html' => 'text/html; charset=utf-8', 'js' => 'application/javascript', 'css' => 'text/css',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'json' => 'application/json',
        'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml', 'woff2' => 'font/woff2', 'map' => 'application/json'];
    if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
    readfile($file);
    exit;
}
// SPA fallback
if (is_file($docroot . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($docroot . '/index.html');
    exit;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "supabase-lite running. Deploy the blogpost build into public/ to serve the SPA.";
