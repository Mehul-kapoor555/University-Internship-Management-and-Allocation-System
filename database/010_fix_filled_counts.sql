-- ============================================================================
-- 010_fix_filled_counts.sql
--
-- Fixes internship_offers.filled.
--
-- filled used to be added to in two places and never taken back down, so seats
-- were counted twice and rejected seats were never released. The code now
-- works it out instead of counting up. This fixes the rows already in the
-- database, using the same rule:
--
--     filled = how many students accepted this offer
--
-- Safe to run more than once.
-- Needs 007_cycle_fsm.sql to have run (it adds allocations.decision).
-- ============================================================================

USE `internship_system`;

-- 1. Recount the seats on every offer.
UPDATE `internship_offers` o
SET o.filled = (
    SELECT COUNT(*) FROM `allocations` a
    WHERE a.offer_id = o.id AND a.decision = 'accepted'
);

-- 2. An offer is closed when its seats are gone, open otherwise.
--    Draft and archived offers are left alone.
UPDATE `internship_offers`
SET `status` = IF(`filled` >= `capacity`, 'closed', 'open')
WHERE `status` IN ('open', 'closed');

-- 3. Same problem on the professor side.
UPDATE `professors` p
SET p.current_load = (
    SELECT COUNT(*) FROM `supervisor_assignments` sa
    JOIN `allocations` a ON a.id = sa.allocation_id
    WHERE sa.professor_id = p.id AND a.decision = 'accepted'
);

-- Check: seats_free is what the second matching round looks at.
SELECT id, title, capacity, filled, (capacity - filled) AS seats_free, status
FROM `internship_offers`
ORDER BY id;