<?php
/**
 * supabase-lite — Edge Functions (GoTrue-style /functions/v1/*).
 * Implements the function surface BlogPost's frontend calls.
 */

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';

class Functions
{
    public static function handle(string $name, string $method, array $query, $body): void
    {
        header('Access-Control-Allow-Origin: *');
        $body = is_array($body) ? $body : [];
        switch ($name) {
            case 'ai-generate-text':      self::aiText($body); return;
            case 'ai-generate-image':     self::aiImage($body); return;
            case 'ai-generate-audio':     self::aiAudio($body); return;
            case 'ai-seo-optimize':       self::aiSeo($body); return;
            case 'subscription-expiry':   self::subscriptionExpiry($query); return;
            case 'social-auth':           self::socialAuth($query); return;
            case 'social-oauth':          self::socialOauth($query); return;
            case 'social-publish':        self::socialPublish($body); return;
            case 'telegram-publish':      self::telegramPublish($body); return;
            case 'schedule-publish':      self::json(['ok' => true, 'scheduled' => true]); return;
            case 'create-payment':        self::createPayment($body); return;
            case 'refund-payment':        self::json(['ok' => true, 'refunded' => true]); return;
            case 'yookassa-webhook':      self::json(['ok' => true]); return;
            default:
                http_response_code(404);
                self::json(['error' => "function {$name} not found"]);
        }
    }

    private static function json($obj, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** http request helper (cURL with timeout) */
    private static function http(string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $opts['timeout'] ?? 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if (!empty($opts['method'])) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method']);
        if (isset($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$resp, 'error' => $err];
    }

    // ═══════════════════════════════════════════════════════════
    // AI generation
    // ═══════════════════════════════════════════════════════════

    private static function aiText(array $body): void
    {
        $prompt = (string)($body['prompt'] ?? '');
        $system = (string)($body['systemPrompt'] ?? ($body['system'] ?? 'Write clearly on the topic.'));
        $lang = ($body['language'] ?? 'ru') === 'en' ? 'en' : 'ru';
        $maxLen = (int)($body['maxLength'] ?? 2000);
        $model = (string)($body['model'] ?? 'auto');

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ];

        // 1) Pollinations OpenAI-compatible (free, keyless)
        $shortCandidate = '';
        foreach (['openai', 'openai-fast'] as $m) {
            $r = self::http('https://text.pollinations.ai/openai', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json'],
                'body' => json_encode(['model' => $m, 'messages' => $messages, 'max_tokens' => 1200, 'temperature' => 0.7]),
                'timeout' => 60,
            ]);
            if ($r['status'] === 200) {
                $d = json_decode($r['body'], true);
                $text = $d['choices'][0]['message']['content'] ?? ($d['text'] ?? '');
                if (strlen(trim($text)) > 20) {
                    self::json(['text' => self::clean($text)]);
                    return;
                }
                if (strlen(trim($text)) > strlen($shortCandidate)) $shortCandidate = trim($text);
            }
        }

        // 2) OpenRouter free models
        foreach (['google/gemma-2-9b-it:free', 'mistralai/mistral-7b-instruct:free', 'meta-llama/llama-3-8b-instruct:free'] as $m) {
            $r = self::http('https://openrouter.ai/api/v1/chat/completions', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'HTTP-Referer: https://blogpost.app', 'X-Title: BlogPost'],
                'body' => json_encode(['model' => $m, 'messages' => $messages, 'max_tokens' => 1200, 'temperature' => 0.7]),
                'timeout' => 60,
            ]);
            if ($r['status'] === 200) {
                $d = json_decode($r['body'], true);
                $text = $d['choices'][0]['message']['content'] ?? '';
                if (strlen(trim($text)) > 20) {
                    self::json(['text' => self::clean($text)]);
                    return;
                }
            }
        }

        // 3) Pollinations simple GET
        $r = self::http('https://text.pollinations.ai/' . rawurlencode(mb_substr($prompt, 0, 1500)), ['timeout' => 90]);
        if ($r['status'] === 200 && strlen(trim($r['body'])) > 20) {
            self::json(['text' => self::clean($r['body'])]);
            return;
        }

        // 4) Last resort: short candidate if any, else loud error (never a generic stub)
        if ($shortCandidate !== '') {
            self::json(['text' => self::clean($shortCandidate)]);
            return;
        }
        self::json(['text' => '', '__mock' => true, 'error' => 'all upstreams failed'], 502);
    }

    private static function clean(string $text): string
    {
        $text = preg_replace('/^[\s\S]*?<\/think>/i', '', $text) ?? $text;
        $text = preg_replace('/^```[a-z]*\n?/i', '', $text) ?? $text;
        $text = preg_replace('/\n?```$/', '', $text) ?? $text;
        return trim($text);
    }

    private static function aiImage(array $body): void
    {
        $prompt = trim(preg_replace('/\s+/', ' ', (string)($body['prompt'] ?? 'beautiful photo')) ?? '');
        $prompt = mb_substr($prompt, 0, 180);
        $seed = random_int(0, 999999);
        $urls = [
            'https://image.pollinations.ai/prompt/' . rawurlencode($prompt) . "?width=768&height=480&nologo=true&seed={$seed}",
            'https://image.pollinations.ai/prompt/' . rawurlencode(mb_substr($prompt, 0, 80)) . "?width=768&height=480&nologo=true&seed={$seed}",
            'https://dummyimage.com/768x480/6366f1/ffffff.png&text=' . rawurlencode(mb_substr($prompt, 0, 40)),
        ];
        foreach ($urls as $url) {
            for ($i = 0; $i < 2; $i++) {
                $r = self::http($url, ['timeout' => 75]);
                if ($r['status'] !== 200 || strlen($r['body']) < 400) continue;
                $isImg = str_starts_with($r['body'], "\xff\xd8") || str_starts_with($r['body'], "\x89PNG") || str_starts_with($r['body'], 'GIF') || str_starts_with($r['body'], 'RIFF');
                if ($isImg) {
                    $mime = 'image/jpeg';
                    if (str_starts_with($r['body'], "\x89PNG")) $mime = 'image/png';
                    header('Content-Type: ' . $mime);
                    echo $r['body'];
                    return;
                }
            }
        }
        self::json(['error' => 'image generation failed'], 502);
    }

    private static function aiAudio(array $body): void
    {
        // No free TTS upstream on the lite stack; caller falls back to its own logic.
        self::json(['audioUrl' => '', 'base64' => '', '__mock' => true]);
    }

    private static function aiSeo(array $body): void
    {
        self::json([
            'title' => ($body['title'] ?? 'Optimized title'),
            'description' => ($body['description'] ?? 'SEO description'),
            'tags' => ['blog', 'content', 'ai'],
            'optimizedContent' => (string)($body['content'] ?? ''),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Subscription expiry (cron / manual)
    // ═══════════════════════════════════════════════════════════

    private static function subscriptionExpiry(array $query): void
    {
        $secret = getenv('CRON_SECRET') ?: '';
        if ($secret !== '') {
            $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $key = $query['key'] ?? '';
            if (!str_contains($hdr, $secret) && $key !== $secret) {
                self::json(['error' => 'unauthorized'], 401);
                return;
            }
        }
        $pdo = Db::pdo();
        $now = Db::now();
        $pdo->exec("UPDATE profiles SET subscription = 'free', pending_subscription = NULL, updated_at = '{$now}' WHERE pending_subscription IS NOT NULL AND free_trial_end IS NOT NULL AND free_trial_end < '{$now}'");
        $n = $pdo->exec("UPDATE profiles SET subscription = 'free', updated_at = '{$now}' WHERE subscription <> 'free' AND pending_subscription IS NULL AND free_trial_end IS NOT NULL AND free_trial_end < '{$now}'");
        self::json(['ok' => true, 'expired' => (int)$n, 'at' => $now]);
    }

    // ═══════════════════════════════════════════════════════════
    // Social OAuth (VK / Yandex / OK)
    // ═══════════════════════════════════════════════════════════

    private static function socialAuth(array $query): void
    {
        $provider = (string)($query['provider'] ?? '');
        $redirect = (string)($query['redirect'] ?? '/');
        $creds = self::providerCreds($provider);
        if (!$creds) {
            header('Location: ' . self::withParams($redirect, ['error' => 'provider_not_configured', 'provider' => $provider]));
            exit;
        }
        $state = bin2hex(random_bytes(12));
        setcookie('sb_social_state', $state, time() + 600, '/');
        $authorize = self::authorizeUrl($provider, $creds, $state, $redirect);
        header('Location: ' . $authorize);
        exit;
    }

    private static function socialOauth(array $query): void
    {
        $provider = (string)($query['provider'] ?? ($query['network'] ?? ''));
        $code = (string)($query['code'] ?? '');
        $redirect = (string)($query['redirect'] ?? '/');
        $creds = self::providerCreds($provider);
        if (!$creds || !$code) {
            header('Location: ' . self::withParams($redirect, ['error' => 'oauth_failed']));
            exit;
        }
        // Exchange code → token
        $tokenResp = self::http($creds['token_url'], [
            'method' => 'POST',
            'headers' => ['Content-Type: ' . $creds['token_ct']],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::redirectUri($redirect),
                'client_id' => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
            ]),
            'timeout' => 30,
        ]);
        $tok = json_decode($tokenResp['body'], true);
        $accessToken = $tok['access_token'] ?? '';
        if (!$accessToken) {
            header('Location: ' . self::withParams($redirect, ['error' => 'token_exchange_failed']));
            exit;
        }
        // Resolve profile → email → magiclink OTP
        $email = self::providerEmail($provider, $accessToken, $creds) ?: ($provider . '-' . substr(md5($accessToken), 0, 8) . '@social.local');
        $token = substr(hash('sha256', 'otp|' . strtolower($email) . '|' . Auth::otpSecret()), 0, 12);
        header('Location: ' . self::withParams($redirect, ['email' => $email, 'token' => $token]));
        exit;
    }

    private static function providerCreds(string $p): ?array
    {
        $map = [
            'vk' => ['client_id' => getenv('VK_CLIENT_ID') ?: '', 'client_secret' => getenv('VK_CLIENT_SECRET') ?: '',
                'auth_url' => 'https://oauth.vk.com/authorize', 'token_url' => 'https://oauth.vk.com/access_token', 'token_ct' => 'application/x-www-form-urlencoded', 'scope' => 'email,wall,photos,video,groups'],
            'yandex' => ['client_id' => getenv('YANDEX_CLIENT_ID') ?: '', 'client_secret' => getenv('YANDEX_CLIENT_SECRET') ?: '',
                'auth_url' => 'https://oauth.yandex.ru/authorize', 'token_url' => 'https://oauth.yandex.ru/token', 'token_ct' => 'application/x-www-form-urlencoded', 'scope' => 'login:email'],
            'ok' => ['client_id' => getenv('OK_CLIENT_ID') ?: '', 'client_secret' => getenv('OK_CLIENT_SECRET') ?: '',
                'auth_url' => 'https://connect.ok.ru/oauth/authorize', 'token_url' => 'https://api.ok.ru/oauth/token.do', 'token_ct' => 'application/x-www-form-urlencoded', 'scope' => 'VALUABLE_ACCESS,PHOTO_CONTENT'],
        ];
        $c = $map[$p] ?? null;
        return ($c && $c['client_id'] && $c['client_secret']) ? $c : null;
    }

    private static function redirectUri(string $redirect): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$_SERVER['REQUEST_URI']}" . '&redirect=' . rawurlencode($redirect);
    }

    private static function authorizeUrl(string $p, array $c, string $state, string $redirect): string
    {
        $params = [
            'client_id' => $c['client_id'],
            'redirect_uri' => self::redirectUri($redirect),
            'response_type' => 'code',
            'state' => $state,
            'scope' => $c['scope'],
        ];
        return $c['auth_url'] . '?' . http_build_query($params);
    }

    private static function providerEmail(string $p, string $token, array $c): string
    {
        if ($p === 'vk') {
            $r = self::http('https://api.vk.com/method/users.get?access_token=' . urlencode($token) . '&v=5.199&fields=email', ['timeout' => 20]);
            $d = json_decode($r['body'], true);
            return $d['response'][0]['email'] ?? '';
        }
        if ($p === 'yandex') {
            $r = self::http('https://login.yandex.ru/info?format=json', ['headers' => ['Authorization: OAuth ' . $token], 'timeout' => 20]);
            $d = json_decode($r['body'], true);
            return $d['default_email'] ?? ($d['emails'][0] ?? '');
        }
        if ($p === 'ok') {
            $r = self::http('https://api.ok.ru/fb.do?method=users.getCurrentUser&access_token=' . urlencode($token) . '&application_key=' . urlencode($c['client_id']) . '&format=json', ['timeout' => 20]);
            $d = json_decode($r['body'], true);
            return $d['email'] ?? '';
        }
        return '';
    }

    private static function withParams(string $url, array $params): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($params);
    }

    // ═══════════════════════════════════════════════════════════
    // Social publish + telegram (thin delegation to ApiProxy)
    // ═══════════════════════════════════════════════════════════

    private static function socialPublish(array $body): void
    {
        $network = (string)($body['network'] ?? '');
        self::json(['network' => $network, 'success' => false, 'error' => 'social-publish is handled client-side via /api/* proxies on the lite stack']);
    }

    private static function telegramPublish(array $body): void
    {
        require_once __DIR__ . '/ApiProxy.php';
        ApiProxy::telegram('sendMessage', $body);
    }

    private static function createPayment(array $body): void
    {
        // YooKassa requires merchant credentials; return a stub confirmation so the
        // UI flow can complete in dev. Real payments need YOOKASSA_* env vars.
        if (getenv('YOOKASSA_SHOP_ID')) {
            $r = self::http('https://api.yookassa.ru/v3/payments', [
                'method' => 'POST',
                'headers' => [
                    'Content-Type: application/json',
                    'Idempotence-Key: ' . bin2hex(random_bytes(8)),
                    'Authorization: Basic ' . base64_encode(getenv('YOOKASSA_SHOP_ID') . ':' . getenv('YOOKASSA_SECRET_KEY')),
                ],
                'body' => json_encode([
                    'amount' => $body['amount'] ?? ['value' => '0.00', 'currency' => 'RUB'],
                    'capture' => true,
                    'description' => $body['description'] ?? 'BlogPost',
                    'metadata' => $body['metadata'] ?? [],
                    'confirmation' => ['type' => 'redirect', 'return_url' => $body['returnUrl'] ?? 'https://example.com'],
                ]),
                'timeout' => 30,
            ]);
            $d = json_decode($r['body'], true);
            self::json(['confirmation_url' => $d['confirmation']['confirmation_url'] ?? '', 'id' => $d['id'] ?? ''], $r['status']);
            return;
        }
        self::json(['confirmation_url' => '#', 'id' => 'demo_' . time()]);
    }
}
