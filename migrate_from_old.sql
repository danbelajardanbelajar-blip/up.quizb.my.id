-- ============================================================
-- MIGRATION SCRIPT: quizb.my.id (lama) → up.quizb.my.id (baru)
-- 
-- CARA PAKAI:
-- 1. Pastikan database baru sudah dibuat dan database.sql sudah diimport
-- 2. Import file quic1934_quizb.sql ke database SEMENTARA misal: quizb_old
-- 3. Ubah nama database sesuai kebutuhan di bagian bawah
-- 4. Jalankan script ini di phpMyAdmin (pilih DB baru sebagai target)
-- ============================================================

-- ============================================================
-- STEP 0: Konfigurasi
-- Ganti nama database lama sesuai yang ada di server kamu
-- ============================================================
SET @OLD_DB = 'quic1934_quizb';  -- <-- Ganti ini dengan nama DB lama di servermu

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- ============================================================
-- STEP 1: Import Categories dari subthemes DB lama
-- subthemes → categories (lebih spesifik, lebih berguna)
-- ============================================================

-- Hapus seed data default dulu agar tidak bentrok
TRUNCATE TABLE `categories`;
TRUNCATE TABLE `quizzes`;
TRUNCATE TABLE `questions`;
TRUNCATE TABLE `options`;

-- Reset auto_increment
ALTER TABLE `categories` AUTO_INCREMENT = 1;
ALTER TABLE `quizzes` AUTO_INCREMENT = 1;
ALTER TABLE `questions` AUTO_INCREMENT = 1;
ALTER TABLE `options` AUTO_INCREMENT = 1;

-- Insert categories dari subthemes (yang tidak dihapus / deleted_at IS NULL)
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `icon`, `color`, `quiz_count`, `created_at`)
SELECT 
    s.id,
    s.name,
    LOWER(REGEXP_REPLACE(
        REGEXP_REPLACE(
            REGEXP_REPLACE(s.name, '[^a-zA-Z0-9 ]', ''),
            ' +', '-'
        ),
        '-+', '-'
    )),
    CONCAT('Kategori: ', s.name, ' (', t.name, ')'),
    CASE t.id
        WHEN 4 THEN '🕌'   -- Pengetahuan Agama
        WHEN 2 THEN '🔬'   -- Pengetahuan Alam
        WHEN 3 THEN '📜'   -- Pengetahuan Sosial
        WHEN 5 THEN '🌍'   -- Pengetahuan Umum
        WHEN 45 THEN '🗣️'  -- Pengetahuan Bahasa
        ELSE '📚'
    END,
    CASE t.id
        WHEN 4 THEN '#10b981'   -- hijau teal (Agama)
        WHEN 2 THEN '#06b6d4'   -- cyan (Alam)
        WHEN 3 THEN '#f59e0b'   -- amber (Sosial)
        WHEN 5 THEN '#6366f1'   -- indigo (Umum)
        WHEN 45 THEN '#8b5cf6'  -- violet (Bahasa)
        ELSE '#6366f1'
    END,
    0,  -- quiz_count akan diupdate nanti
    s.created_at
FROM quic1934_quizb.subthemes s
JOIN quic1934_quizb.themes t ON s.theme_id = t.id
WHERE s.deleted_at IS NULL
ORDER BY s.id;

-- ============================================================
-- STEP 2: Import Quizzes dari quiz_titles DB lama
-- quiz_titles → quizzes
-- ============================================================
INSERT INTO `quizzes` (`id`, `category_id`, `title`, `slug`, `description`, `duration`, 
                        `difficulty`, `total_questions`, `total_attempts`, `is_published`, 
                        `created_by`, `created_at`)
SELECT 
    qt.id,
    qt.subtheme_id,
    qt.title,
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
    600,        -- default 10 menit
    'medium',   -- default medium
    0,          -- akan diupdate dari questions
    0,          -- total_attempts
    1,          -- is_published = true (sudah dipakai)
    1,          -- created_by = admin (id=1)
    qt.created_at
FROM quic1934_quizb.quiz_titles qt
WHERE qt.deleted_at IS NULL
  AND qt.subtheme_id IN (SELECT id FROM `categories`)
ORDER BY qt.id;

-- ============================================================
-- STEP 3: Import Questions dari questions DB lama
-- questions → questions
-- ============================================================
INSERT INTO `questions` (`id`, `quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`)
SELECT 
    q.id,
    q.title_id,
    q.text,
    -- Deteksi tipe: jika hanya punya 2 choices, bisa jadi true_false atau multiple
    CASE 
        WHEN (SELECT COUNT(*) FROM quic1934_quizb.choices c WHERE c.question_id = q.id) <= 2 
        THEN 'multiple'  -- tetap multiple, frontend akan menyesuaikan
        ELSE 'multiple'
    END,
    10,  -- default 10 poin per soal
    q.id,  -- pakai id sebagai order_num (akan dirapikan)
    q.explanation
FROM quic1934_quizb.questions q
WHERE q.title_id IN (SELECT id FROM `quizzes`)
ORDER BY q.id;

-- ============================================================
-- STEP 4: Import Options dari choices DB lama
-- choices → options (support 2-5 pilihan)
-- ============================================================
INSERT INTO `options` (`id`, `question_id`, `option_text`, `is_correct`, `order_num`)
SELECT 
    c.id,
    c.question_id,
    c.text,
    c.is_correct,
    -- Buat order_num berdasarkan urutan insertion per question
    (@rn := IF(@prev_qid = c.question_id, @rn + 1, 1)) AS order_num,
    @prev_qid := c.question_id
FROM quic1934_quizb.choices c
JOIN `questions` q ON c.question_id = q.id
CROSS JOIN (SELECT @rn := 0, @prev_qid := 0) AS init
ORDER BY c.question_id, c.id;

-- ============================================================
-- STEP 5: Update total_questions pada tabel quizzes
-- ============================================================
UPDATE `quizzes` q
SET q.`total_questions` = (
    SELECT COUNT(*) FROM `questions` WHERE quiz_id = q.id
);

-- ============================================================
-- STEP 6: Update quiz_count pada tabel categories
-- ============================================================
UPDATE `categories` c
SET c.`quiz_count` = (
    SELECT COUNT(*) FROM `quizzes` WHERE category_id = c.id
);

-- ============================================================
-- STEP 7: Rapikan order_num questions per quiz (opsional)
-- ============================================================
SET @prev_quiz := 0;
SET @ord := 0;

UPDATE `questions` q
JOIN (
    SELECT id,
           @ord := IF(@prev_quiz = quiz_id, @ord + 1, 1) AS new_order,
           @prev_quiz := quiz_id AS dummy
    FROM `questions`
    ORDER BY quiz_id, id
) ranked ON q.id = ranked.id
SET q.order_num = ranked.new_order;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- STEP 8: Verifikasi hasil migrasi
-- ============================================================
SELECT 'MIGRATION SUMMARY' AS info;
SELECT COUNT(*) AS total_categories FROM `categories`;
SELECT COUNT(*) AS total_quizzes FROM `quizzes`;
SELECT COUNT(*) AS total_questions FROM `questions`;
SELECT COUNT(*) AS total_options FROM `options`;
SELECT COUNT(*) AS quizzes_with_questions FROM `quizzes` WHERE total_questions > 0;

-- Cek quiz tanpa soal (perlu perhatian)
SELECT COUNT(*) AS quizzes_without_questions FROM `quizzes` WHERE total_questions = 0;
