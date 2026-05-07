-- ============================================================
-- Migration: Tambah kolom mode ke tabel attempts
-- Jalankan sekali di database production
-- ============================================================

-- 1. Tambah kolom mode ke tabel attempts (jika belum ada)
ALTER TABLE `attempts`
  ADD COLUMN IF NOT EXISTS `mode`
    ENUM('exam','instant','end','challenge') NOT NULL DEFAULT 'exam'
    COMMENT 'Mode quiz: exam, instant, end, atau challenge'
    AFTER `quiz_id`;

-- 2. Verifikasi
SELECT
  COLUMN_NAME,
  COLUMN_TYPE,
  COLUMN_DEFAULT,
  COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'attempts'
  AND COLUMN_NAME  = 'mode';
