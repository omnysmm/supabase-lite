<?php
/**
 * supabase-lite — GoTrue-compatible auth (signup, login, logout, user, otp).
 */

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Jwt.php';

class Auth
{
    /** Current user id from the bearer token, or null (anon). */
    public static function currentUserId(): ?string
    {
        $payload = self::tokenPayload();
        if (!$payload) return null;
        $role = $payload['role'] ?? '';
        if ($role === 'anon' || $role === 'service_role') return null;
        return $payload['sub'] ?? null;
    }

    public static function isAdmin(): bool
    {
        $payload = self::tokenPayload();
        if (!$payload) return false;
        if (($payload['role'] ?? '') === 'service_role') return true;
        $uid = $payload['sub'] ?? null;
        if (!$uid) return false;
        $st = Db::pdo()->prepare("SELECT role FROM profiles WHERE id = ?");
        $st->execute([$uid]);
        $row = $st->fetch();
        return $row && $row['role'] === 'admin';
    }

    private static function tokenPayload(): ?array
    {
        static $cached = false;
        static $payload = null;
        if ($cached) return $payload;
        $cached = true;
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) return null;
        $payload = Jwt::decode($m[1]);
        return $payload;
    }

    public static function userToJson(array $u): array
    {
        $now = $u['created_at'] ?? gmdate('Y-m-d H:i:s');
        return [
            'id' => $u['id'],
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'email' => $u['email'],
            'email_confirmed_at' => $u['confirmed_at'] ?? $now,
            'phone' => '',
            'confirmed_at' => $u['confirmed_at'] ?? $now,
            'last_sign_in_at' => $now,
            'app_metadata' => ['provider' => 'email', 'providers' => ['email']],
            'user_metadata' => ['name' => $u['name'] ?? ''],
            'identities' => [[
                'id' => $u['id'],
                'user_id' => $u['id'],
                'identity_id' => $u['id'],
                'provider' => 'email',
                'email' => $u['email'],
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            'created_at' => $now,
            'updated_at' => $u['updated_at'] ?? $now,
            'is_anonymous' => false,
        ];
    }

    public static function sessionFor(array $u): array
    {
        $expiresIn = 3600;
        $access = Jwt::accessToken($u['id'], $u['email']);
        $refresh = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 24 * 3600);
        $st = Db::pdo()->prepare("INSERT OR REPLACE INTO refresh_tokens(token, user_id, expires_at, created_at) VALUES (?,?,?,?)");
        $st->execute([$refresh, $u['id'], $expiresAt, Db::now()]);
        return [
            'access_token' => $access,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'expires_at' => time() + $expiresIn,
            'refresh_token' => $refresh,
            'user' => self::userToJson($u),
        ];
    }

    public static function signup(array $body): array
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $meta = (array)($body['data'] ?? []);
        if (!$email || strlen($password) < 6) {
            return ['status' => 422, 'json' => ['code' => 'validation_failed', 'msg' => 'Invalid email or password (min 6 chars)']];
        }
        $st = Db::pdo()->prepare("SELECT id FROM auth_users WHERE email = ?");
        $st->execute([$email]);
        if ($st->fetch()) {
            // GoTrue returns 200 with existing user shape to avoid user enumeration
            return ['status' => 200, 'json' => ['code' => 200, 'msg' => 'User already registered']];
        }
        $id = Db::uuid();
        $now = Db::now();
        $name = (string)($meta['name'] ?? '');
        $st = Db::pdo()->prepare("INSERT INTO auth_users(id, email, password_hash, name, created_at, updated_at, confirmed_at) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$id, $email, password_hash($password, PASSWORD_BCRYPT), $name, $now, $now, $now]);

        // Mirror the Supabase trigger handle_new_user(): create profile row
        $st = Db::pdo()->prepare("INSERT INTO profiles(id, name, email, role, subscription, language, currency, free_trial_end, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$id, $name, $email, 'user', 'free', 'ru', 'RUB', gmdate('Y-m-d H:i:s', time() + 48 * 3600), $now, $now]);

        $u = ['id' => $id, 'email' => $email, 'name' => $name, 'confirmed_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        return ['status' => 200, 'json' => self::sessionFor($u)];
    }

    public static function login(array $body): array
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $st = Db::pdo()->prepare("SELECT * FROM auth_users WHERE email = ?");
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            return ['status' => 400, 'json' => ['error' => 'invalid_grant', 'error_description' => 'Invalid login credentials']];
        }
        $st = Db::pdo()->prepare("UPDATE auth_users SET updated_at = ? WHERE id = ?");
        $st->execute([Db::now(), $u['id']]);
        return ['status' => 200, 'json' => self::sessionFor($u)];
    }

    public static function refresh(array $body): array
    {
        $token = (string)($body['refresh_token'] ?? '');
        $st = Db::pdo()->prepare("SELECT u.* FROM refresh_tokens t JOIN auth_users u ON u.id = t.user_id WHERE t.token = ? AND t.expires_at > ?");
        $st->execute([$token, Db::now()]);
        $u = $st->fetch();
        if (!$u) {
            return ['status' => 400, 'json' => ['error' => 'invalid_grant', 'error_description' => 'Invalid Refresh Token: Refresh Token Not Found']];
        }
        Db::pdo()->prepare("DELETE FROM refresh_tokens WHERE token = ?")->execute([$token]);
        return ['status' => 200, 'json' => self::sessionFor($u)];
    }

    public static function logout(): void
    {
        $uid = self::currentUserId();
        if ($uid) {
            Db::pdo()->prepare("DELETE FROM refresh_tokens WHERE user_id = ?")->execute([$uid]);
        }
    }

    public static function getUser(): ?array
    {
        $uid = self::currentUserId();
        if (!$uid) return null;
        $st = Db::pdo()->prepare("SELECT * FROM auth_users WHERE id = ?");
        $st->execute([$uid]);
        $u = $st->fetch();
        return $u ? self::userToJson($u) : null;
    }

    /** magiclink / email_otp verify used by social login callback. */
    public static function verifyOtp(array $body): array
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $token = (string)($body['token'] ?? '');
        if (!$email || !$token) {
            return ['status' => 400, 'json' => ['error' => 'invalid_grant', 'error_description' => 'invalid token']];
        }
        // Social login: accept the signed one-time token issued by social-auth
        $expected = substr(hash('sha256', 'otp|' . $email . '|' . self::otpSecret()), 0, 12);
        if (!hash_equals($expected, $token)) {
            return ['status' => 400, 'json' => ['error' => 'invalid_grant', 'error_description' => 'invalid token']];
        }
        $st = Db::pdo()->prepare("SELECT * FROM auth_users WHERE email = ?");
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u) {
            $id = Db::uuid();
            $now = Db::now();
            Db::pdo()->prepare("INSERT INTO auth_users(id, email, password_hash, name, created_at, updated_at, confirmed_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([$id, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), '', $now, $now, $now]);
            Db::pdo()->prepare("INSERT INTO profiles(id, name, email, role, subscription, language, currency, free_trial_end, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$id, '', $email, 'user', 'free', 'ru', 'RUB', gmdate('Y-m-d H:i:s', time() + 48 * 3600), $now, $now]);
            $st->execute([$email]);
            $u = $st->fetch();
        }
        return ['status' => 200, 'json' => self::sessionFor($u)];
    }

    public static function otpSecret(): string
    {
        return file_get_contents(__DIR__ . '/../data/jwt_secret') ?: 'otp-secret';
    }

    public static function recover(array $body): array
    {
        // No mail transport on the lite stack: accept and ignore (same as Supabase
        // when SMTP is unconfigured) so the UI flow completes.
        return ['status' => 200, 'json' => []];
    }
}
