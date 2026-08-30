USE `internship_system`;

-- Provenance columns: track whether preferences were auto-generated or manually set
ALTER TABLE `student_preferences`
  ADD COLUMN `source` ENUM('manual','recommendation') NOT NULL DEFAULT 'manual' AFTER `cycle_id`,
  ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `offer_rankings`
  ADD COLUMN `source` ENUM('manual','recommendation') NOT NULL DEFAULT 'manual' AFTER `cycle_id`,
  ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Matching round trace log — every run leaves an auditable trail
CREATE TABLE `matching_trace` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `round_id`       INT UNSIGNED NOT NULL,
  `step_number`    INT UNSIGNED NOT NULL,
  `student_id`     INT UNSIGNED NOT NULL,
  `offer_id`       INT UNSIGNED NOT NULL,
  `outcome`        ENUM('accepted','bumped','rejected_full','rejected_unranked') NOT NULL,
  `bumped_student_id` INT UNSIGNED DEFAULT NULL,
  INDEX `idx_round_step` (`round_id`, `step_number`),
  FOREIGN KEY (`round_id`) REFERENCES `matching_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`) REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;