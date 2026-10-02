-- Apply once after a production backup and a separate release approval.
-- Nullable columns preserve existing promo records; no historical UTM backfill.
-- Apply before deploying lib.php, otherwise new submissions cannot be saved.
ALTER TABLE oktoberfest_promos
  ADD COLUMN utm_source VARCHAR(255) DEFAULT NULL AFTER marketing_choice_at,
  ADD COLUMN utm_medium VARCHAR(255) DEFAULT NULL AFTER utm_source,
  ADD COLUMN utm_campaign VARCHAR(255) DEFAULT NULL AFTER utm_medium,
  ADD COLUMN utm_content VARCHAR(255) DEFAULT NULL AFTER utm_campaign,
  ADD COLUMN utm_term VARCHAR(255) DEFAULT NULL AFTER utm_content;
