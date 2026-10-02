-- Test schema only; production uses schema.mysql.sql.
CREATE TABLE oktoberfest_campaign_counters (campaign_key TEXT PRIMARY KEY, last_sequence INTEGER NOT NULL DEFAULT 0);
CREATE TABLE oktoberfest_promos (
  id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_key TEXT NOT NULL, sequence_number INTEGER NOT NULL,
  promo_code TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL,
  email_normalized TEXT NOT NULL, phone TEXT, phone_normalized TEXT,
  status TEXT NOT NULL DEFAULT 'issued', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at TEXT, application_id TEXT NOT NULL UNIQUE, idempotency_key TEXT,
  personal_consent INTEGER NOT NULL DEFAULT 1, personal_consented_at TEXT,
  marketing_consent INTEGER NOT NULL DEFAULT 0, marketing_choice_at TEXT,
  utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
  sync_status TEXT NOT NULL DEFAULT 'pending',
  sync_version INTEGER NOT NULL DEFAULT 0,
  sync_attempts INTEGER NOT NULL DEFAULT 0, last_sync_attempt_at TEXT, synced_at TEXT,
  bitrix_status TEXT NOT NULL DEFAULT 'pending', bitrix_lead_id INTEGER,
  bitrix_attempts INTEGER NOT NULL DEFAULT 0, bitrix_claimed_at TEXT, last_bitrix_attempt_at TEXT,
  UNIQUE(campaign_key, sequence_number), UNIQUE(campaign_key, promo_code),
  UNIQUE(campaign_key, email_normalized), UNIQUE(campaign_key, phone_normalized),
  UNIQUE(campaign_key, idempotency_key)
);
CREATE TABLE oktoberfest_request_limits (key_hash TEXT NOT NULL, window_start TEXT NOT NULL, request_count INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(key_hash, window_start));
INSERT INTO oktoberfest_campaign_counters VALUES ('oktoberfest', 0);
