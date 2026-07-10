-- =========================================================
-- MIGRATION: whatsapp worker support columns
-- Run this once against your existing bespoke_tailor database:
--   mysql -u your_user -p bespoke_tailor < config/migration_whatsapp_worker.sql
-- Safe to run even if you already loaded schema.sql — these are
-- new columns, nothing existing is touched.
-- =========================================================

USE bespoke_tailor;

ALTER TABLE whatsapp_notifications
    ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN last_error VARCHAR(255) NULL AFTER attempts,
    ADD COLUMN twilio_sid VARCHAR(64) NULL AFTER last_error;
