-- ============================================
-- Migration: Multiple Question Packages per Assignment
-- ============================================

-- Tabel junction untuk assignment_quizzes (many-to-many)
CREATE TABLE IF NOT EXISTS `assignment_quiz_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` INT UNSIGNED NOT NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `order_index` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Urutan paket soal dalam assignment',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assign_quiz` (`assignment_id`, `quiz_id`),
  KEY `idx_quiz` (`quiz_id`),
  CONSTRAINT `fk_aqp_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aqp_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing data dari assignments.quiz_id ke assignment_quiz_packages
INSERT INTO `assignment_quiz_packages` (assignment_id, quiz_id, order_index)
SELECT id, quiz_id, 0 FROM assignments
WHERE quiz_id IS NOT NULL AND quiz_id > 0
ON DUPLICATE KEY UPDATE order_index = 0;
