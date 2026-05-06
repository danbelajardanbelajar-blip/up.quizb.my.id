-- ============================================
-- add_challenges.sql — Mode Tantang (Challenge)
-- Jalankan sekali di database production
-- ============================================

CREATE TABLE IF NOT EXISTS `challenges` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id`                INT UNSIGNED NOT NULL,
  `challenger_id`          INT UNSIGNED NOT NULL,
  `challenged_id`          INT UNSIGNED NOT NULL,
  `status`                 ENUM('pending','playing','completed','declined','expired')
                           NOT NULL DEFAULT 'pending',
  `challenger_attempt_id`  INT UNSIGNED DEFAULT NULL,
  `challenged_attempt_id`  INT UNSIGNED DEFAULT NULL,
  `winner_id`              INT UNSIGNED DEFAULT NULL,
  `message`                VARCHAR(255) DEFAULT NULL,
  `expires_at`             TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 24 HOUR),
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_challenged_status` (`challenged_id`, `status`),
  KEY `idx_challenger`        (`challenger_id`),
  KEY `idx_quiz`              (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
