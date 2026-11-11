<?php
// Shared helper functions used across multiple pages.

/**
 * Returns the id of the single active academic cycle, or null if none.
 * Every cycle-scoped write (preferences, rankings) must stamp this so the
 * cycle FSM integrity checks — which filter WHERE cycle_id = ? — can see the data.
 */
function activeCycleId(PDO $db): ?int {
    $stmt = $db->query("SELECT id FROM academic_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Current state of the active cycle, or null when there is no active cycle.
 */
function cycleState(PDO $db): ?string {
    $stmt = $db->query(
        "SELECT current_state FROM academic_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
    );
    $state = $stmt->fetchColumn();
    return $state !== false ? $state : null;
}

/**
 * May students see the offer list at all?
 *
 * Nothing is visible until the coordinator opens applications. Before that the
 * cycle is still being prepared — offers are being reviewed and corrected — so
 * showing them would advertise things that may still change or be rejected.
 *
 * From the moment applications open the list stays visible for the rest of the
 * cycle, so a student can always look back at what they applied to, even once
 * applying itself has closed.
 */
function studentsCanBrowse(PDO $db): bool {
    $state = cycleState($db);
    return $state !== null && $state !== 'DRAFT' && $state !== 'CANCELLED';
}

/**
 * May students still apply and change their preference order?
 *
 * Only while applications are open. Once the coordinator closes them the
 * student side becomes view-only: the matching runs on the applications and
 * preferences as they stood at that moment, so allowing either to change
 * afterwards would mean matching against data nobody agreed to.
 */
function studentsCanApply(PDO $db): bool {
    return cycleState($db) === 'APPLICATIONS_OPEN';
}

/**
 * Recomputes internship_offers.filled from the allocations that actually exist.
 *
 *     filled = number of allocations on this offer with decision = 'accepted'
 *
 * `filled` used to be a running counter, incremented in two places and never
 * decremented anywhere. Publishing a round added one per matched student, and
 * the student accepting added one for the same seat again, so every seat was
 * counted twice. A rejection or a timeout left the count untouched, so the seat
 * was consumed permanently. Offers reported themselves full before anyone had
 * responded, and `check_unclaimed_offers_exist` — which gates the second
 * matching round on `capacity - filled > 0` — could never pass again.
 *
 * Deriving the number removes the whole class of problem: there is no counter
 * to drift, rejections and timeouts free their seat automatically because the
 * row stops being 'accepted', and running this twice changes nothing.
 *
 * Only a seat someone has ACCEPTED counts as taken. A pending allocation is an
 * offer awaiting an answer, not an occupied seat. This is safe because the
 * cycle FSM converts every remaining 'pending' to 'timed_out' when the decision
 * window closes, which happens before a second round can run — so no allocation
 * is still undecided at the point this number gates anything.
 *
 * @param int|null $offerId Recompute one offer, or every offer when null.
 */
function recomputeOfferFilled(PDO $db, ?int $offerId = null): void {
    $sql = "
        UPDATE internship_offers o
        SET o.filled = (
            SELECT COUNT(*)
            FROM allocations a
            WHERE a.offer_id = o.id
              AND a.decision = 'accepted'
        )
    ";
    if ($offerId !== null) {
        $stmt = $db->prepare($sql . " WHERE o.id = ?");
        $stmt->execute([$offerId]);
    } else {
        $db->exec($sql);
    }

    // Keep the open/closed flag consistent with the number just written. Only
    // these two states are touched: an offer the coordinator archived, or one
    // still in draft, must not be dragged back open by a seat freeing up.
    $statusSql = "
        UPDATE internship_offers o
        SET o.status = CASE
            WHEN o.filled >= o.capacity THEN 'closed'
            ELSE 'open'
        END
        WHERE o.status IN ('open', 'closed')
    ";
    if ($offerId !== null) {
        $stmt = $db->prepare($statusSql . " AND o.id = ?");
        $stmt->execute([$offerId]);
    } else {
        $db->exec($statusSql);
    }
}