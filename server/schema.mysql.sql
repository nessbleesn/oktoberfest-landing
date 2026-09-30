-- Apply only after an approved backup and a read-only audit of historical leads.
-- This migration does not backfill historical applications.
CREATE TABLE IF NOT EXISTS oktoberfest_campaign_counters (
  campaign_key VARCHAR(64) NOT NULL PRIMARY KEY,
  last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oktoberfest_promos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_key VARCHAR(64) NOT NULL,
  sequence_number BIGINT UNSIGNED NOT NULL,
  promo_code VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL,
  email_normalized VARCHAR(254) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  phone_normalized VARCHAR(20) DEFAULT NULL,
  status ENUM('issued','used') NOT NULL DEFAULT 'issued',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME DEFAULT NULL,
  application_id CHAR(32) NOT NULL,
  idempotency_key VARCHAR(128) DEFAULT NULL,
  personal_consent TINYINT(1) NOT NULL DEFAULT 1,
  personal_consented_at DATETIME DEFAULT NULL,
  marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
  marketing_choice_at DATETIME DEFAULT NULL,
  sync_status ENUM('pending','synced') NOT NULL DEFAULT 'pending',
  sync_version INT UNSIGNED NOT NULL DEFAULT 0,
  sync_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_sync_attempt_at DATETIME DEFAULT NULL,
  synced_at DATETIME DEFAULT NULL,
  bitrix_status ENUM('pending','sending','synced') NOT NULL DEFAULT 'pending',
  bitrix_lead_id BIGINT UNSIGNED DEFAULT NULL,
  bitrix_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  bitrix_claimed_at DATETIME DEFAULT NULL,
  last_bitrix_attempt_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_campaign_sequence (campaign_key, sequence_number),
  UNIQUE KEY uq_campaign_code (campaign_key, promo_code),
  UNIQUE KEY uq_campaign_email (campaign_key, email_normalized),
  UNIQUE KEY uq_campaign_phone (campaign_key, phone_normalized),
  UNIQUE KEY uq_application_id (application_id),
  UNIQUE KEY uq_campaign_idempotency (campaign_key, idempotency_key),
  KEY ix_pending_sync (sync_status, id),
  KEY ix_pending_bitrix (bitrix_status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oktoberfest_request_limits (
  key_hash CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (key_hash, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO oktoberfest_campaign_counters (campaign_key, last_sequence)
VALUES ('oktoberfest', 0)
ON DUPLICATE KEY UPDATE campaign_key = campaign_key;
