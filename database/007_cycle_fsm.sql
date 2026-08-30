-- ============================================================================
-- 007_cycle_fsm.sql
-- Adds the cycle lifecycle FSM: current_state + round counter on academic_cycles,
-- plus a full audit table for every transition.
-- ============================================================================

USE `internship_system`;

-- ---------- Cycle state columns ----------
ALTER TABLE `academic_cycles`
    ADD COLUMN `current_state` VARCHAR(64) NOT NULL DEFAULT 'DRAFT'
        AFTER `is_active`,
    ADD COLUMN `current_round` TINYINT UNSIGNED NOT NULL DEFAULT 1
        AFTER `current_state`,
    ADD COLUMN `applications_deadline` DATETIME DEFAULT NULL
        AFTER `end_date`,
    ADD COLUMN `ranking_deadline` DATETIME DEFAULT NULL
        AFTER `applications_deadline`,
    ADD COLUMN `decision_deadline` DATETIME DEFAULT NULL
        AFTER `ranking_deadline`;

-- ---------- Audit trail for every state transition ----------
CREATE TABLE `cycle_state_transitions` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cycle_id`       INT UNSIGNED NOT NULL,
    `from_state`     VARCHAR(64)  NOT NULL,
    `to_state`       VARCHAR(64)  NOT NULL,
    `round_number`   TINYINT UNSIGNED NOT NULL,
    `transition_key` VARCHAR(64)  NOT NULL,
    `triggered_by_user_id` INT UNSIGNED DEFAULT NULL,
    `guard_result`   ENUM('passed','failed','overridden') NOT NULL,
    `notes`          TEXT DEFAULT NULL,
    `transitioned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cycle_time` (`cycle_id`, `transitioned_at`),
    FOREIGN KEY (`cycle_id`) REFERENCES `academic_cycles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Post-decision tracking on allocations ----------
-- Track whether students accepted, rejected, or timed out
ALTER TABLE `allocations`
    ADD COLUMN `decision`         ENUM('pending','accepted','rejected','timed_out') NOT NULL DEFAULT 'pending' AFTER `status`,
    ADD COLUMN `decision_at`      DATETIME DEFAULT NULL AFTER `decision`,
    ADD COLUMN `round_number`     TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `round_id`;

-- Existing active cycle: set it to DRAFT so the FSM takes over
UPDATE `academic_cycles`
   SET `current_state` = 'DRAFT', `current_round` = 1
 WHERE `is_active` = 1;