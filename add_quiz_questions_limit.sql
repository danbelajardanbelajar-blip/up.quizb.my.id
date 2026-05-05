-- ============================================================
-- Migration: Tambah fitur jumlah soal per quiz (quiz_questions_limit)
-- Jalankan script ini di database MySQL/MariaDB Anda
-- ============================================================

-- 1. Tambah kolom quiz_questions_limit ke tabel users
--    DEFAULT 10 = default global jumlah soal yang tampil per quiz
ALTER TABLE `users`
  ADD COLUMN `quiz_questions_limit` TINYINT UNSIGNED NOT NULL DEFAULT 10
  COMMENT 'Jumlah soal yang tampil per quiz untuk user ini (1-100). 0 = tampilkan semua.'
  AFTER `quizzes_taken`;

-- 2. Verifikasi kolom berhasil ditambahkan
SELECT
  COLUMN_NAME,
  COLUMN_TYPE,
  COLUMN_DEFAULT,
  COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'users'
  AND COLUMN_NAME = 'quiz_questions_limit';

-- ============================================================
-- Catatan:
--  - Kolom max_questions di tabel assignments sudah ada
--    (NULL = ikuti setting user, angka = override untuk tugas tsb)
--  - Prioritas: assignment.max_questions > user.quiz_questions_limit > default (10)
-- ============================================================
