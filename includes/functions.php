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
