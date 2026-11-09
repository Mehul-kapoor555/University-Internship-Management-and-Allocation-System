<?php
/**
 * ============================================================================
 * INTERNSHIP LIFECYCLE STATE MACHINE — ENGINE (Layer 2)
 * ============================================================================
 * Generic FSM interpreter for one accepted placement. Architectural twin of
 * CycleStateMachine: reads the declarative definition, drives transitions,
 * writes an audit row, and fires side effects. The cycle engine's side effects
 * run scoring and matching; this engine's side effects generate documents,
 * dispatch forms, and send email through the Phase-2 adapters.
 *
 * Differences from the cycle engine, all per the report:
 *   - Guards are BINARY (allowed / not allowed), not three-valued. There is no
 *     integrity checker; a transition is either legal from the current state or
 *     it is not.
 *   - Actions fire ON ENTRY to a state (generate a document, dispatch a form).
 *   - One special transition (submit_final) fires only when the FINAL_DOCS join
 *     of three independent submissions is complete.
 * ============================================================================
 */

require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/PdfGenerator.php';

class LifecycleStateMachine
{
    private PDO $db;
    private array $definition;

    // The three submissions the FINAL_DOCS join waits on.
    private const FINAL_SET = ['final_report', 'company_evaluation', 'professor_assessment'];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->definition = require __DIR__ . '/lifecycle_state_machine.php';
    }

    // ---- QUERIES -----------------------------------------------------------

    public function stateDef(string $state): ?array
    {
        return $this->definition[$state] ?? null;
    }

    public function loadByAllocation(int $allocationId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM workflow_states WHERE allocation_id = ? LIMIT 1");
        $stmt->execute([$allocationId]);
        return $stmt->fetch() ?: null;
    }

    public function loadByToken(string $token): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM workflow_states WHERE correlation_token = ? LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function loadById(int $workflowId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM workflow_states WHERE id = ? LIMIT 1");
        $stmt->execute([$workflowId]);
        return $stmt->fetch() ?: null;
    }

    public function availableTransitions(string $state): array
    {
        return $this->definition[$state]['transitions'] ?? [];
    }

    public function history(int $workflowId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, CONCAT(u.first_name,' ',u.last_name) AS triggered_by_name
            FROM lifecycle_transitions t
            LEFT JOIN users u ON u.id = t.triggered_by
            WHERE t.workflow_id = ?
            ORDER BY t.created_at ASC
        ");
        $stmt->execute([$workflowId]);
        return $stmt->fetchAll();
    }

    // ---- SPAWN -------------------------------------------------------------

    /**
     * Create one lifecycle instance per accepted placement in a cycle that does
     * not already have one. Returns the number spawned. Called after the cycle
     * finalises (or after supervisor assignment). Idempotent.
     */
    public function spawnForCycle(int $cycleId): int
    {
        $stmt = $this->db->prepare("
            SELECT a.id AS allocation_id
            FROM allocations a
            JOIN matching_rounds r ON r.id = a.round_id
            WHERE r.cycle_id = ?
              AND a.decision = 'accepted'
              AND a.id NOT IN (SELECT allocation_id FROM workflow_states)
        ");
        $stmt->execute([$cycleId]);
        $rows = $stmt->fetchAll();

        $spawned = 0;
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(16)); // 32 hex chars
            $this->db->prepare("
                INSERT INTO workflow_states (allocation_id, correlation_token, current_state, spawned_at)
                VALUES (?, ?, 'allocated', NOW())
            ")->execute([(int)$row['allocation_id'], $token]);
            $spawned++;
        }
        return $spawned;
    }

    // ---- MAIN OPERATION ----------------------------------------------------

    /**
     * Attempt a transition on one placement.
     *
     * @param int         $workflowId
     * @param string      $transitionKey
     * @param string      $source  'user' | 'webhook' | 'system'
     * @param int|null    $userId  triggering user (null for webhook)
     * @param string|null $comment
     * @return array{ok:bool, reason:?string, new_state:?string}
     */
    public function fire(int $workflowId, string $transitionKey,
                         string $source = 'user', ?int $userId = null,
                         ?string $comment = null): array
    {
        $wf = $this->loadById($workflowId);
        if (!$wf) return ['ok' => false, 'reason' => 'Placement not found', 'new_state' => null];

        $current = $wf['current_state'];
        $stateDef = $this->definition[$current] ?? null;
        if (!$stateDef) return ['ok' => false, 'reason' => "Unknown state: $current", 'new_state' => null];

        $trans = $stateDef['transitions'][$transitionKey] ?? null;
        if (!$trans) {
            return ['ok' => false,
                    'reason' => "Transition '$transitionKey' not allowed from '$current'",
                    'new_state' => null];
        }

        $target = $trans['target'];

        $this->db->beginTransaction();
        try {
            // 1. Advance the state.
            $this->db->prepare("UPDATE workflow_states SET current_state = ? WHERE id = ?")
                     ->execute([$target, $workflowId]);

            // 2. Audit the transition.
            $this->db->prepare("
                INSERT INTO lifecycle_transitions
                    (workflow_id, from_state, to_state, transition, triggered_by, trigger_source, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$workflowId, $current, $target, $transitionKey, $userId, $source, $comment]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'reason' => 'Transition failed: ' . $e->getMessage(), 'new_state' => null];
        }

        // 3. Fire ON-ENTRY actions for the new state (outside the state-update
        //    transaction: document/email side effects should not roll back the
        //    state change, and each records its own audit trail).
        $this->runEntryActions($workflowId, $target);

        return ['ok' => true, 'reason' => null, 'new_state' => $target];
    }

    // ---- WEBHOOK CORRELATION (report §8.5.6) -------------------------------

    /**
     * Record a form submission and, if it satisfies the placement's current
     * state, fire the corresponding transition. Returns a structured result the
     * FormReceiver turns into an HTTP response.
     *
     * Routing depends on current state:
     *   doc_pending + signed_agreement     -> submit_agreement
     *   revising    + revision_resubmit    -> resubmit
     *   final_docs  + one of the three     -> record; fire submit_final iff join complete
     */
    public function handleSubmission(array $wf, string $formKind,
                                     ?string $submittedBy, ?string $payloadJson): array
    {
        $state = $wf['current_state'];
        $workflowId = (int)$wf['id'];

        // Record the submission first (every valid submission is logged).
        $this->db->prepare("
            INSERT INTO form_submissions (workflow_id, form_kind, submitted_by, payload)
            VALUES (?, ?, ?, ?)
        ")->execute([$workflowId, $formKind, $submittedBy, $payloadJson]);

        // Route by (state, formKind).
        if ($state === 'doc_pending' && $formKind === 'signed_agreement') {
            $r = $this->fire($workflowId, 'submit_agreement', 'webhook', null, 'Signed agreement received');
            return ['status' => 'fired', 'transition' => 'submit_agreement', 'new_state' => $r['new_state']];
        }

        if ($state === 'revising' && $formKind === 'revision_resubmit') {
            $r = $this->fire($workflowId, 'resubmit', 'webhook', null, 'Revision resubmitted');
            return ['status' => 'fired', 'transition' => 'resubmit', 'new_state' => $r['new_state']];
        }

        if ($state === 'final_docs' && in_array($formKind, self::FINAL_SET, true)) {
            // The join: fire submit_final only when all three kinds are present.
            if ($this->finalJoinComplete($workflowId)) {
                $r = $this->fire($workflowId, 'submit_final', 'webhook', null, 'All final documents received');
                return ['status' => 'fired', 'transition' => 'submit_final', 'new_state' => $r['new_state']];
            }
            return ['status' => 'recorded',
                    'transition' => null,
                    'new_state' => $state,
                    'note' => 'Final document recorded; waiting on remaining submissions.'];
        }

        return ['status' => 'ignored',
                'transition' => null,
                'new_state' => $state,
                'note' => "Submission '$formKind' does not apply in state '$state'."];
    }

    /**
     * The FINAL_DOCS join is complete when at least one submission of EACH of
     * the three required kinds has been recorded for this placement.
     */
    public function finalJoinComplete(int $workflowId): bool
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT form_kind FROM form_submissions
            WHERE workflow_id = ? AND form_kind IN ('final_report','company_evaluation','professor_assessment')
        ");
        $stmt->execute([$workflowId]);
        $have = array_column($stmt->fetchAll(), 'form_kind');
        return count(array_intersect(self::FINAL_SET, $have)) === count(self::FINAL_SET);
    }

    /** Which of the three final kinds are still missing (for dashboards). */
    public function finalJoinMissing(int $workflowId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT form_kind FROM form_submissions
            WHERE workflow_id = ? AND form_kind IN ('final_report','company_evaluation','professor_assessment')
        ");
        $stmt->execute([$workflowId]);
        $have = array_column($stmt->fetchAll(), 'form_kind');
        return array_values(array_diff(self::FINAL_SET, $have));
    }

    // ---- ON-ENTRY ACTIONS --------------------------------------------------

    private function runEntryActions(int $workflowId, string $state): void
    {
        $def = $this->definition[$state] ?? null;
        if (!$def || empty($def['on_entry'])) return;

        $ctx = $this->loadContext($workflowId);
        if (!$ctx) return;

        foreach ($def['on_entry'] as $action) {
            try {
                $this->{$action}($workflowId, $ctx);
            } catch (Throwable $e) {
                // A failed side effect must not crash the transition; log-and-continue.
            }
        }
    }

    /** Pull the people and offer around a placement, plus the correlation token. */
    private function loadContext(int $workflowId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT w.id AS workflow_id, w.correlation_token, w.allocation_id,
                   a.student_id, a.offer_id,
                   su.id AS student_user_id, su.email AS student_email,
                   CONCAT(su.first_name,' ',su.last_name) AS student_name,
                   o.title AS offer_title, o.posted_by_user_id,
                   pu.id AS poster_user_id, pu.email AS poster_email,
                   CONCAT(pu.first_name,' ',pu.last_name) AS poster_name,
                   supu.id AS prof_user_id, supu.email AS prof_email,
                   CONCAT(supu.first_name,' ',supu.last_name) AS prof_name
            FROM workflow_states w
            JOIN allocations a        ON a.id = w.allocation_id
            JOIN students s           ON s.id = a.student_id
            JOIN users su             ON su.id = s.user_id
            JOIN internship_offers o  ON o.id = a.offer_id
            JOIN users pu             ON pu.id = o.posted_by_user_id
            LEFT JOIN supervisor_assignments sa ON sa.allocation_id = a.id
            LEFT JOIN professors prof ON prof.id = sa.professor_id
            LEFT JOIN users supu      ON supu.id = prof.user_id
            WHERE w.id = ?
            LIMIT 1
        ");
        $stmt->execute([$workflowId]);
        return $stmt->fetch() ?: null;
    }

    private function formLink(array $ctx, string $formKind): string
    {
        // The public form link carries the correlation token so Google can post
        // it back. In real deployment this is the Google Form prefilled URL; the
        // token is what the webhook uses to correlate. We surface a local
        // fallback endpoint too (used by the "simulate submission" demo control).
        $base = defined('FORM_BASE_URL') ? FORM_BASE_URL : (BASE_URL . '/simulate-form.php');
        return $base . '?token=' . urlencode($ctx['correlation_token']) . '&kind=' . urlencode($formKind);
    }

    private function recordDocument(int $workflowId, string $docType, array $res): void
    {
        if (empty($res['ok'])) return;
        $this->db->prepare("
            INSERT INTO generated_documents (workflow_id, doc_type, file_path, format)
            VALUES (?, ?, ?, ?)
        ")->execute([$workflowId, $docType, $res['path'] ?? '', $res['format'] ?? 'pdf']);
    }

    // --- document generators ---
    private function generate_agreement(int $wfId, array $ctx): void
    {
        $res = PdfGenerator::placementAgreement($this->db, (int)$ctx['allocation_id']);
        $this->recordDocument($wfId, 'agreement', $res);
    }

    private function generate_revision_notice(int $wfId, array $ctx): void
    {
        $html = PdfGenerator::simpleNotice('Revision Requested',
            'The documentation for the placement "' . htmlspecialchars($ctx['offer_title']) . '" requires revision. '
            . 'Please review the feedback, correct the documents, and resubmit using the link provided by email.');
        $res = PdfGenerator::fromHtml($html, 'revision_wf_' . $wfId);
        $this->recordDocument($wfId, 'revision_notice', $res);
    }

    private function generate_rejection_notice(int $wfId, array $ctx): void
    {
        $html = PdfGenerator::simpleNotice('Placement Rejected',
            'The placement "' . htmlspecialchars($ctx['offer_title']) . '" for ' . htmlspecialchars($ctx['student_name'])
            . ' has been rejected and will not proceed.');
        $res = PdfGenerator::fromHtml($html, 'rejection_wf_' . $wfId);
        $this->recordDocument($wfId, 'rejection_notice', $res);
    }

    private function generate_certificate(int $wfId, array $ctx): void
    {
        $html = PdfGenerator::simpleNotice('Certificate of Completion',
            'This certifies that ' . htmlspecialchars($ctx['student_name']) . ' has successfully completed the internship '
            . '"' . htmlspecialchars($ctx['offer_title']) . '".');
        $res = PdfGenerator::fromHtml($html, 'certificate_wf_' . $wfId);
        $this->recordDocument($wfId, 'completion_certificate', $res);
    }

    // --- email actions ---
    private function email_student_agreement_form(int $wfId, array $ctx): void
    {
        if (!$ctx['student_user_id']) return;
        $link = $this->formLink($ctx, 'signed_agreement');
        $body = Mailer::wrap('Please sign your internship agreement',
            '<p>Your placement <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> is ready. '
            . 'The agreement is attached as a PDF. Please sign it through this form:</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">Open the signing form</a></p>');
        Mailer::sendToUser($this->db, (int)$ctx['student_user_id'],
            'Sign your internship agreement', $body, 'lifecycle_agreement');
    }

    private function email_professor_approve(int $wfId, array $ctx): void
    {
        if (empty($ctx['prof_user_id'])) return;
        $body = Mailer::wrap('Approval needed',
            '<p>The signed agreement for <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> '
            . '(' . htmlspecialchars($ctx['student_name']) . ') is ready for your approval.</p>');
        Mailer::sendToUser($this->db, (int)$ctx['prof_user_id'],
            'Internship agreement awaiting your approval', $body, 'lifecycle_prof_approval');
    }

    private function email_coordinator_approve(int $wfId, array $ctx): void
    {
        $coord = $this->db->query("SELECT id FROM users WHERE role='coordinator' LIMIT 1")->fetch();
        if (!$coord) return;
        $body = Mailer::wrap('Coordinator approval needed',
            '<p>The placement <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> '
            . '(' . htmlspecialchars($ctx['student_name']) . ') has professor approval and awaits your final approval.</p>');
        Mailer::sendToUser($this->db, (int)$coord['id'],
            'Placement awaiting coordinator approval', $body, 'lifecycle_coord_approval');
    }

    private function email_all_activated(int $wfId, array $ctx): void
    {
        $body = Mailer::wrap('Internship active',
            '<p>The placement <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> is now active.</p>');
        foreach (['student_user_id', 'poster_user_id', 'prof_user_id'] as $k) {
            if (!empty($ctx[$k])) {
                Mailer::sendToUser($this->db, (int)$ctx[$k], 'Internship is now active', $body, 'lifecycle_active');
            }
        }
    }

    private function email_final_form_links(int $wfId, array $ctx): void
    {
        // Student -> final report, company -> evaluation, professor -> assessment.
        $targets = [
            'student_user_id' => ['final_report', 'Submit your final report'],
            'poster_user_id'  => ['company_evaluation', 'Submit your company evaluation'],
            'prof_user_id'    => ['professor_assessment', 'Submit your assessment'],
        ];
        foreach ($targets as $k => [$kind, $subject]) {
            if (empty($ctx[$k])) continue;
            $link = $this->formLink($ctx, $kind);
            $body = Mailer::wrap($subject,
                '<p>For the placement <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong>, '
                . 'please submit your part of the closing documentation:</p>'
                . '<p><a href="' . htmlspecialchars($link) . '">Open the form</a></p>');
            Mailer::sendToUser($this->db, (int)$ctx[$k], $subject, $body, 'lifecycle_final_docs');
        }
    }

    private function email_student_revision_form(int $wfId, array $ctx): void
    {
        if (!$ctx['student_user_id']) return;
        $link = $this->formLink($ctx, 'revision_resubmit');
        $body = Mailer::wrap('Revision requested',
            '<p>Your documentation for <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> needs revision. '
            . 'A revision notice is attached. Resubmit through this form:</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">Open the resubmission form</a></p>');
        Mailer::sendToUser($this->db, (int)$ctx['student_user_id'],
            'Revision requested for your internship documents', $body, 'lifecycle_revision');
    }

    private function email_rejection(int $wfId, array $ctx): void
    {
        $body = Mailer::wrap('Placement rejected',
            '<p>The placement <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> has been rejected.</p>');
        foreach (['student_user_id', 'poster_user_id'] as $k) {
            if (!empty($ctx[$k])) {
                Mailer::sendToUser($this->db, (int)$ctx[$k], 'Placement rejected', $body, 'lifecycle_rejected');
            }
        }
    }

    private function email_certificate(int $wfId, array $ctx): void
    {
        $body = Mailer::wrap('Internship complete',
            '<p>Congratulations. The placement <strong>' . htmlspecialchars($ctx['offer_title']) . '</strong> is complete. '
            . 'The completion certificate is attached.</p>');
        foreach (['student_user_id', 'poster_user_id', 'prof_user_id'] as $k) {
            if (!empty($ctx[$k])) {
                Mailer::sendToUser($this->db, (int)$ctx[$k], 'Internship complete — certificate issued', $body, 'lifecycle_closed');
            }
        }
    }
}
