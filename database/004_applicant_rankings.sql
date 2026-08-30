USE `internship_system`;

CREATE TABLE `applicant_rankings` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `offer_id`        INT UNSIGNED NOT NULL,
  `application_id`  INT UNSIGNED NOT NULL,
  `student_id`      INT UNSIGNED NOT NULL,
  `semantic_score`  DECIMAL(6,5) NOT NULL,
  `coverage_score`  DECIMAL(6,4) NOT NULL,
  `academic_score`  DECIMAL(6,4) NOT NULL,
  `recency_score`   DECIMAL(6,4) NOT NULL,
  `final_score`     DECIMAL(6,4) NOT NULL,
  `rank_position`   INT UNSIGNED NOT NULL,
  `explanation`     TEXT,
  `computed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_offer_app` (`offer_id`, `application_id`),
  INDEX `idx_offer_rank` (`offer_id`, `rank_position`),
  FOREIGN KEY (`offer_id`) REFERENCES `internship_offers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;