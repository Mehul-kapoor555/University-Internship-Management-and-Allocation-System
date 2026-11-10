<?php
/**
 * ============================================================================
 * CYCLE STATE MACHINE — ENGINE (Layer 2)
 * ============================================================================
 * Generic FSM interpreter. Reads state from academic_cycles, transitions from
 * cycle_state_machine.php definition, evaluates guards via IntegrityChecker,
 * fires side effects, updates DB, writes audit row.
 * ============================================================================
 */

require_once __DIR__ . '/integrity_checker.php';
// For recomputeOfferFilled(). db.php already loads this, but requiring it here
// too means the class does not silently depend on its caller's include order.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/engine_runner.php';
require_once __DIR__ . '/Mailer.php';

class CycleStateMachine
{
    private PDO $db;
    private array $definition;
    private IntegrityChecker $checker;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->definition = require __DIR__ . '/cycle_state_machine.php';
        $this->checker = new IntegrityChecker($db);
    }

    // ---- QUERIES ----

    public function loadCycle(?int $cycleId = null): ?array
    {
        if ($cycleId === null) {
            $stmt = $this->db->prepare("SELECT * FROM academic_cycles WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("SELECT * FROM academic_cycles WHERE id = ?");
            $stmt->execute([$cycleId]);
        }
        $cycle = $stmt->fetch();
        return $cycle ?: null;
    }

    public function stateDef(string $stateName): ?array
    {
        return $this->definition[$stateName] ?? null;
    }

    public function availableTransitions(string $stateName): array
    {
        return $this->definition[$stateName]['transitions'] ?? [];
    }

    public function isVisibleTo(string $stateName, string $role): bool
    {
        $def = $this->stateDef($stateName);
        return $def && in_array($role, $def['visible_to'] ?? [], true);
    }

    public function transitionHistory(int $cycleId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, CONCAT(u.first_name,' ',u.last_name) AS triggered_by_name
            FROM cycle_state_transitions t
            LEFT JOIN users u ON u.id = t.triggered_by_user_id
            WHERE t.cycle_id = ?
            ORDER BY t.transitioned_at ASC
        ");
        $stmt->execute([$cycleId]);
        return $stmt->fetchAll();
    }

    // ---- GUARDS ----

    public function evaluateTransitionGuards(array $cycle, string $transitionKey): array
    {
        $state = $this->definition[$cycle['current_state']] ?? null;
        if (!$state) return ['results' => [], 'all_passed' => false, 'error' => 'Unknown state'];

        $trans = $state['transitions'][$transitionKey] ?? null;
        if (!$trans) return ['results' => [], 'all_passed' => false, 'error' => 'Unknown transition'];

        $checkNames = $trans['requires'] ?? [];
        $results = $this->checker->runChecksForTransitions($checkNames, $cycle);

        $allPassed = true;
        foreach ($results as $r) {
            if ($r['status'] === 'fail') { $allPassed = false; break; }
        }
        return ['results' => $results, 'all_passed' => $allPassed, 'error' => null];
    }

    // ---- MAIN OPERATION ----

    public function attemptTransition(int $cycleId, string $transitionKey,
                                      int $triggeredByUserId,
                                      bool $overrideWarnings = false,
                                      ?string $notes = null): array
    {
        $cycle = $this->loadCycle($cycleId);
        if (!$cycle) return ['ok' => false, 'reason' => 'Cycle not found', 'checks' => [], 'new_state' => null];

        $currentState = $cycle['current_state'];
        $stateDef = $this->definition[$currentState] ?? null;
        if (!$stateDef) return ['ok' => false, 'reason' => "Unknown current state: $currentState", 'checks' => [], 'new_state' => null];

        $trans = $stateDef['transitions'][$transitionKey] ?? null;
        if (!$trans) return ['ok' => false, 'reason' => "Transition '$transitionKey' not allowed from '$currentState'", 'checks' => [], 'new_state' => null];

        $eval = $this->evaluateTransitionGuards($cycle, $transitionKey);
        $checks = $eval['results'];

        $hasHardFail = false;
        foreach ($checks as $c) if ($c['status'] === 'fail') $hasHardFail = true;
        if ($hasHardFail) {
            return ['ok' => false, 'reason' => 'One or more integrity checks failed.',
                    'checks' => $checks, 'new_state' => null];
        }

        $hasWarn = false;
        foreach ($checks as $c) if ($c['status'] === 'warn') $hasWarn = true;
        if ($hasWarn && !$overrideWarnings) {
            return ['ok' => false, 'reason' => 'Warnings present — confirm override to proceed.',
                    'checks' => $checks, 'new_state' => null];
        }

        $this->db->beginTransaction();
        try {
            $targetState = $trans['target'];
            $incrementsRound = !empty($trans['increments_round']);
            $newRound = (int)$cycle['current_round'] + ($incrementsRound ? 1 : 0);

            $stmt = $this->db->prepare("
                UPDATE academic_cycles SET current_state = ?, current_round = ? WHERE id = ?
            ");
            $stmt->execute([$targetState, $newRound, $cycleId]);

            $guardResult = $hasWarn ? 'overridden' : 'passed';
            $stmt = $this->db->prepare("
                INSERT INTO cycle_state_transitions
                    (cycle_id, from_state, to_state, round_number, transition_key,
                     triggered_by_user_id, guard_result, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$cycleId, $currentState, $targetState, $newRound,
                            $transitionKey, $triggeredByUserId, $guardResult, $notes]);

            if (!empty($trans['on_transition'])) {
                $actionName = $trans['on_transition'];
                $freshCycle = $this->loadCycle($cycleId);
                $this->runAction($actionName, $freshCycle);
            }

            $this->db->commit();
            return ['ok' => true, 'reason' => 'Transitioned.',
                    'checks' => $checks, 'new_state' => $targetState];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'reason' => 'Transition failed: ' . $e->getMessage(),
                    'checks' => $checks, 'new_state' => null];
        }
    }

    // ---- SIDE EFFECTS ----

    private function runAction(string $actionName, array $cycle): void
    {
        if (!method_exists($this, $actionName)) return;
        $this->$actionName($cycle);
    }

    private function action_notify_all_applications_open(array $cycle): void
    {
        $this->notifyAllOfRole('student',
            'Applications are open',
            'The internship cycle "' . $cycle['name'] . '" is now open. Browse offers and apply.',
            'info', '/internships.php');
    }

    private function action_notify_coordinator_apps_closed(array $cycle): void
    {
        $this->notifyAllOfRole('coordinator',
            'Applications closed',
            'The application window for "' . $cycle['name'] . '" has closed. Ready to review.',
            'info', '/admin/cycle-control.php');
    }

    private function action_notify_posters_to_rank(array $cycle): void
    {
        $this->notifyAllOfRole('company',
            'Rank your applicants',
            'Ranking is now open for cycle "' . $cycle['name'] . '". Please rank your applicants.',
            'action', '/company/rank-applicants.php');
        $this->notifyAllOfRole('professor',
            'Rank your applicants',
            'Ranking is now open for cycle "' . $cycle['name'] . '". Please rank your applicants.',
            'action', '/professor/rank-applicants.php');
    }

    private function action_notify_coordinator_ranking_closed(array $cycle): void
    {
        $this->notifyAllOfRole('coordinator',
            'Ranking closed',
            'The ranking window for "' . $cycle['name'] . '" has closed. Ready to review before matching.',
            'info', '/admin/cycle-control.php');
    }

    private function action_run_matching_engine(array $cycle): void
    {
        $matchResult = EngineRunner::run('matching', ['cycle-id' => (int)$cycle['id']]);
        if ($matchResult['success'] && !empty($matchResult['data']['round_id'])) {
            EngineRunner::run('stability_verifier',
                ['round-id' => (int)$matchResult['data']['round_id']]);
        }
    }

    private function action_publish_results_to_students(array $cycle): void
    {
        $stmt = $this->db->prepare("
            SELECT id FROM matching_rounds WHERE cycle_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$cycle['id']]);
        $round = $stmt->fetch();
        if (!$round) return;

        $stmt = $this->db->prepare("
            UPDATE allocations SET status = 'confirmed', round_number = ?
            WHERE round_id = ?
        ");
        $stmt->execute([(int)$cycle['current_round'], (int)$round['id']]);

        $stmt = $this->db->prepare("
            UPDATE matching_rounds SET status = 'published' WHERE id = ?
        ");
        $stmt->execute([(int)$round['id']]);

        $stmt = $this->db->prepare("
            SELECT s.user_id, o.title
            FROM allocations a
            JOIN students s ON s.id = a.student_id
            JOIN internship_offers o ON o.id = a.offer_id
            WHERE a.round_id = ?
        ");
        $stmt->execute([(int)$round['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $this->notify((int)$row['user_id'],
                'You have been matched — accept or reject',
                'You have been matched with "' . $row['title'] . '". Please respond before the decision deadline.',
                'action', '/applications.php');

            // Also send a real email through the Mailer (Brevo) adapter. In demo
            // mode this is recorded in sent_emails and shown on the email log.
            $body = Mailer::wrap('You have been matched',
                '<p>Good news — you have been matched with '
                . '<strong>' . htmlspecialchars($row['title'], ENT_QUOTES) . '</strong>.</p>'
                . '<p>Please log in and either accept or reject your placement so the '
                . 'coordinator can finalise the cycle.</p>');
            Mailer::sendToUser($this->db, (int)$row['user_id'],
                'You have been matched — please respond', $body, 'match_published');
        }
    }

    private function action_timeout_pending_decisions(array $cycle): void
    {
        $stmt = $this->db->prepare("
            UPDATE allocations a
            JOIN matching_rounds r ON r.id = a.round_id
            SET a.decision = 'timed_out', a.decision_at = NOW()
            WHERE r.cycle_id = ? AND a.decision = 'pending'
        ");
        $stmt->execute([(int)$cycle['id']]);

        // A student who never answered has not taken their seat, so it must go
        // back into the pool for the next round. Seat counts are derived from
        // the accepted allocations, and these rows just stopped being pending
        // without becoming accepted, so a recount releases them.
        recomputeOfferFilled($this->db);
    }

    private function action_discard_current_round(array $cycle): void
    {
        $stmt = $this->db->prepare("
            SELECT id FROM matching_rounds WHERE cycle_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$cycle['id']]);
        $round = $stmt->fetch();
        if (!$round) return;

        $stmt = $this->db->prepare("DELETE FROM matching_rounds WHERE id = ?");
        $stmt->execute([(int)$round['id']]);
    }

    private function action_publish_to_posters(array $cycle): void
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT o.posted_by_user_id AS user_id
            FROM allocations a
            JOIN matching_rounds r ON r.id = a.round_id
            JOIN internship_offers o ON o.id = a.offer_id
            WHERE r.cycle_id = ? AND a.decision = 'accepted'
        ");
        $stmt->execute([(int)$cycle['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $this->notify((int)$row['user_id'],
                'Final matching results available',
                'The cycle "' . $cycle['name'] . '" has concluded. You may now view accepted student matches.',
                'success', '/dashboard.php');
        }

        // Spawn one internship-lifecycle instance per accepted placement. The
        // lifecycle engine takes over from here (agreement -> approvals ->
        // active -> closure). Idempotent: only placements without an instance
        // are created, so a manual spawn on the dashboard is harmless too.
        try {
            require_once __DIR__ . '/LifecycleStateMachine.php';
            (new LifecycleStateMachine($this->db))->spawnForCycle((int)$cycle['id']);
        } catch (Throwable $e) {
            /* non-fatal: coordinator can spawn manually from the dashboard */
        }
    }

    // ---- Notification helpers (schema: user_id,title,message,type,is_read,link,created_at) ----

    private function notify(int $userId, string $title, string $message, string $type, string $link): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        try { $stmt->execute([$userId, $title, $message, $type, $link]); }
        catch (Exception $e) { /* non-fatal */ }
    }

    private function notifyAllOfRole(string $role, string $title, string $message, string $type, string $link): void
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = ? AND is_active = 1");
        $stmt->execute([$role]);
        foreach ($stmt->fetchAll() as $row) {
            $this->notify((int)$row['id'], $title, $message, $type, $link);
        }
    }
}