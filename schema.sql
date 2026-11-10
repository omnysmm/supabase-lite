-- supabase-lite: SQLite schema (adapted from BlogPost's Supabase/Postgres schema)
-- Single file DB, zero-config. JSON/array columns stored as TEXT.

CREATE TABLE IF NOT EXISTS auth_users (
  id            TEXT PRIMARY KEY,
  email         TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  name          TEXT DEFAULT '',
  created_at    TEXT DEFAULT (datetime('now')),
  updated_at    TEXT DEFAULT (datetime('now')),
  confirmed_at  TEXT
);

CREATE TABLE IF NOT EXISTS refresh_tokens (
  token      TEXT PRIMARY KEY,
  user_id    TEXT NOT NULL REFERENCES auth_users(id) ON DELETE CASCADE,
  expires_at TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_refresh_user ON refresh_tokens(user_id);

CREATE TABLE IF NOT EXISTS profiles (
  id                  TEXT PRIMARY KEY,
  name                TEXT DEFAULT '',
  email               TEXT DEFAULT '',
  role                TEXT DEFAULT 'user' CHECK (role IN ('user','advertiser','admin')),
  subscription        TEXT DEFAULT 'free' CHECK (subscription IN ('free','basic','pro','premium')),
  avatar_url          TEXT,
  language            TEXT DEFAULT 'ru',
  currency            TEXT DEFAULT 'RUB',
  free_trial_end      TEXT,
  pending_subscription TEXT,
  status              TEXT DEFAULT 'active',
  created_at          TEXT DEFAULT (datetime('now')),
  updated_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS posts (
  id               TEXT PRIMARY KEY,
  user_id          TEXT NOT NULL,
  title            TEXT DEFAULT '',
  content          TEXT DEFAULT '',
  topic            TEXT DEFAULT '',
  type             TEXT DEFAULT 'post' CHECK (type IN ('post','article','video','music')),
  status           TEXT DEFAULT 'draft' CHECK (status IN ('draft','generating','ready','queued','scheduled','published','moderating','rejected')),
  ai_model         TEXT,
  social_networks  TEXT DEFAULT '[]',
  scheduled_at     TEXT,
  published_at     TEXT,
  scheduled_dates  TEXT DEFAULT '[]',
  scheduled_time   TEXT,
  moderation_note  TEXT,
  has_audio        INTEGER DEFAULT 0,
  has_video        INTEGER DEFAULT 0,
  has_image        INTEGER DEFAULT 0,
  image_url        TEXT,
  views            INTEGER DEFAULT 0,
  likes            INTEGER DEFAULT 0,
  created_at       TEXT DEFAULT (datetime('now')),
  updated_at       TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_posts_user ON posts(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_user_created ON posts(user_id, created_at);

CREATE TABLE IF NOT EXISTS social_accounts (
  id            TEXT PRIMARY KEY,
  user_id       TEXT NOT NULL,
  network       TEXT NOT NULL,
  account_name  TEXT DEFAULT '',
  account_id    TEXT DEFAULT '',
  access_token  TEXT,
  refresh_token TEXT,
  expires_at    TEXT,
  connected     INTEGER DEFAULT 1,
  connected_at  TEXT DEFAULT (datetime('now')),
  config        TEXT DEFAULT '{}'
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_social_user_network ON social_accounts(user_id, network);

CREATE TABLE IF NOT EXISTS ad_campaigns (
  id             TEXT PRIMARY KEY,
  user_id        TEXT NOT NULL,
  title          TEXT DEFAULT '',
  status         TEXT DEFAULT 'draft',
  format         TEXT,
  placement      TEXT,
  site_position  TEXT,
  video_position TEXT,
  payment_model  TEXT,
  bid            REAL DEFAULT 0,
  budget_total   REAL DEFAULT 0,
  budget_daily   REAL DEFAULT 0,
  budget_spent   REAL DEFAULT 0,
  impressions    INTEGER DEFAULT 0,
  clicks         INTEGER DEFAULT 0,
  conversions    INTEGER DEFAULT 0,
  media_url      TEXT,
  media_type     TEXT,
  link           TEXT,
  description    TEXT,
  start_date     TEXT,
  end_date       TEXT,
  moderation_note TEXT DEFAULT '',
  spend_today    REAL DEFAULT 0,
  views_today    INTEGER DEFAULT 0,
  last_billing_at TEXT,
  created_at     TEXT DEFAULT (datetime('now')),
  updated_at     TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ad_events (
  id          TEXT PRIMARY KEY,
  campaign_id TEXT,
  user_id     TEXT,
  kind        TEXT NOT NULL CHECK (kind IN ('impression','click')),
  position    TEXT DEFAULT '',
  date        TEXT DEFAULT (date('now')),
  cost        REAL DEFAULT 0,
  created_at  TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ad_events_campaign ON ad_events(campaign_id, date);

CREATE TABLE IF NOT EXISTS payments (
  id                 TEXT PRIMARY KEY,
  user_id            TEXT,
  amount             REAL NOT NULL,
  currency           TEXT DEFAULT 'RUB',
  description        TEXT DEFAULT '',
  status             TEXT DEFAULT 'pending',
  payment_method     TEXT,
  yookassa_payment_id TEXT,
  metadata           TEXT DEFAULT '{}',
  created_at         TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS billing_entries (
  id             TEXT PRIMARY KEY,
  user_id        TEXT,
  kind           TEXT NOT NULL CHECK (kind IN ('payment','charge','refund','bonus')),
  amount         REAL DEFAULT 0,
  currency       TEXT DEFAULT 'RUB',
  description    TEXT DEFAULT '',
  campaign_id    TEXT,
  campaign_title TEXT DEFAULT '',
  method         TEXT DEFAULT '',
  status         TEXT DEFAULT 'succeeded' CHECK (status IN ('succeeded','pending','failed','draft')),
  created_at     TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_billing_user ON billing_entries(user_id, created_at);

CREATE TABLE IF NOT EXISTS tickets (
  id         TEXT PRIMARY KEY,
  user_id    TEXT NOT NULL,
  subject    TEXT DEFAULT '',
  status     TEXT DEFAULT 'open',
  priority   TEXT DEFAULT 'medium',
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ticket_messages (
  id         TEXT PRIMARY KEY,
  ticket_id  TEXT NOT NULL,
  sender     TEXT NOT NULL,
  content    TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages(ticket_id);

CREATE TABLE IF NOT EXISTS analytics (
  id            TEXT PRIMARY KEY,
  user_id       TEXT NOT NULL,
  post_id       TEXT,
  network       TEXT,
  date          TEXT DEFAULT (date('now')),
  views         INTEGER DEFAULT 0,
  likes         INTEGER DEFAULT 0,
  shares        INTEGER DEFAULT 0,
  publications  INTEGER DEFAULT 0,
  created_at    TEXT DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_analytics_user_date_network ON analytics(user_id, date, network);
CREATE INDEX IF NOT EXISTS idx_analytics_user_date ON analytics(user_id, date);

CREATE TABLE IF NOT EXISTS user_blocks (
  id           TEXT PRIMARY KEY,
  user_id      TEXT NOT NULL,
  block_type   TEXT NOT NULL,
  active       INTEGER DEFAULT 1,
  purchased_at TEXT DEFAULT (datetime('now')),
  expires_at   TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_blocks_type ON user_blocks(user_id, block_type);

CREATE TABLE IF NOT EXISTS auto_tasks (
  id              TEXT PRIMARY KEY,
  user_id         TEXT NOT NULL,
  name            TEXT DEFAULT '',
  content_type    TEXT DEFAULT 'post',
  frequency       TEXT DEFAULT 'daily',
  schedule        TEXT DEFAULT '{"time":"10:00","days":[]}',
  scheduled_dates TEXT DEFAULT '[]',
  networks        TEXT DEFAULT '[]',
  topics          TEXT DEFAULT '[]',
  active          INTEGER DEFAULT 1,
  last_run        TEXT,
  generated_count INTEGER DEFAULT 0,
  created_at      TEXT DEFAULT (datetime('now')),
  updated_at      TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_auto_tasks_user ON auto_tasks(user_id);

CREATE TABLE IF NOT EXISTS site_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT DEFAULT '{}',
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS promo_codes (
  code            TEXT PRIMARY KEY,
  discount_percent INTEGER DEFAULT 10,
  max_uses        INTEGER DEFAULT 0,
  used_count      INTEGER DEFAULT 0,
  active          INTEGER DEFAULT 1,
  expires_at      TEXT,
  created_at      TEXT DEFAULT (datetime('now'))
);
