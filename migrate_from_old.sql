-- ============================================================
-- MIGRATION SCRIPT: quic1934_quiz_lama → quic1934_upgrade
--
-- CARA PAKAI:
-- 1. Pastikan database baru (quic1934_upgrade) sudah dibuat
--    dan file database.sql sudah diimport ke quic1934_upgrade
-- 2. File quic1934_quizb.sql sudah diimport ke quic1934_quiz_lama
-- 3. Buka phpMyAdmin → pilih database quic1934_upgrade
-- 4. Klik tab SQL → paste & jalankan script ini
-- ============================================================

-- ============================================================
-- STEP 0: Konfigurasi nama database
-- ============================================================
-- DB lama  : quic1934_quiz_lama
-- DB baru  : quic1934_upgrade  (ini yang aktif saat script dijalankan)

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- ============================================================
-- STEP 1: Bersihkan data seed default agar tidak bentrok
-- Gunakan DELETE FROM (bukan TRUNCATE) agar FK tidak masalah di phpMyAdmin
-- Urutan: tabel anak dulu, baru tabel induk
-- ============================================================
DELETE FROM `assignment_submissions`;
DELETE FROM `attempt_answers`;
DELETE FROM `attempts`;
DELETE FROM `options`;
DELETE FROM `questions`;
DELETE FROM `quizzes`;
DELETE FROM `categories`;

-- Reset auto_increment
ALTER TABLE `categories`  AUTO_INCREMENT = 1;
ALTER TABLE `quizzes`     AUTO_INCREMENT = 1;
ALTER TABLE `questions`   AUTO_INCREMENT = 1;
ALTER TABLE `options`     AUTO_INCREMENT = 1;

-- ============================================================
-- STEP 2: Import Categories dari subthemes DB lama
-- subthemes → categories
-- ============================================================
INSERT INTO `categories`
    (`id`, `name`, `slug`, `description`, `icon`, `color`, `quiz_count`, `created_at`)
SELECT
    s.id,
    s.name,
    -- Slug: lowercase, spasi → tanda hubung, buang karakter aneh
    LOWER(REGEXP_REPLACE(
        REGEXP_REPLACE(
            REGEXP_REPLACE(s.name, '[^a-zA-Z0-9 ]', ''),
            ' +', '-'
        ),
        '-+', '-'
    )),
    CONCAT('Kategori: ', s.name, ' (', t.name, ')'),
    -- Ikon berdasarkan tema induk
    CASE t.id
        WHEN 4  THEN '🕌'   -- Pengetahuan Agama
        WHEN 2  THEN '🔬'   -- Pengetahuan Alam
        WHEN 3  THEN '📜'   -- Pengetahuan Sosial
        WHEN 5  THEN '🌍'   -- Pengetahuan Umum
        WHEN 45 THEN '🗣️'   -- Pengetahuan Bahasa
        ELSE '📚'
    END,
    -- Warna berdasarkan tema induk
    CASE t.id
        WHEN 4  THEN '#10b981'   -- hijau teal  (Agama)
        WHEN 2  THEN '#06b6d4'   -- cyan         (Alam)
        WHEN 3  THEN '#f59e0b'   -- amber        (Sosial)
        WHEN 5  THEN '#6366f1'   -- indigo       (Umum)
        WHEN 45 THEN '#8b5cf6'   -- violet       (Bahasa)
        ELSE '#6366f1'
    END,
    0,            -- quiz_count akan diupdate di STEP 7
    s.created_at
FROM quic1934_quiz_lama.subthemes  s
JOIN quic1934_quiz_lama.themes     t ON s.theme_id = t.id
WHERE s.deleted_at IS NULL
ORDER BY s.id;

-- ============================================================
-- STEP 3: Import Quizzes dari quiz_titles DB lama
-- quiz_titles → quizzes
-- ============================================================
INSERT INTO `quizzes`
    (`id`, `category_id`, `title`, `slug`, `description`,
     `duration`, `time_limit`, `difficulty`,
     `total_questions`, `total_attempts`, `passing_score`,
     `max_attempts`, `is_published`, `created_by`, `created_at`)
SELECT
    qt.id,
    qt.subtheme_id,
    qt.title,
    -- Slug unik: judul-id
    CONCAT(
        LOWER(REGEXP_REPLACE(
            REGEXP_REPLACE(
                REGEXP_REPLACE(qt.title, '[^a-zA-Z0-9 ]', ''),
                ' +', '-'
            ),
            '-+', '-'
        )),
        '-', qt.id
    ),
    CONCAT('Paket soal: ', qt.title),
    600,        -- durasi default 10 menit
    600,        -- time_limit (alias duration)
    'medium',   -- difficulty default
    0,          -- total_questions akan diupdate di STEP 6
    0,          -- total_attempts
    60,         -- passing_score 60%
    0,          -- max_attempts 0 = unlimited
    1,          -- is_published = true
    1,          -- created_by = admin (id=1)
    qt.created_at
FROM quic1934_quiz_lama.quiz_titles qt
WHERE qt.deleted_at IS NULL
  AND qt.subtheme_id IN (SELECT id FROM `categories`)
ORDER BY qt.id;

-- ============================================================
-- STEP 4: Import Questions dari questions DB lama
-- questions → questions
-- ============================================================
INSERT INTO `questions`
    (`id`, `quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`)
SELECT
    q.id,
    q.title_id,
    q.text,
    'multiple',   -- semua diimport sebagai multiple (2-5 pilihan ditampilkan dinamis)
    10,           -- 10 poin per soal
    q.id,         -- order_num sementara = id, akan dirapikan di STEP 8
    q.explanation
FROM quic1934_quiz_lama.questions q
WHERE q.title_id IN (SELECT id FROM `quizzes`)
ORDER BY q.id;

-- ============================================================
-- STEP 5: Import Options dari choices DB lama
-- choices → options (support 2–5 pilihan dinamis)
-- ============================================================
-- Gunakan variabel session untuk row_number per question
SET @rn       = 0;
SET @prev_qid = 0;

INSERT INTO `options`
    (`id`, `question_id`, `option_text`, `is_correct`, `order_num`)
SELECT
    c.id,
    c.question_id,
    c.text,
    c.is_correct,
    @rn := IF(@prev_qid = c.question_id, @rn + 1, 1) AS order_num
FROM (
    -- Subquery agar ORDER BY terjamin sebelum variabel dihitung
    SELECT c2.id, c2.question_id, c2.text, c2.is_correct
    FROM quic1934_quiz_lama.choices c2
    JOIN `questions` q ON c2.question_id = q.id
    ORDER BY c2.question_id, c2.id
) c,
(SELECT @prev_qid := c3.question_id
 FROM quic1934_quiz_lama.choices c3
 ORDER BY c3.question_id, c3.id
 LIMIT 1) AS init_prev
WHERE (@prev_qid := c.question_id) IS NOT NULL
  OR TRUE;

-- ============================================================
-- Alternatif lebih sederhana jika query di atas gagal:
-- (hapus komentar pada blok ini dan komentari STEP 5 di atas)
-- ============================================================
-- INSERT INTO `options` (`id`, `question_id`, `option_text`, `is_correct`, `order_num`)
-- SELECT c.id, c.question_id, c.text, c.is_correct,
--        ROW_NUMBER() OVER (PARTITION BY c.question_id ORDER BY c.id) AS order_num
-- FROM quic1934_quiz_lama.choices c
-- JOIN `questions` q ON c.question_id = q.id;

-- ============================================================
-- STEP 6: Update total_questions pada tabel quizzes
-- ============================================================
UPDATE `quizzes` q
SET q.`total_questions` = (
    SELECT COUNT(*) FROM `questions` WHERE quiz_id = q.id
);

-- ============================================================
-- STEP 7: Update quiz_count pada tabel categories
-- ============================================================
UPDATE `categories` c
SET c.`quiz_count` = (
    SELECT COUNT(*) FROM `quizzes` WHERE category_id = c.id AND total_questions > 0
);

-- ============================================================
-- STEP 8: Rapikan order_num questions per quiz
-- ============================================================
SET @prev_quiz := 0;
SET @ord       := 0;

UPDATE `questions` q
JOIN (
    SELECT id,
           @ord := IF(@prev_quiz = quiz_id, @ord + 1, 1) AS new_order,
           @prev_quiz := quiz_id AS dummy
    FROM `questions`
    ORDER BY quiz_id, id
) ranked ON q.id = ranked.id
SET q.order_num = ranked.new_order;

-- ============================================================
-- STEP 9: Hapus quizzes yang tidak punya soal (opsional)
-- Komentari jika ingin menyimpan quiz kosong
-- ============================================================
DELETE FROM `quizzes` WHERE total_questions = 0;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- STEP 10: Verifikasi hasil migrasi
-- ============================================================
SELECT '========== MIGRATION SUMMARY ==========' AS info;

SELECT
    (SELECT COUNT(*) FROM `categories`)                   AS total_categories,
    (SELECT COUNT(*) FROM `quizzes`)                      AS total_quizzes,
    (SELECT COUNT(*) FROM `questions`)                    AS total_questions,
    (SELECT COUNT(*) FROM `options`)                      AS total_options,
    (SELECT COUNT(*) FROM `quizzes` WHERE total_questions > 0) AS quizzes_with_questions;

SELECT '== Quizzes per kategori ==' AS info;
SELECT c.name AS kategori, COUNT(q.id) AS jumlah_quiz
FROM `categories` c
LEFT JOIN `quizzes` q ON q.category_id = c.id
GROUP BY c.id, c.name
ORDER BY jumlah_quiz DESC;

SELECT '== Distribusi jumlah pilihan per soal ==' AS info;
SELECT
    pilihan_count,
    COUNT(*) AS jumlah_soal
FROM (
    SELECT question_id, COUNT(*) AS pilihan_count
    FROM `options`
    GROUP BY question_id
) sub
GROUP BY pilihan_count
ORDER BY pilihan_count;
