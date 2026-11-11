<?php
require_once __DIR__ . '/engine_runner.php';

/**
 * ============================================================================
 * AUTOMATIC APPLICATIONS AND RANKING DEFAULTS
 * ============================================================================
 * Makes sure that, for the cycle being matched:
 *
 *   - every active student has applications,
 *   - every student with applications has a ranked preference list,
 *   - every offer with applicants has them ALL ranked.
 *
 * Nothing is left empty, so nothing can be dropped by the matcher.
 *
 * Why this exists
 * ---------------
 * The matcher reads ONLY `student_preferences` and `offer_rankings`. The NLP
 * engines write to different tables (`recommendation_scores` and
 * `applicant_rankings`), and the only bridge between them used to be a "load
 * from recommendations" button a human had to click. Anyone who never clicked
 * it had no rows at all, and `load_matching_data()` in the matching engine
 * leaves out a student who has no preference rows - they are not ranked last,
 * they are absent from the allocation entirely.
 *
 * The rule
 * --------
 *   manual beats recommendation, always.
 *
 * For applications: one application a student made themselves is enough to
 * mean "this student chose for themselves". We then use only what they applied
 * to and add nothing - and anything the engine had added for them earlier is
 * removed, so their own choices are the whole list.
 *
 * For ranking: if a student saved their own ordering (source='manual') their
 * list is left completely untouched. Same for an offer whose poster ranked
 * applicants by hand.
 *
 * Students apply to a few offers; offers rank everyone
 * ----------------------------------------------------
 * A student who applied to nothing gets AUTO_APPLY_LIMIT applications, which
 * is how a real student behaves - they pick a few, not everything. An offer,
 * on the other hand, ranks EVERY applicant it has. If an offer left applicants
 * out, the matcher would have no opinion to use when those students proposed
 * to it, and they would be silently rejected.
 *
 * Ordering within a generated list
 * --------------------------------
 *   1. Entries the NLP engine scored, best rank first.
 *   2. Anything left over, in the order it was applied for.
 *
 * The second part matters. `recommendation_scores` only holds offers the
 * student is ELIGIBLE for, so an application to an offer the student does not
 * qualify for is scored nowhere. Without the fallback those applications would
 * vanish from the preference list. Application order is the honest tie-break:
 * it is what the student did.
 *
 * Everything below is scoped to one cycle. A cycle never sees another cycle's
 * applications, preferences or rankings.
 * ============================================================================
 */

/**
 * How many offers the engine applies to for a student who applied to nothing.
 * Kept here as one number so it can be changed in one place.
 */
if (!defined('AUTO_APPLY_LIMIT')) {
    define('AUTO_APPLY_LIMIT', 5);
}

/**
 * Fill in missing applications, preference lists and applicant rankings for
 * one cycle.
 *
 * Safe to run repeatedly. Each run refreshes generated rows and leaves manual
 * rows alone, so a coordinator can run matching twice without wiping anyone's
 * choices.
 *
 * @return array Summary for display: what was generated, what was respected.
 */
function applyRecommendationDefaults(PDO $db, ?int $cycleId): array
{
    $summary = [
        'students_total'             => 0,
        'students_applied_themselves'=> 0,
        'students_auto_applied'      => 0,
        'students_no_eligible_offers'=> 0,
        'students_generated'         => 0,
        'students_manual'            => 0,
        'students_no_data'           => 0,
        'offers_generated'           => 0,
        'offers_manual'              => 0,
        'offers_no_data'             => 0,
        'engine_failures'            => [],
    ];

    // Without a cycle there is nothing to scope anything to, and writing rows
    // with a NULL cycle is what caused the leak between cycles in the first
    // place. Say so instead of writing bad data.
    if ($cycleId === null) {
        $summary['engine_failures'][] = 'No active cycle — nothing was generated.';
        return $summary;
    }

    // ------------------------------------------------------------------
    // STEP 1 — students who applied to nothing get applications made for them
    // ------------------------------------------------------------------
    $allStudents = $db->query("
        SELECT s.id
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE u.role = 'student' AND u.is_active = 1
    ")->fetchAll();

    $summary['students_total'] = count($allStudents);

    $countManualApps = $db->prepare("
        SELECT COUNT(*) FROM applications
        WHERE student_id = ? AND cycle_id = ?
          AND source = 'manual' AND status <> 'withdrawn'
    ");
    $countScores = $db->prepare("SELECT COUNT(*) FROM recommendation_scores WHERE student_id = ?");

    // Best-scored eligible offers first. `rank_position IS NULL` sorts 0 before
    // 1, so scored rows lead, and score breaks any remaining tie.
    $topOffers = $db->prepare("
        SELECT rs.offer_id
        FROM recommendation_scores rs
        JOIN internship_offers o ON o.id = rs.offer_id
        WHERE rs.student_id = ? AND o.status = 'open'
        ORDER BY (rs.rank_position IS NULL) ASC,
                 rs.rank_position ASC,
                 rs.score DESC
        LIMIT " . (int)AUTO_APPLY_LIMIT
    );

    $clearAutoApps = $db->prepare("
        DELETE FROM applications
        WHERE student_id = ? AND cycle_id = ? AND source = 'recommendation'
    ");
    $insertApp = $db->prepare("
        INSERT INTO applications (student_id, offer_id, cycle_id, source, status, cover_letter)
        VALUES (?, ?, ?, 'recommendation', 'pending',
                'Submitted from the recommendation ranking because no manual application was made for this cycle.')
    ");

    foreach ($allStudents as $row) {
        $studentId = (int)$row['id'];

        $countManualApps->execute([$studentId, $cycleId]);
        if ((int)$countManualApps->fetchColumn() > 0) {
            // They chose for themselves. Remove anything the engine added for
            // them earlier, so only their own applications count — one manual
            // application means the system uses only those.
            $clearAutoApps->execute([$studentId, $cycleId]);
            $summary['students_applied_themselves']++;
            continue;
        }

        // The scoring engine runs lazily elsewhere (on profile save, on first
        // visit to the offers list), so a student who never browsed may have no
        // scores yet. Generate them now.
        $countScores->execute([$studentId]);
        if ((int)$countScores->fetchColumn() === 0) {
            $res = EngineRunner::run('recommendation', ['student-id' => $studentId]);
            if (!$res['success']) {
                $summary['engine_failures'][] = "recommendation (student $studentId): " . $res['error'];
            }
        }

        $topOffers->execute([$studentId]);
        $offerIds = array_map('intval', array_column($topOffers->fetchAll(), 'offer_id'));
        $offerIds = array_values(array_unique($offerIds));

        if (empty($offerIds)) {
            // This student is eligible for nothing that is open. Counted and
            // reported, never hidden.
            $summary['students_no_eligible_offers']++;
            continue;
        }

        $clearAutoApps->execute([$studentId, $cycleId]);
        foreach ($offerIds as $offerId) {
            $insertApp->execute([$studentId, $offerId, $cycleId]);
        }
        $summary['students_auto_applied']++;
    }

    // ------------------------------------------------------------------
    // STEP 2 — students -> student_preferences
    // ------------------------------------------------------------------
    $students = $db->prepare("
        SELECT DISTINCT a.student_id
        FROM applications a
        JOIN internship_offers o ON o.id = a.offer_id
        WHERE a.cycle_id = ?
          AND a.status <> 'withdrawn'
          AND o.status = 'open'
    ");
    $students->execute([$cycleId]);
    $students = $students->fetchAll();

    $hasManualPref = $db->prepare("
        SELECT COUNT(*) FROM student_preferences
        WHERE student_id = ? AND cycle_id = ? AND source = 'manual'
    ");

    // Recommendation order first, then anything unscored by application order.
    $orderedOffers = $db->prepare("
        SELECT a.offer_id
        FROM applications a
        JOIN internship_offers o ON o.id = a.offer_id
        LEFT JOIN recommendation_scores rs
               ON rs.student_id = a.student_id AND rs.offer_id = a.offer_id
        WHERE a.student_id = ?
          AND a.cycle_id = ?
          AND a.status <> 'withdrawn'
          AND o.status = 'open'
        ORDER BY (rs.rank_position IS NULL) ASC,
                 rs.rank_position ASC,
                 a.applied_at ASC,
                 a.id ASC
    ");

    $clearGenerated = $db->prepare("
        DELETE FROM student_preferences
        WHERE student_id = ? AND cycle_id = ? AND source <> 'manual'
    ");
    $insertPref = $db->prepare("
        INSERT INTO student_preferences (student_id, offer_id, `rank`, cycle_id, source)
        VALUES (?, ?, ?, ?, 'recommendation')
    ");

    foreach ($students as $s) {
        $studentId = (int)$s['student_id'];

        $hasManualPref->execute([$studentId, $cycleId]);
        if ((int)$hasManualPref->fetchColumn() > 0) {
            $summary['students_manual']++;
            continue;                       // the student chose; leave it alone
        }

        $orderedOffers->execute([$studentId, $cycleId]);
        $offerIds = array_map('intval', array_column($orderedOffers->fetchAll(), 'offer_id'));
        $offerIds = array_values(array_unique($offerIds));

        if (empty($offerIds)) {
            $summary['students_no_data']++;
            continue;
        }

        $clearGenerated->execute([$studentId, $cycleId]);
        $rank = 1;
        foreach ($offerIds as $offerId) {
            $insertPref->execute([$studentId, $offerId, $rank++, $cycleId]);
        }
        $summary['students_generated']++;
    }

    // ------------------------------------------------------------------
    // STEP 3 — offers -> offer_rankings (every applicant, not a top few)
    // ------------------------------------------------------------------
    $offers = $db->prepare("
        SELECT DISTINCT o.id
        FROM internship_offers o
        JOIN applications a
          ON a.offer_id = o.id AND a.status <> 'withdrawn' AND a.cycle_id = ?
        WHERE o.status = 'open'
    ");
    $offers->execute([$cycleId]);
    $offers = $offers->fetchAll();

    $hasManualRank = $db->prepare("
        SELECT COUNT(*) FROM offer_rankings
        WHERE offer_id = ? AND cycle_id = ? AND source = 'manual'
    ");
    $countApplicantRanks = $db->prepare("SELECT COUNT(*) FROM applicant_rankings WHERE offer_id = ?");

    $orderedApplicants = $db->prepare("
        SELECT a.student_id
        FROM applications a
        LEFT JOIN applicant_rankings ar
               ON ar.offer_id = a.offer_id AND ar.student_id = a.student_id
        WHERE a.offer_id = ?
          AND a.cycle_id = ?
          AND a.status <> 'withdrawn'
        ORDER BY (ar.rank_position IS NULL) ASC,
                 ar.rank_position ASC,
                 a.applied_at ASC,
                 a.id ASC
    ");

    $clearGeneratedRanks = $db->prepare("
        DELETE FROM offer_rankings
        WHERE offer_id = ? AND cycle_id = ? AND source <> 'manual'
    ");
    $insertRank = $db->prepare("
        INSERT INTO offer_rankings (offer_id, student_id, `rank`, cycle_id, source)
        VALUES (?, ?, ?, ?, 'recommendation')
    ");

    foreach ($offers as $o) {
        $offerId = (int)$o['id'];

        $hasManualRank->execute([$offerId, $cycleId]);
        if ((int)$hasManualRank->fetchColumn() > 0) {
            $summary['offers_manual']++;
            continue;                       // the poster chose; leave it alone
        }

        // The applicant-ranking engine scores this offer's applicants. It runs
        // here if it has never run for this offer. New applicants added after
        // that are still ranked — they fall in by application order below.
        $countApplicantRanks->execute([$offerId]);
        if ((int)$countApplicantRanks->fetchColumn() === 0) {
            $res = EngineRunner::run('applicant_ranking', ['offer-id' => $offerId]);
            if (!$res['success']) {
                $summary['engine_failures'][] = "applicant_ranking (offer $offerId): " . $res['error'];
            }
        }

        $orderedApplicants->execute([$offerId, $cycleId]);
        $studentIds = array_map('intval', array_column($orderedApplicants->fetchAll(), 'student_id'));
        $studentIds = array_values(array_unique($studentIds));

        if (empty($studentIds)) {
            $summary['offers_no_data']++;
            continue;
        }

        $clearGeneratedRanks->execute([$offerId, $cycleId]);
        $rank = 1;
        foreach ($studentIds as $studentId) {
            $insertRank->execute([$offerId, $studentId, $rank++, $cycleId]);
        }
        $summary['offers_generated']++;
    }

    return $summary;
}

/** One-line human summary of what applyRecommendationDefaults() did. */
function describeRecommendationDefaults(array $s): string
{
    $parts = [];
    $parts[] = $s['students_total'] . ' student(s) checked';
    if ($s['students_applied_themselves']) $parts[] = $s['students_applied_themselves'] . ' applied themselves';
    if ($s['students_auto_applied'])       $parts[] = $s['students_auto_applied'] . ' had ' . AUTO_APPLY_LIMIT . ' application(s) generated';
    $parts[] = $s['students_generated'] . ' preference list(s) generated';
    if ($s['students_manual'])             $parts[] = $s['students_manual'] . ' kept as manually ranked';
    $parts[] = $s['offers_generated'] . ' offer ranking(s) generated';
    if ($s['offers_manual'])               $parts[] = $s['offers_manual'] . ' kept as manually ranked';

    $text = implode(', ', $parts) . '.';

    // Anyone the engine could not place is named here rather than quietly
    // left out of the matching.
    if ($s['students_no_eligible_offers']) {
        $text .= ' ' . $s['students_no_eligible_offers']
              . ' student(s) are eligible for no open offer and will not be matched.';
    }
    if ($s['students_no_data']) {
        $text .= ' ' . $s['students_no_data']
              . ' student(s) had applications but no open offer left to rank.';
    }

    return $text;
}
