-- ============================================================
-- Migration: Tambah pengaturan timer ke tabel users
-- timer_per_question : detik per soal (instant/end review)
-- exam_duration_minutes : durasi exam dalam menit (NULL = ikuti quiz)
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN `timer_per_question`    SMALLINT UNSIGNED NOT NULL DEFAULT 20
    COMMENT 'Detik per soal untuk instant/end review (default 20)'
    AFTER `shuffle_options`,
  ADD COLUMN `exam_duration_minutes` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Durasi ujian dalam menit untuk mode exam; NULL = ikuti durasi quiz'
    AFTER `timer_per_question`;

-- Cek idempotent
SELECT
  IF(COUNT(*) > 0, 'Kolom sudah ada', 'Kolom berhasil ditambahkan') AS status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'users'
  AND COLUMN_NAME IN ('timer_per_question', 'exam_duration_minutes');
