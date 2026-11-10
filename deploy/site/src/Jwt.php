<?php
/**
 * supabase-lite — minimal HS256 JWT (compatible with GoTrue token shape).
 */

class Jwt
{
    // Stand-in signing secret. Supabase's anon key is a JWT signed with the
    // project's secret; clients treat it as an opaque bearer token, so any
    // verifiable HS256 token works as the "anon" key here.
    private static function secret(): string
    {
        $f = __DIR__ . '/../data/jwt_secret';
        if (is_file($f)) return trim((string)file_get_contents($f));
        $s = bin2hex(random_bytes(32));
        if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
        file_put_contents($f, $s);
        return $s;
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload): string
    {
        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = self::b64url(self::sign("$header.$body"));
        return "$header.$body.$sig";
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret(), true);
    }

    /** Returns payload array on success, null on failure. */
    public static function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $p, $s] = $parts;
        if (!hash_equals(self::b64url(self::sign("$h.$p")), $s)) return null;
        $payload = json_decode(self::b64urlDecode($p), true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && time() > (int)$payload['exp']) return null;
        return $payload;
    }

    /** Access token as GoTrue shapes it (claims used by supabase-js). */
    public static function accessToken(string $userId, string $email, string $role = 'authenticated', int $ttl = 3600): string
    {
        $now = time();
        return self::encode([
            'sub' => $userId,
            'aud' => 'authenticated',
            'role' => $role,
            'email' => $email,
            'phone' => '',
            'app_metadata' => ['provider' => 'email', 'providers' => ['email']],
            'user_metadata' => [],
            'aal' => 'aal1',
            'amr' => [['method' => 'password', 'timestamp' => $now]],
            'session_id' => self::b64url(random_bytes(9)),
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);
    }

    /** Fixed "anon" API key token (matches the role supabase-js expects). */
    public static function anonKey(): string
    {
        $now = time();
        return self::encode([
            'iss' => 'supabase',
            'ref' => 'supabase-lite',
            'role' => 'anon',
            'iat' => $now,
            'exp' => $now + 10 * 365 * 24 * 3600,
        ]);
    }

    public static function serviceKey(): string
    {
        $now = time();
        return self::encode([
            'iss' => 'supabase',
            'ref' => 'supabase-lite',
            'role' => 'service_role',
            'iat' => $now,
            'exp' => $now + 10 * 365 * 24 * 3600,
        ]);
    }
}
