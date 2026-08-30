<?php
/**
 * ============================================================================
 * INTEGRITY CHECKER (Layer 3)
 * ============================================================================
 * A suite of named checks against cycle data. Every check name matches a
 * 'requires' entry in cycle_state_machine.php. Each returns:
 *   ['status' => 'pass'|'fail'|'warn', 'title' => str, 'message' => str, 'details' => array]
 * ============================================================================
 */

class IntegrityChecker
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function runCheck(string $name, array $cycle): array
    {
        if (!method_exists($this, $name)) {
            return ['status' => 'fail', 'title' => $name,
                    'message' => "Unknown check: $name", 'details' => []];
        }
        return $this->$name($cycle);
    }

    // ---- DRAFT phase ----

    public function check_cycle_has_offers(array $cycle): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM internship_offers WHERE status = 'open'");
        $stmt->execute();
        $n = (int)$stmt->fetchColumn();
        return [
            'status'  => $n > 0 ? 'pass' : 'fail',
            'title'   => 'Offers exist',
            'message' => $n > 0 ? "$n open offers available." : 'No open offers — students would have nothing to apply to.',
            'details' => [],
        ];
    }

    public function check_deadlines_set(array $cycle): array
    {
        $missing = [];
        if (empty($cycle['applications_deadline'])) $missing[] = 'applications_deadline';
        if (empty($cycle['ranking_deadline']))      $missing[] = 'ranking_deadline';
        if (empty($cycle['decision_deadline']))     $missing[] = 'decision_deadline';
        return [
            'status'  => empty($missing) ? 'pass' : 'warn',
            'title'   => 'Deadlines configured',
            'message' => empty($missing) ? 'All deadlines set.' : 'Missing: ' . implode(', ', $missing) . ' (advisory only — advancement is manual)',
            'details' => $missing,
        ];
    }

    // ---- APPLICATIONS_INTEGRITY ----

    public function check_applications_present(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM applications a
            JOIN internship_offers o ON o.id = a.offer_id
            WHERE a.status != 'withdrawn' AND o.status = 'open'
        ");
        $stmt->execute();
        $n = (int)$stmt->fetchColumn();
        return [
            'status'  => $n > 0 ? 'pass' : 'fail',
            'title'   => 'Applications present',
            'message' => "$n active applications recorded.",
            'details' => [],
        ];
    }

    public function check_all_applications_valid(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, s.matricola, o.title
            FROM applications a
            JOIN students s ON s.id = a.student_id
            JOIN internship_offers o ON o.id = a.offer_id
            WHERE a.status != 'withdrawn' AND o.status != 'open'
        ");
        $stmt->execute();
        $bad = $stmt->fetchAll();
        return [
            'status'  => empty($bad) ? 'pass' : 'fail',
            'title'   => 'All applications reference open offers',
            'message' => empty($bad)
                ? 'Every active application points at an open offer.'
                : count($bad) . ' application(s) reference offers that are no longer open.',
            'details' => $bad,
        ];
    }

    public function check_all_students_have_preferences(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT s.id, s.matricola, CONCAT(u.first_name, ' ', u.last_name) AS name
            FROM students s
            JOIN users u ON u.id = s.user_id
            JOIN applications a ON a.student_id = s.id
            WHERE a.status != 'withdrawn'
              AND s.id NOT IN (
                  SELECT student_id FROM student_preferences WHERE cycle_id = ?
              )
        ");
        $stmt->execute([(int)$cycle['id']]);
        $missing = $stmt->fetchAll();
        return [
            'status'  => empty($missing) ? 'pass' : 'warn',
            'title'   => 'All applicants ranked their preferences',
            'message' => empty($missing)
                ? 'Every applicant has submitted a preference ranking.'
                : count($missing) . ' applicant(s) have not yet ranked their preferences.',
            'details' => $missing,
        ];
    }

    public function check_no_orphaned_applications(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id FROM applications a
            LEFT JOIN students s ON s.id = a.student_id
            LEFT JOIN internship_offers o ON o.id = a.offer_id
            WHERE s.id IS NULL OR o.id IS NULL
        ");
        $stmt->execute();
        $orphans = $stmt->fetchAll();
        return [
            'status'  => empty($orphans) ? 'pass' : 'fail',
            'title'   => 'No orphaned applications',
            'message' => empty($orphans)
                ? 'No applications reference missing students or offers.'
                : count($orphans) . ' orphaned application(s) found.',
            'details' => $orphans,
        ];
    }

    // ---- RANKING_INTEGRITY ----

    public function check_all_offers_ranked(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT o.id, o.title
            FROM internship_offers o
            WHERE o.status = 'open'
              AND EXISTS (SELECT 1 FROM applications a WHERE a.offer_id = o.id AND a.status != 'withdrawn')
              AND o.id NOT IN (SELECT offer_id FROM offer_rankings WHERE cycle_id = ?)
        ");
        $stmt->execute([(int)$cycle['id']]);
        $unranked = $stmt->fetchAll();
        return [
            'status'  => empty($unranked) ? 'pass' : 'fail',
            'title'   => 'All offers with applicants have rankings',
            'message' => empty($unranked)
                ? 'Every offer with applicants has been ranked.'
                : count($unranked) . ' offer(s) still need ranking.',
            'details' => $unranked,
        ];
    }

    public function check_rankings_reference_applicants_only(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT r.offer_id, r.student_id, o.title, s.matricola
            FROM offer_rankings r
            JOIN internship_offers o ON o.id = r.offer_id
            JOIN students s ON s.id = r.student_id
            WHERE r.cycle_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM applications a
                  WHERE a.offer_id = r.offer_id AND a.student_id = r.student_id
                    AND a.status != 'withdrawn'
              )
        ");
        $stmt->execute([(int)$cycle['id']]);
        $bad = $stmt->fetchAll();
        return [
            'status'  => empty($bad) ? 'pass' : 'fail',
            'title'   => 'Rankings reference only applicants',
            'message' => empty($bad)
                ? 'Every ranked student actually applied to the offer.'
                : count($bad) . ' ranking(s) reference students who never applied.',
            'details' => $bad,
        ];
    }

    // ---- MATCHING_RUNNING ----

    public function check_matching_round_exists(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT id FROM matching_rounds WHERE cycle_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$cycle['id']]);
        $round = $stmt->fetch();
        return [
            'status'  => $round ? 'pass' : 'fail',
            'title'   => 'Matching round produced',
            'message' => $round ? "Round #{$round['id']} recorded." : 'No matching round was produced.',
            'details' => [],
        ];
    }

    public function check_verifier_confirms_stability(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT id, status, blocking_pairs FROM matching_rounds
            WHERE cycle_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$cycle['id']]);
        $round = $stmt->fetch();
        if (!$round) {
            return ['status' => 'fail', 'title' => 'Stability verified',
                    'message' => 'No round found.', 'details' => []];
        }
        $stable = (int)$round['blocking_pairs'] === 0;
        return [
            'status'  => $stable ? 'pass' : 'fail',
            'title'   => 'Stability verified',
            'message' => $stable
                ? "Round #{$round['id']} confirmed stable (0 blocking pairs)."
                : "Round #{$round['id']} is UNSTABLE ({$round['blocking_pairs']} blocking pairs). Do not publish.",
            'details' => [],
        ];
    }

    // ---- MATCHING_INTEGRITY ----

    public function check_matching_stable(array $cycle): array
    {
        return $this->check_verifier_confirms_stability($cycle);
    }

    // ---- POST_DECISION_INTEGRITY ----

    public function check_unmatched_pool_nonempty(array $cycle): array
    {
        // Students who submitted preferences but are not accepted or rejected.
        // Rejections stay out (per the workflow: voluntary rejectors are excluded from R2).
        $stmt = $this->db->prepare("
            SELECT s.id, s.matricola, CONCAT(u.first_name,' ',u.last_name) AS name
            FROM students s
            JOIN users u ON u.id = s.user_id
            WHERE s.id IN (SELECT student_id FROM student_preferences WHERE cycle_id = ?)
              AND s.id NOT IN (
                  SELECT a.student_id
                  FROM allocations a
                  JOIN matching_rounds r ON r.id = a.round_id
                  WHERE r.cycle_id = ? AND a.decision IN ('accepted','rejected')
              )
        ");
        $stmt->execute([(int)$cycle['id'], (int)$cycle['id']]);
        $pool = $stmt->fetchAll();
        return [
            'status'  => !empty($pool) ? 'pass' : 'fail',
            'title'   => 'Unmatched student pool exists',
            'message' => !empty($pool)
                ? count($pool) . ' student(s) eligible for another round.'
                : 'No students remain for a second round.',
            'details' => $pool,
        ];
    }

    public function check_unclaimed_offers_exist(array $cycle): array
    {
        $stmt = $this->db->prepare("
            SELECT o.id, o.title, o.capacity, o.filled
            FROM internship_offers o
            WHERE o.status = 'open' AND (o.capacity - o.filled) > 0
        ");
        $stmt->execute();
        $unclaimed = $stmt->fetchAll();
        return [
            'status'  => !empty($unclaimed) ? 'pass' : 'fail',
            'title'   => 'Unclaimed offer capacity exists',
            'message' => !empty($unclaimed)
                ? count($unclaimed) . ' offer(s) still have open capacity.'
                : 'All offers are full — no capacity for another round.',
            'details' => $unclaimed,
        ];
    }

    // ---- Batch runner ----

    public function runChecksForTransitions(array $checkNames, array $cycle): array
    {
        $results = [];
        foreach ($checkNames as $name) {
            $results[$name] = $this->runCheck($name, $cycle);
        }
        return $results;
    }
}