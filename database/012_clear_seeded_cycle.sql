-- ============================================================================
-- 012_clear_seeded_cycle.sql
--
-- Two corrections to academic_cycles.
--
-- 1. schema.sql used to seed an active "2025-2026" cycle. A cycle is something
--    the coordinator starts when they are ready, so a fresh install should not
--    come up with one already running. schema.sql no longer creates it; this
--    stands down the one already in your database.
--
-- 2. Cancelling a cycle set current_state to CANCELLED but left is_active at 1,
--    so a cancelled cycle still read as Active and still came back as the cycle
--    in charge. Cancelling now stands the cycle down as well; this fixes any
--    cycle already cancelled.
--
-- Nothing is deleted. The cycles stay on record with their history — they are
-- only marked as no longer running.
-- ============================================================================

USE `internship_system`;

-- Before.
SELECT id, name, current_state, is_active FROM `academic_cycles` ORDER BY id;

-- A cancelled cycle is not the cycle the system is running.
UPDATE `academic_cycles`
SET `is_active` = 0
WHERE `current_state` = 'CANCELLED';

-- Stand down the cycle that came from the old schema seed, so the coordinator
-- starts the first real one themselves. Remove this statement if you want to
-- keep using it.
UPDATE `academic_cycles`
SET `is_active` = 0
WHERE `name` = '2025-2026';

-- After. is_active should be 0 everywhere, or 1 on just the one cycle you
-- intend to run.
SELECT id, name, current_state, is_active FROM `academic_cycles` ORDER BY id;

SELECT COUNT(*) AS cycles_running FROM `academic_cycles` WHERE `is_active` = 1;