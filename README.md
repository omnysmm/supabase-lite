# supabase-lite

A self-hosted, single-file replacement for Supabase — PHP 8 + SQLite, zero external services.

Built to run BlogPost (React+Vite AI blogging platform) on plain shared PHP hosting where
managed Supabase is unavailable or undesired. The frontend talks to it with the **stock**
`@supabase/supabase-js` client — no frontend changes needed.

## What it implements

| Surface | Routes | Notes |
|---|---|---|
| **Auth (GoTrue)** | `/auth/v1/signup`, `/auth/v1/token` (password + refresh_token), `/auth/v1/logout`, `/auth/v1/user`, `/auth/v1/recover`, `/auth/v1/verify` (magiclink OTP), `/auth/v1/authorize` | HS256 JWT sessions, bcrypt passwords |
| **Data API (PostgREST)** | `/rest/v1/{table}` GET/POST/PATCH/DELETE | Filters `eq/neq/gt/gte/lt/lte/like/in/is`, `order`, `limit`, `offset`, `select`, `single()` (Accept: `application/vnd.pgrst.object+json`), `count=exact` (HEAD + `Content-Range`) |
| **Upsert** | `POST` + `Prefer: resolution=merge-duplicates` + `?on_conflict=col1,col2` | Matches postgrest-js `upsert()` wire format |
| **RPC** | `/rest/v1/rpc/{fn}` | `use_promo_code`, `expire_subscriptions` |
| **Edge functions** | `/functions/v1/*` | `ai-generate-text/image/audio`, `ai-seo-optimize`, `social-auth`/`social-oauth` (VK/Yandex/OK), `social-publish`, `telegram-publish`, `schedule-publish`, `create-payment` (YooKassa), `refund-payment`, `yookassa-webhook`, `subscription-expiry` |
| **Vite middleware** | `/api/*` | `generate`, `image`, `generate-health`, `telegram/*`, `vk/*`, `youtube/*`, `tiktok/*`, `instagram/graph`, `ok/*`, `rutube/*` — CORS-safe proxies for social publishing + AI |
| **Realtime** | — | Not implemented (support chat falls back to local storage) |

## Type fidelity

SQLite stores JSON/arrays as TEXT and booleans as 0/1. `src/Db.php` decodes them on the
way out so the JS client sees the same shapes as real PostgREST: `social_networks` → array,
`config` → object, `has_image` → `true/false`.

## RLS-style scoping

Tables with a `user_id` owner column are auto-scoped to the authenticated user
(`profiles` scopes by `id`). Admin role bypasses the scoping. Mirrors the RLS policies
in BlogPost's `supabase/schema.sql`.

## Quick start

```bash
# PHP built-in server
php -S 127.0.0.1:8080 -t public

# Integration tests (needs @supabase/supabase-js)
npm install
node tests/test-api.mjs
```

The SQLite DB is created automatically at `data/db.sqlite` from `schema.sql` on first request.

## Deploying

Upload the project to any PHP 8+ host (Apache + `mod_rewrite` or nginx + `try_files`).
Point the frontend at it:

```env
VITE_SUPABASE_URL=https://your-host.example.com
VITE_SUPABASE_ANON_KEY=<any non-empty string>   # accepted for wire compatibility
```

Optional env vars unlock real integrations:
`VK_CLIENT_ID/VK_CLIENT_SECRET`, `YANDEX_CLIENT_ID/YANDEX_CLIENT_SECRET`,
`OK_CLIENT_ID/OK_CLIENT_SECRET`, `YOOKASSA_SHOP_ID/YOOKASSA_SECRET_KEY`, `CRON_SECRET`.

## Security notes

- Change `data/jwt_secret` after first boot (delete it; a new one is generated).
- Put `data/` outside the web root or deny HTTP access to it in your web server config.
- Passwords use `password_hash` (bcrypt). Sessions are HS256 JWTs + rotating refresh tokens.
