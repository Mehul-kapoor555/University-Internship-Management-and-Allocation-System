-- ============================================================
-- 013: tie applications, preferences and rankings to ONE cycle
-- ============================================================
-- The problem this fixes
-- ---------------------
-- `applications` had no cycle column. An application was just "this student
-- applied to this offer" - forever. So a brand new cycle inherited every
-- application ever made, and students saw "already applied" and a filled-in
-- preference list on offers they never touched in this cycle.
--
-- The UNIQUE keys on all three tables also stopped at (student, offer), which
-- meant a student could never apply to - or rank - the same offer again in a
-- later cycle. They are widened to include the cycle.
--
-- `source` is added to applications for the same reason it exists on
-- preferences and rankings: it tells a student's own application apart from
-- one the recommendation engine made on their behalf. Manual always wins.
--
-- Note on the order below: the new UNIQUE key is always added BEFORE the old
-- one is dropped. Those old keys are what MySQL uses to police the foreign
-- keys on student_id / offer_id, and dropping one while it is the only index
-- covering a foreign key fails with "Cannot drop index ... needed in a
-- foreign key constraint" (error 1553). Adding the wider key first - it starts
-- with the same column - keeps a usable index in place the whole time.
--
-- Run this once.
-- ============================================================


-- ------------------------------------------------------------
-- 1. applications: add the cycle and the source
-- ------------------------------------------------------------
ALTER TABLE `applications`
  ADD COLUMN `cycle_id` INT UNSIGNED DEFAULT NULL AFTER `offer_id`;

ALTER TABLE `applications`
  ADD COLUMN `source` ENUM('manual','recommendation') NOT NULL DEFAULT 'manual' AFTER `cycle_id`;

ALTER TABLE `applications`
  ADD UNIQUE KEY `uq_student_offer_cycle` (`student_id`, `offer_id`, `cycle_id`);

ALTER TABLE `applications` DROP INDEX `uq_student_offer`;

ALTER TABLE `applications`
  ADD INDEX `idx_app_cycle` (`cycle_id`);


-- ------------------------------------------------------------
-- 2. preferences and rankings: widen their unique keys the same way
-- ------------------------------------------------------------
ALTER TABLE `student_preferences`
  ADD UNIQUE KEY `uq_student_pref_cycle` (`student_id`, `offer_id`, `cycle_id`);

ALTER TABLE `student_preferences` DROP INDEX `uq_student_pref`;

ALTER TABLE `offer_rankings`
  ADD UNIQUE KEY `uq_offer_rank_cycle` (`offer_id`, `student_id`, `cycle_id`);

ALTER TABLE `offer_rankings` DROP INDEX `uq_offer_rank`;


-- ------------------------------------------------------------
-- 3. Clear what the old seed left behind
-- ------------------------------------------------------------
-- These rows belong to no cycle, and because the seed stamped them 'manual'
-- the recommendation engine treated them as deliberate student choices and
-- refused to replace them. They are exactly what was showing up in the new
-- cycle. Deleting them gives every new cycle a clean start.
--
-- Deleting the applications also clears their applicant_rankings rows, which
-- reference applications with ON DELETE CASCADE.
DELETE FROM `student_preferences` WHERE `cycle_id` IS NULL;

DELETE FROM `offer_rankings` WHERE `cycle_id` IS NULL;

DELETE FROM `applications` WHERE `cycle_id` IS NULL;
