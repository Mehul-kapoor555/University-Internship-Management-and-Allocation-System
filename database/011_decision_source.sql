-- ============================================================================
-- 011_decision_source.sql
--
-- Records WHO made each accept/reject decision.
--
-- This project is demonstrated with more students than can realistically be
-- logged in and clicked through by hand, so when the coordinator closes the
-- decision window the remaining undecided students are given a simulated
-- answer. That is a demo aid, and a simulated acceptance must never be
-- mistaken for a real one — on screen, in the report, or in the oral exam.
--
-- Without this column a simulated decision is indistinguishable from a
-- student's own, which is exactly the thing to avoid. One column makes it
-- visible everywhere the decisions are listed.
--
--   student    the student clicked accept or reject themselves
--   simulated  generated when the coordinator closed the decision window
--
-- NULL means no decision has been made yet.
-- ============================================================================

USE `internship_system`;
SET SQL_SAFE_UPDATES = 0;

ALTER TABLE `allocations`
    ADD COLUMN `decision_source` ENUM('student','simulated') DEFAULT NULL AFTER `decision`;

-- Anything already decided before this column existed was decided by hand.
UPDATE `allocations`
SET `decision_source` = 'student'
WHERE `decision` IN ('accepted', 'rejected');

-- Check.
SELECT `decision`, `decision_source`, COUNT(*) AS n
FROM `allocations`
GROUP BY `decision`, `decision_source`;