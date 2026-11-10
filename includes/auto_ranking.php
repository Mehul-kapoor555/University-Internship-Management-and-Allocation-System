<?php
require_once __DIR__ . '/engine_runner.php';

/**
 * ============================================================================
 * AUTOMATIC RANKING DEFAULTS
 * ============================================================================
 * Gives every applicant a preference list and every offer an applicant ranking,
 * derived from the NLP scoring engines, without anyone having to press a button.
 *
 * Why this exists
 * ---------------
 * The matcher reads ONLY `student_preferences` and `offer_rankings`. The NLP
 * engines write to different tables (`recommendation_scores` and
 * `applicant_rankings`), and until now the only bridge between them was a
 * "load from recommendations" button a human had to click. Anyone who never
 * clicked it had no rows at all, and `load_matching_data()` in the matching
 * engine silently drops a student who has no preference rows — they are not
 * ranked last, they are absent from the allocation entirely.
 *
 * The `source ENUM('manual','recommendation')` column added in migration 005
 * shows this was always the intent: the algorithmic order is the DEFAULT, and a
 * human choice OVERRIDES it. This file supplies the missing default.
 *
 * The rule
 * --------
 *   manual beats recommendation, always.
 *
 * If a student has saved their own ordering (source='manual'), their list is
 * left completely untouched — including not appending offers they applied to
 * afterwards, because silently editing someone's deliberate ordering is worse
 * than leaving it short. The same rule applies to an offer whose poster ranked
 * applicants by hand.
 *
 * Ordering within a generated list
 * --------------------------------
 *   1. Entries the NLP engine scored, best rank first.
 *   2. Anything left over, in the order it was applied for.
 *
 * The second part matters. `recommendation_scores` only holds offers the
 * student is ELIGIBLE for, so an application to an offer the student does not
 * qualify for is scored nowhere. Without the fallback those applications would
 * vanish from the preference list and the student could end up with an empty
 * list despite having applied — exactly the failure this file exists to stop.
 * Application order is the honest tie-break: it is what the student did.
 * ============================================================================
 */

/**
 * Fill in missing preference lists and applicant rankings for one cycle.
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
        'students_generated'   => 0,
        'students_manual'      => 0,
        'students_no_data'     => 0,
        'offers_generated'     => 0,
        'offers_manual'        => 0,
        'offers_no_data'       => 0,
        'engine_failures'      => [],
    ];

    // ------------------------------------------------------------------
    // STUDENTS -> student_preferences
    // ------------------------------------------------------------------
    $students = $db->query("
        SELECT DISTINCT a.student_id
        FROM applications a
        JOIN internship_offers o ON o.id = a.offer_id
        WHERE a.status <> 'withdrawn'
          AND o.status = 'open'
    ")->fetchAll();

    $hasManualPref = $db->prepare("
        SELECT COUNT(*) FROM student_preferences
        WHERE student_id = ? AND source = 'manual'
    ");
    $countScores = $db->prepare("SELECT COUNT(*) FROM recommendation_scores WHERE student_id = ?");

    // Recommendation order first, then anything unscored by application order.
    // `rs.rank_position IS NULL` sorts 0 before 1, so scored rows lead.
    $orderedOffers = $db->prepare("
        SELECT a.offer_id
        FROM applications a
        JOIN internship_offers o ON o.id = a.offer_id
        LEFT JOIN recommendation_scores rs
               ON rs.student_id = a.student_id AND rs.offer_id = a.offer_id
        WHERE a.student_id = ?
          AND a.status <> 'withdrawn'
          AND o.status = 'open'
        ORDER BY (rs.rank_position IS NULL) ASC,
                 rs.rank_position ASC,
                 a.applied_at ASC,
                 a.id ASC
    ");

    $clearGenerated = $db->prepare("
        DELETE FROM student_preferences WHERE student_id = ? AND source <> 'manual'
    ");
    $insertPref = $db->prepare("
        INSERT INTO student_preferences (student_id, offer_id, `rank`, cycle_id, source)
        VALUES (?, ?, ?, ?, 'recommendation')
    ");

    foreach ($students as $s) {
        $studentId = (int)$s['student_id'];

        $hasManualPref->execute([$studentId]);
        if ((int)$hasManualPref->fetchColumn() > 0) {
            $summary['students_manual']++;
            continue;                       // the student chose; leave it alone
        }

        // The scoring engine runs lazily elsewhere (on profile save, on first
        // visit to the offers list), so a student who never browsed may have no
        // scores yet. Generate them now rather than falling back to raw
        // application order for someone the engine could have ranked properly.
        $countScores->execute([$studentId]);
        if ((int)$countScores->fetchColumn() === 0) {
            $res = EngineRunner::run('recommendation', ['student-id' => $studentId]);
            if (!$res['success']) {
                // Not fatal. The ordering below still works, it just falls back
                // to application order for this student.
                $summary['engine_failures'][] = "recommendation (student $studentId): " . $res['error'];
            }
        }

        $orderedOffers->execute([$studentId]);
        $offerIds = array_map('intval', array_column($orderedOffers->fetchAll(), 'offer_id'));
        $offerIds = array_values(array_unique($offerIds));

        if (empty($offerIds)) {
            $summary['students_no_data']++;
            continue;
        }

        $clearGenerated->execute([$studentId]);
        $rank = 1;
        foreach ($offerIds as $offerId) {
            $insertPref->execute([$studentId, $offerId, $rank++, $cycleId]);
        }
        $summary['students_generated']++;
    }

    // ------------------------------------------------------------------
    // OFFERS -> offer_rankings
    // ------------------------------------------------------------------
    $offers = $db->query("
        SELECT DISTINCT o.id
        FROM internship_offers o
        JOIN applications a ON a.offer_id = o.id AND a.status <> 'withdrawn'
        WHERE o.status = 'open'
    ")->fetchAll();

    $hasManualRank = $db->prepare("
        SELECT COUNT(*) FROM offer_rankings
        WHERE offer_id = ? AND source = 'manual'
    ");
    $countApplicantRanks = $db->prepare("SELECT COUNT(*) FROM applicant_rankings WHERE offer_id = ?");

    $orderedApplicants = $db->prepare("
        SELECT a.student_id
        FROM applications a
        LEFT JOIN applicant_rankings ar
               ON ar.offer_id = a.offer_id AND ar.student_id = a.student_id
        WHERE a.offer_id = ?
          AND a.status <> 'withdrawn'
        ORDER BY (ar.rank_position IS NULL) ASC,
                 ar.rank_position ASC,
                 a.applied_at ASC,
                 a.id ASC
    ");

    $clearGeneratedRanks = $db->prepare("
        DELETE FROM offer_rankings WHERE offer_id = ? AND source <> 'manual'
    ");
    $insertRank = $db->prepare("
        INSERT INTO offer_rankings (offer_id, student_id, `rank`, cycle_id, source)
        VALUES (?, ?, ?, ?, 'recommendation')
    ");

    foreach ($offers as $o) {
        $offerId = (int)$o['id'];

        $hasManualRank->execute([$offerId]);
        if ((int)$hasManualRank->fetchColumn() > 0) {
            $summary['offers_manual']++;
            continue;                       // the poster chose; leave it alone
        }

        $countApplicantRanks->execute([$offerId]);
        if ((int)$countApplicantRanks->fetchColumn() === 0) {
            $res = EngineRunner::run('applicant_ranking', ['offer-id' => $offerId]);
            if (!$res['success']) {
                $summary['engine_failures'][] = "applicant_ranking (offer $offerId): " . $res['error'];
            }
        }

        $orderedApplicants->execute([$offerId]);
        $studentIds = array_map('intval', array_column($orderedApplicants->fetchAll(), 'student_id'));
        $studentIds = array_values(array_unique($studentIds));

        if (empty($studentIds)) {
            $summary['offers_no_data']++;
            continue;
        }

        $clearGeneratedRanks->execute([$offerId]);
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
    $parts[] = $s['students_generated'] . ' student preference list(s) generated';
    if ($s['students_manual'])  $parts[] = $s['students_manual'] . ' kept as manually ranked';
    if ($s['students_no_data']) $parts[] = $s['students_no_data'] . ' student(s) had no open applications';
    $parts[] = $s['offers_generated'] . ' offer ranking(s) generated';
    if ($s['offers_manual'])    $parts[] = $s['offers_manual'] . ' kept as manually ranked';
    return implode(', ', $parts) . '.';
}