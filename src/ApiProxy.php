<?php
/**
 * supabase-lite — /api/* proxies (replaces the Vite dev middleware for production).
 * AI text/image, Telegram Bot API, VK, YouTube, TikTok, Instagram, OK, Rutube.
 */

class ApiProxy
{
    public static function handle(string $uri, string $method, array $query, $body, string $raw): void
    {
        $body = is_array($body) ? $body : [];
        // normalize: /api/telegram/sendPhoto → telegram/sendPhoto
        $path = trim(preg_replace('#^/api/?#', '', $uri), '/');

        if ($path === 'generate-health') { self::text('ok'); return; }
        if ($path === 'generate') { self::generate($body); return; }
        if ($path === 'image') { self::image($query); return; }
        if (str_starts_with($path, 'llm/') || str_starts_with($path, 'img/')) { self::pollinationsProxy($path, $raw); return; }
        if (str_starts_with($path, 'telegram/')) { self::telegram(substr($path, 9), $body); return; }
        if ($path === 'vk/method') { self::vkMethod($body); return; }
        if ($path === 'vk/upload-photo') { self::vkUpload($body); return; }
        if ($path === 'youtube/upload') { self::youtubeUpload($body); return; }
        if ($path === 'youtube/channels') { self::youtubeChannels($body); return; }
        if ($path === 'tiktok/user') { self::tiktokUser($body); return; }
        if ($path === 'tiktok/publish') { self::tiktokPublish($body); return; }
        if ($path === 'instagram/graph') { self::instagramGraph($query, $body, $method); return; }
        if ($path === 'ok/fb') { self::okFb($body); return; }
        if ($path === 'ok/upload-photo') { self::okUpload($body); return; }
        if ($path === 'rutube/me') { self::rutubeMe($body); return; }
        if ($path === 'rutube/from-url') { self::rutubeFromUrl($body); return; }
        if ($path === 'rutube/upload') { self::rutubeUpload($body); return; }

        self::json(['error' => "unknown api route {$path}"], 404);
    }

    // ── helpers ──────────────────────────────────────────────

    private static function json($obj, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function text(string $t): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo $t;
    }

    private static function raw(string $body, string $contentType, int $status): void
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        echo $body;
    }

    private static function http(string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $opts['timeout'] ?? 60,
            CURLOPT_FOLLOWLOCATION => !($opts['no_follow'] ?? false),
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HEADER => true,
        ]);
        if (!empty($opts['method'])) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method']);
        if (isset($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);
        $headers = substr((string)$resp, 0, $headerSize);
        $body = substr((string)$resp, $headerSize);
        // Extract last Location header if present
        $location = '';
        if (preg_match_all('/^Location:\s*(.+)$/mi', $headers, $mm)) {
            $location = trim(end($mm[1]));
        }
        return ['status' => $status, 'body' => $body, 'error' => $err, 'ctype' => $ctype, 'location' => $location];
    }

    /** multipart/form-data builder */
    private static function multipart(array $fields, ?string $fileField, ?string $fileBuf, ?string $mime, ?string $filename): array
    {
        $boundary = '----BlogPostForm' . bin2hex(random_bytes(6));
        $out = '';
        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') continue;
            $out .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        if ($fileField !== null && $fileBuf !== null) {
            $out .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$fileField}\"; filename=\"" . ($filename ?: 'file.bin') . "\"\r\nContent-Type: " . ($mime ?: 'application/octet-stream') . "\r\n\r\n";
            $out .= $fileBuf . "\r\n";
        }
        $out .= "--{$boundary}--\r\n";
        return ['body' => $out, 'content_type' => "multipart/form-data; boundary={$boundary}"];
    }

    private static function b64(string $b64): string
    {
        return (string)base64_decode(preg_replace('/\s+/', '', $b64) ?? '');
    }

    // ── AI ───────────────────────────────────────────────────

    private static function generate(array $body): void
    {
        require_once __DIR__ . '/Functions.php';
        Functions::handle('ai-generate-text', 'POST', [], $body);
    }

    private static function image(array $query): void
    {
        require_once __DIR__ . '/Functions.php';
        Functions::handle('ai-generate-image', 'POST', [], ['prompt' => $query['prompt'] ?? 'beautiful photo']);
    }

    private static function pollinationsProxy(string $path, string $raw): void
    {
        $target = str_starts_with($path, 'llm/') ? 'https://text.pollinations.ai/' . substr($path, 4) : 'https://image.pollinations.ai/' . substr($path, 4);
        $r = self::http($target, ['body' => $raw ?: null, 'headers' => ['Content-Type: application/json'], 'timeout' => 60]);
        self::raw($r['body'], $r['ctype'] ?: 'application/json', $r['status']);
    }

    // ── Telegram ─────────────────────────────────────────────

    public static function telegram(string $tgMethod, array $body): void
    {
        $token = $_SERVER['HTTP_X_TELEGRAM_BOT_TOKEN'] ?? ($body['token'] ?? '');
        if (!$token) { self::json(['ok' => false, 'description' => 'missing bot token'], 400); return; }
        $tgMethod = $tgMethod ?: 'sendMessage';

        $photo = (string)($body['photoBase64'] ?? '');
        $photoMime = (string)($body['photoMime'] ?? 'image/jpeg');
        if ($photo === '' && !empty($body['photo']) && is_string($body['photo']) && str_starts_with($body['photo'], 'data:')) {
            [$meta, $payload] = explode(',', $body['photo'], 2);
            $photoMime = explode(';', substr($meta, 5))[0] ?: 'image/jpeg';
            $photo = $payload;
        }

        if ($photo !== '') {
            $mp = self::multipart([
                'chat_id' => (string)($body['chat_id'] ?? $body['chatId'] ?? ''),
                'caption' => (string)($body['caption'] ?? ''),
                'parse_mode' => (string)($body['parse_mode'] ?? 'HTML'),
            ], 'photo', self::b64($photo), $photoMime, (string)($body['photoName'] ?? 'image.jpg'));
            $r = self::http("https://api.telegram.org/bot{$token}/{$tgMethod}", [
                'method' => 'POST', 'headers' => ['Content-Type: ' . $mp['content_type']], 'body' => $mp['body'], 'timeout' => 120,
            ]);
            self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
            return;
        }

        $r = self::http("https://api.telegram.org/bot{$token}/{$tgMethod}", [
            'method' => 'POST', 'headers' => ['Content-Type: application/json'], 'body' => json_encode($body), 'timeout' => 120,
        ]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    // ── VK ───────────────────────────────────────────────────

    private static function vkMethod(array $body): void
    {
        $method = (string)($body['method'] ?? 'wall.post');
        $params = (array)($body['params'] ?? []);
        $r = self::http("https://api.vk.com/method/{$method}", [
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query(array_filter($params, fn($v) => $v !== null && $v !== '')),
            'timeout' => 60,
        ]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status'] ?: 502);
    }

    private static function vkUpload(array $body): void
    {
        $url = (string)($body['uploadUrl'] ?? '');
        if (!$url) { self::json(['error' => 'missing uploadUrl'], 400); return; }
        $mp = self::multipart([], 'photo', self::b64((string)($body['photoBase64'] ?? '')), $body['photoMime'] ?? 'image/jpeg', $body['photoName'] ?? 'image.jpg');
        $r = self::http($url, ['method' => 'POST', 'headers' => ['Content-Type: ' . $mp['content_type']], 'body' => $mp['body'], 'timeout' => 120]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    // ── YouTube ──────────────────────────────────────────────

    private static function youtubeUpload(array $body): void
    {
        $token = (string)($body['accessToken'] ?? '');
        if (!$token) { self::json(['error' => 'missing accessToken'], 400); return; }
        $video = self::b64((string)($body['videoBase64'] ?? ''));
        if ($video === '') { self::json(['error' => 'empty video'], 400); return; }

        $meta = [
            'snippet' => [
                'title' => mb_substr((string)($body['title'] ?? 'BlogPost video'), 0, 100),
                'description' => mb_substr((string)($body['description'] ?? ''), 0, 4800),
                'categoryId' => '22',
            ],
            'status' => [
                'privacyStatus' => (string)($body['privacyStatus'] ?? 'public'),
                'selfDeclaredMadeForKids' => false,
            ],
        ];
        $init = self::http('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'method' => 'POST',
            'headers' => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json; charset=utf-8',
                'X-Upload-Content-Length: ' . strlen($video),
                'X-Upload-Content-Type: ' . ($body['videoMime'] ?? 'video/mp4'),
            ],
            'body' => json_encode($meta),
            'timeout' => 60,
            'no_follow' => true,
        ]);
        $loc = $init['location'] ?? '';
        if (!$loc) { self::json(['error' => $init['body'] ?: 'no upload session'], $init['status'] ?: 502); return; }

        $up = self::http($loc, [
            'method' => 'PUT',
            'headers' => ['Content-Type: ' . ($body['videoMime'] ?? 'video/mp4'), 'Content-Length: ' . strlen($video)],
            'body' => $video,
            'timeout' => 900,
        ]);
        $d = json_decode($up['body'], true);
        $videoId = $d['id'] ?? null;
        if (!$up['status'] || $up['status'] >= 400) { self::json(['error' => $up['body'] ?: 'upload failed'], $up['status']); return; }
        self::json(['success' => true, 'videoId' => $videoId, 'id' => $videoId, 'url' => $videoId ? "https://youtu.be/{$videoId}" : null]);
    }

    /** Extract Location header — cURL follows redirects, so re-issue without follow for the session URL. */
    private static function youtubeChannels(array $body): void
    {
        $token = (string)($body['accessToken'] ?? '');
        if (!$token) { self::json(['error' => 'missing accessToken'], 400); return; }
        $r = self::http('https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&mine=true', [
            'headers' => ['Authorization: Bearer ' . $token], 'timeout' => 30,
        ]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    // ── TikTok ───────────────────────────────────────────────

    private static function tiktokUser(array $body): void
    {
        $token = (string)($body['accessToken'] ?? '');
        if (!$token) { self::json(['error' => 'missing accessToken'], 400); return; }
        $r = self::http('https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,avatar_url', [
            'headers' => ['Authorization: Bearer ' . $token], 'timeout' => 30,
        ]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    private static function tiktokPublish(array $body): void
    {
        $token = (string)($body['accessToken'] ?? '');
        if (!$token) { self::json(['error' => 'missing accessToken'], 400); return; }
        $video = self::b64((string)($body['videoBase64'] ?? ''));

        $postInfo = [
            'title' => mb_substr((string)($body['title'] ?? 'BlogPost'), 0, 2200),
            'privacy_level' => 'SELF_ONLY',
            'disable_duet' => false, 'disable_comment' => false, 'disable_stitch' => false,
        ];
        if (($body['mode'] ?? '') === 'pull' && !empty($body['videoUrl'])) {
            $source = ['source' => 'PULL_FROM_URL', 'video_url' => (string)$body['videoUrl']];
        } else {
            $source = ['source' => 'FILE_UPLOAD', 'video_size' => strlen($video), 'chunk_size' => strlen($video), 'total_chunk_count' => 1];
        }

        $init = self::http('https://open.tiktokapis.com/v2/post/publish/video/init/', [
            'method' => 'POST',
            'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'],
            'body' => json_encode(['post_info' => $postInfo, 'source_info' => $source]),
            'timeout' => 60,
        ]);
        $initData = json_decode($init['body'], true) ?: [];
        if ($init['status'] >= 400 || !empty($initData['error']['code'])) {
            self::json(['error' => $initData['error']['message'] ?? ($initData['error']['code'] ?? 'TikTok init failed'), 'detail' => $initData], $init['status'] ?: 400);
            return;
        }
        $publishId = $initData['data']['publish_id'] ?? null;
        $uploadUrl = $initData['data']['upload_url'] ?? null;

        if (($body['mode'] ?? '') !== 'pull' && $uploadUrl && $video !== '') {
            $put = self::http($uploadUrl, [
                'method' => 'PUT',
                'headers' => ['Content-Type: video/mp4', 'Content-Range: bytes 0-' . (strlen($video) - 1) . '/' . strlen($video)],
                'body' => $video,
                'timeout' => 900,
            ]);
            if ($put['status'] >= 400) {
                self::json(['error' => $put['body'] ?: 'TikTok upload failed', 'publishId' => $publishId], $put['status']);
                return;
            }
        }
        self::json(['success' => true, 'publishId' => $publishId, 'publish_id' => $publishId]);
    }

    // ── Instagram ────────────────────────────────────────────

    private static function instagramGraph(array $query, array $body, string $method): void
    {
        $m = strtoupper((string)($body['method'] ?? $method));
        $path = (string)($body['path'] ?? ($query['path'] ?? '/me'));
        $token = (string)($body['access_token'] ?? ($body['accessToken'] ?? ($query['access_token'] ?? '')));
        if (!$token) { self::json(['error' => 'missing access_token'], 400); return; }

        $url = 'https://graph.facebook.com/v19.0' . (str_starts_with($path, '/') ? $path : '/' . $path);
        $qs = ['access_token' => $token];
        if ($m === 'GET') {
            foreach ($query as $k => $v) {
                if (in_array($k, ['path', 'access_token', 'accessToken'], true) || $v === '') continue;
                $qs[$k] = $v;
            }
        }
        $url .= '?' . http_build_query($qs);

        $payload = null;
        if ($m !== 'GET') {
            $form = ['access_token' => $token];
            foreach ($body as $k => $v) {
                if (in_array($k, ['path', 'method', 'access_token', 'accessToken'], true) || $v === null || $v === '') continue;
                $form[$k] = $v;
            }
            $payload = http_build_query($form);
        }
        $r = self::http($url, [
            'method' => $m,
            'headers' => $payload ? ['Content-Type: application/x-www-form-urlencoded'] : [],
            'body' => $payload,
            'timeout' => 60,
        ]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    // ── OK.ru ────────────────────────────────────────────────

    private static function okSign(array $params, string $secret): string
    {
        $keys = array_keys(array_filter($params, fn($k) => $k !== 'sig' && $k !== 'access_token', ARRAY_FILTER_USE_KEY));
        sort($keys);
        $raw = '';
        foreach ($keys as $k) $raw .= $k . '=' . $params[$k];
        $raw .= $secret;
        return md5($raw);
    }

    private static function okFb(array $body): void
    {
        $token = (string)($body['accessToken'] ?? '');
        $appKey = (string)($body['applicationKey'] ?? '');
        $secret = (string)($body['secretKey'] ?? '');
        $inner = (array)($body['params'] ?? []);
        if (!$token) { self::json(['error' => 'missing accessToken'], 400); return; }
        if (!$appKey) { self::json(['error' => 'missing applicationKey'], 400); return; }

        $params = array_merge(['application_key' => $appKey, 'format' => 'json'], array_filter($inner, fn($v) => $v !== null && $v !== ''));
        $params['sig'] = self::okSign($params, $secret);
        $params['access_token'] = $token;

        $r = self::http('https://api.ok.ru/fb.do', [
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($params),
            'timeout' => 60,
        ]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    private static function okUpload(array $body): void
    {
        $url = (string)($body['uploadUrl'] ?? '');
        if (!$url) { self::json(['error' => 'missing uploadUrl'], 400); return; }
        $mp = self::multipart(['pic1' => ''], 'pic1', self::b64((string)($body['photoBase64'] ?? '')), $body['photoMime'] ?? 'image/jpeg', $body['photoName'] ?? 'image.jpg');
        $r = self::http($url, ['method' => 'POST', 'headers' => ['Content-Type: ' . $mp['content_type']], 'body' => $mp['body'], 'timeout' => 120]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    // ── Rutube ───────────────────────────────────────────────

    private static function rutubeMe(array $body): void
    {
        $key = (string)($body['apiKey'] ?? '');
        if (!$key) { self::json(['error' => 'missing apiKey'], 400); return; }
        $r = self::http('https://rutube.ru/api/whoami/', ['headers' => ['Rutube-Api-Key: ' . $key], 'timeout' => 30]);
        self::raw($r['body'], 'application/json; charset=utf-8', $r['status']);
    }

    private static function rutubeFromUrl(array $body): void
    {
        $key = (string)($body['apiKey'] ?? '');
        if (!$key) { self::json(['error' => 'missing apiKey'], 400); return; }
        $r = self::http('https://rutube.ru/api/video/from_url/', [
            'method' => 'POST',
            'headers' => ['Rutube-Api-Key: ' . $key, 'Content-Type: application/json; charset=utf-8'],
            'body' => json_encode([
                'url' => (string)($body['url'] ?? ''),
                'title' => mb_substr((string)($body['title'] ?? 'BlogPost video'), 0, 150),
                'description' => mb_substr((string)($body['description'] ?? ''), 0, 5000),
                'is_private' => (bool)($body['isPrivate'] ?? false),
            ]),
            'timeout' => 120,
        ]);
        self::rutubeRespond($r);
    }

    private static function rutubeUpload(array $body): void
    {
        $key = (string)($body['apiKey'] ?? '');
        if (!$key) { self::json(['error' => 'missing apiKey'], 400); return; }
        $video = self::b64((string)($body['videoBase64'] ?? ''));
        if ($video === '') { self::json(['error' => 'empty video'], 400); return; }

        $mp = self::multipart([
            'title' => mb_substr((string)($body['title'] ?? 'BlogPost video'), 0, 150),
            'description' => mb_substr((string)($body['description'] ?? ''), 0, 5000),
            'is_private' => !empty($body['isPrivate']) ? '1' : '0',
            'category' => '22',
        ], 'file', $video, $body['videoMime'] ?? 'video/mp4', 'video.mp4');
        $r = self::http('https://rutube.ru/api/video/upload/', [
            'method' => 'POST',
            'headers' => ['Rutube-Api-Key: ' . $key, 'Content-Type: ' . $mp['content_type']],
            'body' => $mp['body'],
            'timeout' => 900,
        ]);
        self::rutubeRespond($r);
    }

    private static function rutubeRespond(array $r): void
    {
        $d = json_decode($r['body'], true) ?: [];
        $videoId = $d['id'] ?? ($d['video_id'] ?? ($d['data']['id'] ?? null));
        $ok = $r['status'] < 400 && $videoId !== null;
        self::json([
            'success' => $ok,
            'videoId' => $videoId !== null ? (string)$videoId : null,
            'url' => $videoId ? "https://rutube.ru/video/{$videoId}/" : null,
            'error' => $ok ? null : ($d['detail'] ?? ($d['error'] ?? ($d['message'] ?? 'Rutube failed'))),
            'raw' => $d,
        ], $ok ? 200 : ($r['status'] ?: 400));
    }
}
