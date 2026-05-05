-- ============================================================
-- Migration: Tambah fitur acak soal & pilihan jawaban
-- Jalankan script ini di database MySQL/MariaDB Anda
-- ============================================================

-- 1. Tambah kolom shuffle ke tabel users
--    shuffle_questions = 1 → urutan soal diacak (default: YA)
--    shuffle_options   = 1 → urutan pilihan jawaban diacak (default: YA)
ALTER TABLE `users`
  ADD COLUMN `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Acak urutan soal saat quiz dimulai (1=ya, 0=tidak)'
  AFTER `quiz_questions_limit`,
  ADD COLUMN `shuffle_options` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Acak urutan pilihan jawaban saat quiz dimulai (1=ya, 0=tidak)'
  AFTER `shuffle_questions`;

-- 2. Tambah kolom shuffle ke tabel assignments
--    NULL = ikuti setting user/default
--    0    = paksa TIDAK acak untuk semua pelajar di tugas ini
--    1    = paksa acak untuk semua pelajar di tugas ini
ALTER TABLE `assignments`
  ADD COLUMN `shuffle_questions` TINYINT(1) DEFAULT NULL
  COMMENT 'Override acak soal untuk tugas ini (NULL=ikuti user, 1=paksa acak, 0=paksa berurutan)'
  AFTER `max_questions`,
  ADD COLUMN `shuffle_options` TINYINT(1) DEFAULT NULL
  COMMENT 'Override acak jawaban untuk tugas ini (NULL=ikuti user, 1=paksa acak, 0=paksa berurutan)'
  AFTER `shuffle_questions`;

-- 3. Verifikasi
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_NAME IN ('users', 'assignments')
  AND COLUMN_NAME IN ('shuffle_questions', 'shuffle_options')
ORDER BY TABLE_NAME, COLUMN_NAME;

-- ============================================================
-- Catatan prioritas:
--  assignment.shuffle_questions/options (jika tidak NULL)
--    > user.shuffle_questions/options
--    > default: acak (1)
-- ============================================================
