USE `internship_system`;

-- ============================================================
-- 1. Add registration_status to students
-- ============================================================
ALTER TABLE `students`
  ADD COLUMN `registration_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `user_id`,
  ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `registration_status`,
  ADD COLUMN `approved_by_user_id` INT UNSIGNED DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN `approved_at` DATETIME DEFAULT NULL AFTER `approved_by_user_id`,
  ADD CONSTRAINT `fk_student_approver` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Existing seeded student rows (if any) should be marked approved so tests keep working
UPDATE `students` SET `registration_status` = 'approved' WHERE `registration_status` = 'pending';

-- ============================================================
-- 2. Academic records table (replaces students.completed_exams TEXT)
-- ============================================================
CREATE TABLE `academic_records` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`           INT UNSIGNED NOT NULL,
  `exam_name`            VARCHAR(200) NOT NULL,
  `cfu`                  INT UNSIGNED NOT NULL,
  `grade`                VARCHAR(10) DEFAULT NULL,
  `exam_date`            DATE DEFAULT NULL,
  `verified_by_user_id`  INT UNSIGNED DEFAULT NULL,
  `verified_at`          DATETIME DEFAULT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_student_verified` (`student_id`, `verified_by_user_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`verified_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 3. In-app notifications already exist in base schema — no change needed
-- ============================================================