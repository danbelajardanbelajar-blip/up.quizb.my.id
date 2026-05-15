-- ============================================
-- Migration: Tambah kolom email verification
-- Jalankan sekali di database quic1934_upgrade
-- ============================================

ALTER TABLE `users`
  ADD COLUMN `email_verification_token` VARCHAR(64) DEFAULT NULL AFTER `is_active`,
  ADD COLUMN `email_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `email_verification_token`,
  ADD INDEX `idx_email_verification_token` (`email_verification_token`);

-- User lama (sebelum fitur ini) anggap sudah verified
UPDATE `users`
SET `email_verified_at` = `created_at`
WHERE `is_active` = 1 AND `email_verified_at` IS NULL;
