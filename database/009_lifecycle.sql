-- ============================================================================
-- 009b_fix_workflow_token_columns.sql
--
-- The big ALTER in 009_lifecycle.sql does three things at once. If 009 was run
-- before, the columns already exist, so re-running it fails with
-- "Duplicate column name" and phpMyAdmin stops the whole file there.
--
-- Here the same three things are three separate statements. Run them ONE AT A
-- TIME. If one says the column or key already exists, that part is already
-- done — skip it and run the next one.
-- ============================================================================

USE `internship_system`;

ALTER TABLE `workflow_states`
    ADD COLUMN `correlation_token` CHAR(32) DEFAULT NULL AFTER `allocation_id`;

ALTER TABLE `workflow_states`
    ADD COLUMN `spawned_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;

ALTER TABLE `workflow_states`
    ADD UNIQUE KEY `uq_token` (`correlation_token`);

-- Check: all three should be listed.
SHOW COLUMNS FROM `workflow_states`;