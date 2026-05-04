-- ============================================
-- QuizB Platform — Database Schema + Seed Data
-- Compatible: MySQL 5.7+ / MariaDB 10.3+
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+07:00";

-- Drop existing tables (order matters for FK)
DROP TABLE IF EXISTS `attempt_answers`;
DROP TABLE IF EXISTS `attempts`;
DROP TABLE IF EXISTS `options`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `quizzes`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;

-- ============================================
-- TABLE: users
-- ============================================
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('user','admin') NOT NULL DEFAULT 'user',
  `avatar` VARCHAR(255) DEFAULT NULL,
  `total_points` INT UNSIGNED NOT NULL DEFAULT 0,
  `quizzes_taken` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: categories
-- ============================================
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `icon` VARCHAR(10) DEFAULT '📚',
  `color` VARCHAR(20) DEFAULT '#6366f1',
  `quiz_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: quizzes
-- ============================================
CREATE TABLE `quizzes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `duration` SMALLINT UNSIGNED NOT NULL DEFAULT 600 COMMENT 'Seconds',
  `difficulty` ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
  `total_questions` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `total_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_slug` (`slug`),
  KEY `idx_published` (`is_published`),
  CONSTRAINT `fk_quiz_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quiz_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: questions
-- ============================================
CREATE TABLE `questions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id` INT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `type` ENUM('multiple','true_false') NOT NULL DEFAULT 'multiple',
  `points` TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `order_num` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `explanation` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_quiz` (`quiz_id`),
  CONSTRAINT `fk_question_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: options
-- ============================================
CREATE TABLE `options` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` INT UNSIGNED NOT NULL,
  `option_text` VARCHAR(500) NOT NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `order_num` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_question` (`question_id`),
  CONSTRAINT `fk_option_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: attempts
-- ============================================
CREATE TABLE `attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `total_points` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `correct_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `time_taken` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Seconds',
  `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_quiz` (`quiz_id`),
  KEY `idx_score` (`score` DESC),
  CONSTRAINT `fk_attempt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: attempt_answers
-- ============================================
CREATE TABLE `attempt_answers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED DEFAULT NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_attempt` (`attempt_id`),
  CONSTRAINT `fk_answer_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_answer_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- VIEW: leaderboard
-- ============================================
CREATE OR REPLACE VIEW `v_leaderboard` AS
  SELECT
    u.id AS user_id,
    u.name,
    u.avatar,
    SUM(a.score) AS total_score,
    COUNT(DISTINCT a.id) AS total_attempts,
    ROUND(AVG(a.score), 1) AS avg_score,
    MAX(a.completed_at) AS last_attempt
  FROM `users` u
  INNER JOIN `attempts` a ON a.user_id = u.id
  WHERE u.is_active = 1
  GROUP BY u.id, u.name, u.avatar
  ORDER BY total_score DESC;

-- ============================================
-- SEED: Users
-- Passwords: admin123 / user123
-- ============================================
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `total_points`, `quizzes_taken`) VALUES
('Administrator', 'admin@quizb.my.id', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 0, 0),
('Budi Santoso', 'budi@example.com', '$2y$12$TKh8H1.PfuMIj8fCPUxB3uBaWIWniPDGP2M0wYcSWYE5VfG2VBLfe', 'user', 280, 4),
('Siti Rahayu', 'siti@example.com', '$2y$12$TKh8H1.PfuMIj8fCPUxB3uBaWIWniPDGP2M0wYcSWYE5VfG2VBLfe', 'user', 190, 3),
('Andi Wijaya', 'andi@example.com', '$2y$12$TKh8H1.PfuMIj8fCPUxB3uBaWIWniPDGP2M0wYcSWYE5VfG2VBLfe', 'user', 350, 5);

-- ============================================
-- SEED: Categories
-- ============================================
INSERT INTO `categories` (`name`, `slug`, `description`, `icon`, `color`, `quiz_count`) VALUES
('Matematika', 'matematika', 'Soal-soal matematika dari dasar hingga tingkat lanjut', '🔢', '#6366f1', 2),
('Sains & IPA', 'sains-ipa', 'Biologi, Fisika, Kimia dan ilmu pengetahuan alam', '🔬', '#06b6d4', 2),
('Sejarah', 'sejarah', 'Sejarah Indonesia dan dunia', '📜', '#f59e0b', 1),
('Bahasa Indonesia', 'bahasa-indonesia', 'Tata bahasa, sastra dan kosakata', '📝', '#10b981', 1),
('Teknologi & IT', 'teknologi-it', 'Pemrograman, jaringan dan teknologi informasi', '💻', '#8b5cf6', 1),
('Pengetahuan Umum', 'pengetahuan-umum', 'Wawasan umum dan trivia sehari-hari', '🌍', '#ef4444', 1);

-- ============================================
-- SEED: Quizzes
-- ============================================
INSERT INTO `quizzes` (`category_id`, `title`, `slug`, `description`, `duration`, `difficulty`, `total_questions`, `total_attempts`, `is_published`, `created_by`) VALUES
(1, 'Matematika Dasar SD', 'matematika-dasar-sd', 'Uji kemampuan matematika dasar penjumlahan, pengurangan, perkalian dan pembagian', 600, 'easy', 5, 12, 1, 1),
(1, 'Aljabar SMP', 'aljabar-smp', 'Soal aljabar tingkat SMP persamaan linear dan kuadrat', 900, 'medium', 5, 8, 1, 1),
(2, 'Biologi Sel', 'biologi-sel', 'Pengetahuan tentang sel, organel dan fungsinya', 720, 'medium', 5, 6, 1, 1),
(2, 'Fisika Mekanika', 'fisika-mekanika', 'Hukum Newton, gerak, dan energi mekanik', 900, 'hard', 5, 4, 1, 1),
(3, 'Sejarah Kemerdekaan RI', 'sejarah-kemerdekaan-ri', 'Perjuangan kemerdekaan Indonesia 1945', 600, 'easy', 5, 15, 1, 1),
(4, 'EYD dan Tata Bahasa', 'eyd-tata-bahasa', 'Ejaan yang Disempurnakan dan tata bahasa Indonesia', 720, 'medium', 5, 7, 1, 1),
(5, 'Dasar Pemrograman', 'dasar-pemrograman', 'Konsep dasar algoritma, variabel, dan logika pemrograman', 900, 'medium', 5, 9, 1, 1),
(6, 'Trivia Indonesia', 'trivia-indonesia', 'Fakta menarik dan pengetahuan umum tentang Indonesia', 600, 'easy', 5, 20, 1, 1);

-- ============================================
-- SEED: Questions & Options — Quiz 1 (Matematika Dasar)
-- ============================================
INSERT INTO `questions` (`quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`) VALUES
(1, 'Berapa hasil dari 125 + 278?', 'multiple', 10, 1, '125 + 278 = 403'),
(1, 'Hasil dari 15 × 8 adalah...', 'multiple', 10, 2, '15 × 8 = 120'),
(1, 'Berapakah 256 ÷ 16?', 'multiple', 10, 3, '256 ÷ 16 = 16'),
(1, 'Apakah 7 × 7 = 49?', 'true_false', 10, 4, '7 × 7 = 49, benar'),
(1, 'Hasil dari 1000 - 387 adalah...', 'multiple', 10, 5, '1000 - 387 = 613');

INSERT INTO `options` (`question_id`, `option_text`, `is_correct`, `order_num`) VALUES
(1, '401', 0, 1), (1, '403', 1, 2), (1, '413', 0, 3), (1, '423', 0, 4),
(2, '100', 0, 1), (2, '110', 0, 2), (2, '120', 1, 3), (2, '130', 0, 4),
(3, '14', 0, 1), (3, '15', 0, 2), (3, '16', 1, 3), (3, '17', 0, 4),
(4, 'Benar', 1, 1), (4, 'Salah', 0, 2),
(5, '603', 0, 1), (5, '613', 1, 2), (5, '623', 0, 3), (5, '633', 0, 4);

-- ============================================
-- SEED: Questions & Options — Quiz 5 (Sejarah Kemerdekaan)
-- ============================================
INSERT INTO `questions` (`quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`) VALUES
(5, 'Pada tanggal berapa Indonesia memproklamasikan kemerdekaan?', 'multiple', 10, 1, 'Indonesia merdeka pada 17 Agustus 1945'),
(5, 'Siapakah yang membacakan teks Proklamasi Kemerdekaan Indonesia?', 'multiple', 10, 2, 'Ir. Soekarno membacakan teks Proklamasi'),
(5, 'Di mana naskah Proklamasi dibacakan?', 'multiple', 10, 3, 'Dibacakan di Jalan Pegangsaan Timur No. 56, Jakarta'),
(5, 'Bendera Merah Putih pertama kali dikibarkan oleh Suhud dan Latief Hendraningrat', 'true_false', 10, 4, 'Benar, keduanya adalah pengibar bendera pertama'),
(5, 'Siapa yang mengetik naskah Proklamasi Kemerdekaan?', 'multiple', 10, 5, 'Sayuti Melik yang mengetik naskah Proklamasi');

INSERT INTO `options` (`question_id`, `option_text`, `is_correct`, `order_num`) VALUES
(6, '15 Agustus 1945', 0, 1), (6, '16 Agustus 1945', 0, 2), (6, '17 Agustus 1945', 1, 3), (6, '18 Agustus 1945', 0, 4),
(7, 'Mohammad Hatta', 0, 1), (7, 'Ir. Soekarno', 1, 2), (7, 'Sayuti Melik', 0, 3), (7, 'Achmad Soebardjo', 0, 4),
(8, 'Istana Merdeka', 0, 1), (8, 'Lapangan Ikada', 0, 2), (8, 'Jl. Pegangsaan Timur No. 56', 1, 3), (8, 'Gedung Proklamasi', 0, 4),
(9, 'Benar', 1, 1), (9, 'Salah', 0, 2),
(10, 'Mohammad Hatta', 0, 1), (10, 'Achmad Soebardjo', 0, 2), (10, 'Sayuti Melik', 1, 3), (10, 'Bung Tomo', 0, 4);

-- ============================================
-- SEED: Questions & Options — Quiz 7 (Dasar Pemrograman)
-- ============================================
INSERT INTO `questions` (`quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`) VALUES
(7, 'Apa kepanjangan dari CPU?', 'multiple', 10, 1, 'CPU = Central Processing Unit'),
(7, 'Bahasa pemrograman apa yang dikenal sebagai bahasa ibu dari bahasa pemrograman modern?', 'multiple', 10, 2, 'C adalah bahasa yang menjadi dasar banyak bahasa modern'),
(7, 'Apakah HTML termasuk bahasa pemrograman?', 'true_false', 10, 3, 'HTML adalah markup language, bukan bahasa pemrograman'),
(7, 'Apa yang dimaksud dengan algoritma?', 'multiple', 10, 4, 'Algoritma adalah langkah-langkah sistematis untuk menyelesaikan masalah'),
(7, 'Struktur data apa yang bekerja dengan prinsip LIFO (Last In First Out)?', 'multiple', 10, 5, 'Stack bekerja dengan prinsip LIFO');

INSERT INTO `options` (`question_id`, `option_text`, `is_correct`, `order_num`) VALUES
(11, 'Computer Processing Unit', 0, 1), (11, 'Central Processing Unit', 1, 2), (11, 'Core Processing Unit', 0, 3), (11, 'Central Program Unit', 0, 4),
(12, 'Python', 0, 1), (12, 'Java', 0, 2), (12, 'C', 1, 3), (12, 'Assembly', 0, 4),
(13, 'Benar', 0, 1), (13, 'Salah', 1, 2),
(14, 'Program komputer', 0, 1), (14, 'Langkah sistematis menyelesaikan masalah', 1, 2), (14, 'Bahasa mesin', 0, 3), (14, 'Kode biner', 0, 4),
(15, 'Queue', 0, 1), (15, 'Array', 0, 2), (15, 'Stack', 1, 3), (15, 'Linked List', 0, 4);

-- ============================================
-- SEED: Questions & Options — Quiz 8 (Trivia Indonesia)
-- ============================================
INSERT INTO `questions` (`quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`) VALUES
(8, 'Apa ibukota Indonesia saat ini (2024)?', 'multiple', 10, 1, 'Secara resmi Jakarta masih ibukota, Nusantara masih dalam transisi'),
(8, 'Pulau terbesar di Indonesia adalah...', 'multiple', 10, 2, 'Kalimantan adalah pulau terbesar di Indonesia'),
(8, 'Indonesia memiliki lebih dari 17.000 pulau', 'true_false', 10, 3, 'Benar, Indonesia memiliki sekitar 17.504 pulau'),
(8, 'Gunung tertinggi di Indonesia adalah...', 'multiple', 10, 4, 'Puncak Jaya (Carstensz Pyramid) adalah yang tertinggi di 4.884 mdpl'),
(8, 'Bahasa resmi Indonesia adalah...', 'multiple', 10, 5, 'Bahasa Indonesia adalah bahasa resmi negara');

INSERT INTO `options` (`question_id`, `option_text`, `is_correct`, `order_num`) VALUES
(16, 'Nusantara', 0, 1), (16, 'Surabaya', 0, 2), (16, 'Jakarta', 1, 3), (16, 'Bandung', 0, 4),
(17, 'Sumatera', 0, 1), (17, 'Kalimantan', 1, 2), (17, 'Papua', 0, 3), (17, 'Jawa', 0, 4),
(18, 'Benar', 1, 1), (18, 'Salah', 0, 2),
(19, 'Semeru', 0, 1), (19, 'Rinjani', 0, 2), (19, 'Puncak Jaya', 1, 3), (19, 'Kerinci', 0, 4),
(20, 'Bahasa Jawa', 0, 1), (20, 'Bahasa Melayu', 0, 2), (20, 'Bahasa Indonesia', 1, 3), (20, 'Bahasa Sunda', 0, 4);

-- ============================================
-- SEED: Sample Attempts (Demo History)
-- ============================================
INSERT INTO `attempts` (`user_id`, `quiz_id`, `score`, `total_points`, `correct_count`, `time_taken`) VALUES
(2, 1, 80, 50, 4, 342),
(2, 5, 100, 50, 5, 280),
(2, 7, 70, 50, 3, 510),
(2, 8, 90, 50, 4, 195),
(3, 1, 60, 50, 3, 455),
(3, 5, 80, 50, 4, 320),
(3, 8, 100, 50, 5, 210),
(4, 1, 100, 50, 5, 298),
(4, 5, 90, 50, 4, 310),
(4, 7, 80, 50, 4, 480),
(4, 8, 70, 50, 3, 350),
(4, 8, 90, 50, 4, 280);

-- Update user stats
UPDATE `users` SET `total_points` = 280, `quizzes_taken` = 4 WHERE `id` = 2;
UPDATE `users` SET `total_points` = 190, `quizzes_taken` = 3 WHERE `id` = 3;
UPDATE `users` SET `total_points` = 350, `quizzes_taken` = 5 WHERE `id` = 4;
