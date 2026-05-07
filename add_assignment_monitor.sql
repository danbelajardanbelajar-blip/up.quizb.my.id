-- ============================================
-- Migration: Assignment Live Monitor
-- Jalankan sekali di server: mysql -u user -p dbname < add_assignment_monitor.sql
-- ============================================
CREATE TABLE IF NOT EXISTS `assignment_progress` (
  `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `assignment_id`    INT UNSIGNED      NOT NULL,
  `user_id`          INT UNSIGNED      NOT NULL,
  `started_at`       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `current_question` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `total_questions`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_forced_stop`   TINYINT(1)        NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assign_user` (`assignment_id`, `user_id`),
  KEY `idx_assignment` (`assignment_id`),
  CONSTRAINT `fk_ap_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ap_user`       FOREIGN KEY (`user_id`)       REFERENCES `users`       (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
