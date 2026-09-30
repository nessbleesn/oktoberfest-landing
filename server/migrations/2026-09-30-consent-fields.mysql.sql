-- Review and apply once, only after a production backup and explicit approval.
-- Historical requests already passed server-side personal_consent=true validation.
-- Their exact consent timestamps are unknown and remain NULL; no codes are changed.
ALTER TABLE oktoberfest_promos
  ADD COLUMN personal_consent TINYINT(1) NOT NULL DEFAULT 1 AFTER idempotency_key,
  ADD COLUMN personal_consented_at DATETIME DEFAULT NULL AFTER personal_consent,
  ADD COLUMN marketing_choice_at DATETIME DEFAULT NULL AFTER marketing_consent;
