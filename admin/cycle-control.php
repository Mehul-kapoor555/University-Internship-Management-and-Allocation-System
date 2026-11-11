<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/CycleStateMachine.php';
requireRole(['coordinator']);

$user = currentUser();
$activePage = 'cycle-control';
$message = '';
$error = '';
$checkResults = null;

$fsm = new CycleStateMachine($db);
$cycle = $fsm->loadCycle();

/* ---------------------------------------------------------------------------
   Starting a cycle.

   This is step one of the whole process, so it belongs here rather than on a
   separate page: name the cycle, and the rest of the chain follows from it.

   Exactly one cycle may be active. academic_cycles.is_active defaults to 1, so
   creating a cycle used to leave the previous one active as well, and the two
   places that look up "the" active cycle disagreed about which one that was —
   loadCycle() takes the first row it finds, activeCycleId() takes the newest.
   Deactivating everything else first makes that impossible.
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_cycle') {
    $name  = trim($_POST['name'] ?? '');
    $start = $_POST['start_date'] ?? '';

    // The coordinator is not asked for an end date — nothing in the process
    // depends on when the cycle ends, and the cycle finishes when it is driven
    // to its final state, not on a date. The column is NOT NULL and is still
    // used as the latest allowed application deadline, so it is derived: a year
    // from the start, which is comfortably past any deadline worth setting.
    $end = $start !== '' ? date('Y-m-d', strtotime($start . ' +1 year')) : '';

    $replacingUnfinished = $cycle
        && !in_array($cycle['current_state'], ['FINAL_PUBLICATION', 'CANCELLED'], true);

    if ($name === '' || $start === '') {
        $error = 'A cycle name and a start date are required.';
    } elseif ($replacingUnfinished && !isset($_POST['confirm_replace'])) {
        $error = 'Tick the confirmation box to retire the cycle that is still running.';
    } else {
        $db->beginTransaction();
        $db->exec("UPDATE academic_cycles SET is_active = 0");
        $stmt = $db->prepare(
            "INSERT INTO academic_cycles (name, start_date, end_date, is_active, current_state, current_round)
             VALUES (?, ?, ?, 1, 'DRAFT', 1)"
        );
        $stmt->execute([$name, $start, $end]);
        $db->commit();

        $cycle = $fsm->loadCycle();
        $message = 'Cycle "' . $name . '" created. Students cannot see any offers until you open applications.';
    }
}

if (!$cycle) {
    require '../includes/header.php';
    ?>
    <div class="page-header">
        <h1>Start a cycle</h1>
        <p>Nothing runs until a cycle exists. Name it, set its dates, and the rest follows from here.</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" class="card form-narrow">
        <input type="hidden" name="action" value="create_cycle">
        <label for="name">Cycle name</label>
        <input type="text" id="name" name="name" required placeholder="e.g. Summer 2026">
        <label for="start_date">Start date</label>
        <input type="date" id="start_date" name="start_date" required value="<?= date('Y-m-d') ?>">
        <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Create cycle</button>
    </form>
    <?php
    require '../includes/footer.php';
    exit;
}

/* ------------------------------- transitions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview_checks') {
        $transitionKey = $_POST['transition_key'] ?? '';
        $eval = $fsm->evaluateTransitionGuards($cycle, $transitionKey);
        $checkResults = [
            'transition_key' => $transitionKey,
            'results' => $eval['results'],
            'all_passed' => $eval['all_passed'],
        ];

    } elseif ($action === 'transition') {
        $transitionKey = $_POST['transition_key'] ?? '';
        $override = isset($_POST['override_warnings']);
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $result = $fsm->attemptTransition((int)$cycle['id'], $transitionKey, (int)$user['id'], $override, $notes);
        if ($result['ok']) {
            $message = 'Moved to: ' . $result['new_state'];
            $cycle = $fsm->loadCycle();
        } else {
            $error = $result['reason'];
            $checkResults = [
                'transition_key' => $transitionKey,
                'results' => $result['checks'],
                'all_passed' => false,
            ];
        }
    }
}

$state       = $cycle['current_state'];
$stateDef    = $fsm->stateDef($state);
$transitions = $fsm->availableTransitions($state);

/* ---------------------------------------------------------------------------
   THE SIX STEPS

   The machine has eleven states, but a coordinator thinks in phases. Each phase
   is a period where other people are doing work, followed by a review before
   moving on — which is exactly what the "integrity" states are. Grouping them
   this way turns the machine into a form you walk through, without changing the
   machine itself: every button below is still a real FSM transition.
--------------------------------------------------------------------------- */
$STEPS = [
    1 => ['label' => 'Setup',        'states' => ['DRAFT']],
    2 => ['label' => 'Applications', 'states' => ['APPLICATIONS_OPEN', 'APPLICATIONS_INTEGRITY']],
    3 => ['label' => 'Ranking',      'states' => ['RANKING_OPEN', 'RANKING_INTEGRITY']],
    4 => ['label' => 'Matching',     'states' => ['MATCHING_RUNNING', 'MATCHING_INTEGRITY']],
    5 => ['label' => 'Decisions',    'states' => ['RESULTS_PUBLISHED', 'POST_DECISION_INTEGRITY']],
    6 => ['label' => 'Complete',     'states' => ['FINAL_PUBLICATION']],
];

$currentStep = 0;
foreach ($STEPS as $n => $s) {
    if (in_array($state, $s['states'], true)) { $currentStep = $n; break; }
}
$isCancelled = ($state === 'CANCELLED');

/* What each step means in plain terms: who is doing what right now. */
$STEP_NOW = [
    'DRAFT'                   => 'You are preparing the cycle. Review the offers below — students cannot see anything yet.',
    'APPLICATIONS_OPEN'       => 'Students can see the offers, apply, and put them in order of preference.',
    'APPLICATIONS_INTEGRITY'  => 'Applications are closed. Students can still look, but can no longer apply or reorder. Check the applications before handing them to the professors and companies.',
    'RANKING_OPEN'            => 'Professors and companies are ranking the students who applied to their offers.',
    'RANKING_INTEGRITY'       => 'Ranking is closed. Check that every offer has been ranked before matching runs.',
    'MATCHING_RUNNING'        => 'The matching has been run and the stability verifier has checked it. Review the result before showing it to anyone.',
    'MATCHING_INTEGRITY'      => 'The result is verified and ready. Publishing it tells each matched student and asks them to accept or reject.',
    'RESULTS_PUBLISHED'       => 'Students are accepting or rejecting their placements.',
    'POST_DECISION_INTEGRITY' => 'The decision window is closed. You can run another round for the students who were not placed, or finish the cycle.',
    'FINAL_PUBLICATION'       => 'The cycle is finished. Professors and companies have been told who they are getting.',
    'CANCELLED'               => 'This cycle was cancelled. Nothing is visible to students. Start a new cycle below when you are ready.',
];

/* What the button will actually do, in the coordinator's words rather than the
   transition's name. Kept here rather than in the definition file, which the
   engine reads as a flat map of states and would not expect an extra key. */
$EFFECTS = [
    'open_applications'   => 'Students will be able to see the offers and apply. Every student gets a notification that the cycle is open.',
    'close_applications'  => 'Students can no longer apply or change their preference order. The offers stay visible to them, read-only.',
    'open_ranking'        => 'Professors and companies are asked to rank the students who applied to their offers.',
    'reopen_applications' => 'Goes back a step: students can apply and reorder again.',
    'close_ranking'       => 'Professors and companies can no longer change their rankings.',
    'run_matching'        => 'Runs the matching algorithm, then the stability verifier. Nothing is shown to students yet.',
    'reopen_ranking'      => 'Goes back a step: professors and companies can rank again.',
    'matching_complete'   => 'Accepts the matching result and moves on to reviewing it.',
    'publish_to_students' => 'Each matched student is notified and emailed, and asked to accept or reject their placement.',
    'discard_and_rerun'   => 'Throws this matching result away and goes back so it can be run again.',
    'close_decisions'     => 'Anyone who has not answered is given a simulated decision — roughly one in three declines. Students who answered keep their own choice.',
    'run_next_round'      => 'Runs the matching again for the students who were not placed, using the seats that are still free.',
    'finalise'            => 'Finishes the cycle. Professors and companies are told which students they are getting.',
    'cancel'              => 'Ends this cycle immediately. Students stop seeing any offers. This cannot be undone.',
];

/* The one transition that moves the process forward at each step. Everything
   else offered from the same state is a way back, and is shown as such.
   POST_DECISION_INTEGRITY is deliberately absent: running another round and
   finishing the cycle are both legitimate next moves, so neither is demoted. */
$FORWARD = [
    'DRAFT'                  => 'open_applications',
    'APPLICATIONS_OPEN'      => 'close_applications',
    'APPLICATIONS_INTEGRITY' => 'open_ranking',
    'RANKING_OPEN'           => 'close_ranking',
    'RANKING_INTEGRITY'      => 'run_matching',
    'MATCHING_RUNNING'       => 'matching_complete',
    'MATCHING_INTEGRITY'     => 'publish_to_students',
    'RESULTS_PUBLISHED'      => 'close_decisions',
];

// Cancel is rendered separately, in red, and only exists where the machine
// allows it — Draft and Applications open.
$cancelAvailable = isset($transitions['cancel']);
$forwardKey = $FORWARD[$state] ?? null;

$ordered = [];
if ($forwardKey && isset($transitions[$forwardKey])) {
    $ordered[$forwardKey] = $transitions[$forwardKey];
}
foreach ($transitions as $k => $t) {
    if ($k !== 'cancel' && !isset($ordered[$k])) $ordered[$k] = $t;
}

/* ------------------------- step-specific panels -------------------------- */

// Setup: the offers that must exist before applications can open. The guard on
// this step is check_cycle_has_offers, so this is the work that unblocks it.
$offerCounts = null;
$pendingOffers = [];
if ($state === 'DRAFT') {
    $offerCounts = $db->query("
        SELECT
          SUM(status = 'open')  AS approved,
          SUM(status = 'draft') AS pending
        FROM internship_offers
    ")->fetch();
    $pendingOffers = $db->query("
        SELECT o.id, o.title, o.type, o.capacity,
               COALESCE(c.company_name, CONCAT(u.first_name,' ',u.last_name)) AS poster
        FROM internship_offers o
        JOIN users u ON u.id = o.posted_by_user_id
        LEFT JOIN companies c ON c.user_id = u.id
        WHERE o.status = 'draft'
        ORDER BY o.created_at ASC
    ")->fetchAll();
}

// Matching: the round that was just produced.
$latestRound = null;
if (in_array($state, ['MATCHING_RUNNING', 'MATCHING_INTEGRITY'], true)) {
    // matching_rounds stores only the round's own facts; how many students were
    // placed is counted from its allocations.
    $stmt = $db->prepare("
        SELECT mr.id, mr.status, mr.blocking_pairs, mr.run_at,
               (SELECT COUNT(*) FROM allocations WHERE round_id = mr.id) AS allocation_count
        FROM matching_rounds mr
        WHERE mr.cycle_id = ? ORDER BY mr.id DESC LIMIT 1
    ");
    $stmt->execute([(int)$cycle['id']]);
    $latestRound = $stmt->fetch();
}

// Decisions: who accepted, who rejected, and who decided it.
$decisionRows = [];
$decisionCounts = ['accepted' => 0, 'rejected' => 0, 'timed_out' => 0, 'pending' => 0];
if (in_array($state, ['RESULTS_PUBLISHED', 'POST_DECISION_INTEGRITY', 'FINAL_PUBLICATION'], true)) {
    $stmt = $db->prepare("
        SELECT a.decision, a.decision_source, a.decision_at, a.round_number,
               o.title,
               CONCAT(u.first_name, ' ', u.last_name) AS student_name,
               s.matricola
        FROM allocations a
        JOIN matching_rounds r   ON r.id = a.round_id
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN students s          ON s.id = a.student_id
        JOIN users u             ON u.id = s.user_id
        WHERE r.cycle_id = ?
        ORDER BY a.round_number DESC, a.id DESC
    ");
    $stmt->execute([(int)$cycle['id']]);
    $decisionRows = $stmt->fetchAll();
    foreach ($decisionRows as $d) {
        if (isset($decisionCounts[$d['decision']])) $decisionCounts[$d['decision']]++;
    }
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1><?= htmlspecialchars($cycle['name']) ?></h1>
    <p>Run the cycle from here, one step at a time. Every step is checked before it moves.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ============================ STEP BAR ============================ -->
<?php if (!$isCancelled): ?>
<div class="stepbar">
    <?php foreach ($STEPS as $n => $s): ?>
        <div class="step <?= $n < $currentStep ? 'step-done' : ($n === $currentStep ? 'step-now' : 'step-todo') ?>">
            <div class="step-dot"><?= $n < $currentStep ? '✓' : $n ?></div>
            <div class="step-label"><?= htmlspecialchars($s['label']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ======================== WHERE YOU ARE ========================== -->
<div class="card fsm-hero">
    <div class="fsm-hero-label">
        <?= $isCancelled ? 'Cancelled' : 'Step ' . $currentStep . ' of 6' ?>
    </div>
    <h2 class="fsm-hero-state"><?= htmlspecialchars($stateDef['label'] ?? $state) ?></h2>
    <div class="fsm-hero-meta">
        <span class="pill">Round <?= (int)$cycle['current_round'] ?></span>
        <span class="pill pill-mono"><?= htmlspecialchars($state) ?></span>
        <?php if (!empty($stateDef['is_terminal'])): ?>
            <span class="pill pill-terminal">Final state</span>
        <?php endif; ?>
    </div>
    <p class="fsm-hero-desc"><?= htmlspecialchars($STEP_NOW[$state] ?? ($stateDef['description'] ?? '')) ?></p>
</div>

<!-- ===================== STEP 1 — OFFERS TO REVIEW ================== -->
<?php if ($state === 'DRAFT'): ?>
    <div class="card">
        <h2 class="section-heading" style="margin-top:0;">Offers in this cycle</h2>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px;">
            <span class="pill" style="background:#dcfce7;color:#166534;">Approved: <?= (int)($offerCounts['approved'] ?? 0) ?></span>
            <span class="pill" style="background:#fef9c3;color:#854d0e;">Waiting for review: <?= (int)($offerCounts['pending'] ?? 0) ?></span>
        </div>

        <?php if ((int)($offerCounts['approved'] ?? 0) === 0): ?>
            <div class="alert alert-error">
                There are no approved offers yet. Applications cannot open until at least one offer is approved.
            </div>
        <?php endif; ?>

        <?php if (!empty($pendingOffers)): ?>
            <p style="font-size:13px;color:var(--slate);">
                These are waiting for your decision. Approving one makes it available to students once you open applications.
            </p>
            <table>
                <thead><tr><th>Offer</th><th>Posted by</th><th>Type</th><th>Places</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pendingOffers as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['title']) ?></td>
                        <td><?= htmlspecialchars($o['poster']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($o['type'])) ?></td>
                        <td><?= (int)$o['capacity'] ?></td>
                        <td><a href="<?= BASE_URL ?>/admin/offer-detail.php?id=<?= (int)$o['id'] ?>">Review →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="font-size:13px;color:var(--slate-light);">Nothing waiting for review.</p>
        <?php endif; ?>

        <p style="margin-top:14px;">
            <a href="<?= BASE_URL ?>/admin/offer-reviews.php">Open the full offers review page →</a>
        </p>
    </div>
<?php endif; ?>

<!-- ===================== STEP 4 — MATCHING RESULT =================== -->
<?php if ($latestRound): ?>
    <div class="card">
        <h2 class="section-heading" style="margin-top:0;">Matching result</h2>
        <div class="field-row"><span>Round</span><span>#<?= (int)$cycle['current_round'] ?></span></div>
        <div class="field-row"><span>Students placed</span>
            <span><?= (int)$latestRound['allocation_count'] ?></span></div>
        <div class="field-row"><span>Run status</span>
            <span><?= htmlspecialchars(ucfirst($latestRound['status'])) ?></span></div>
        <div class="field-row"><span>Stability</span>
            <span>
                <?php if ($latestRound['blocking_pairs'] === null): ?>
                    <span class="badge badge-pending">Not verified</span>
                <?php elseif ((int)$latestRound['blocking_pairs'] === 0): ?>
                    <span class="badge badge-accepted">Stable — no blocking pairs</span>
                <?php else: ?>
                    <span class="badge badge-rejected"><?= (int)$latestRound['blocking_pairs'] ?> blocking pair(s)</span>
                <?php endif; ?>
            </span>
        </div>
        <p style="margin-top:12px;">
            <a href="<?= BASE_URL ?>/admin/matching-visualizer.php?round_id=<?= (int)$latestRound['id'] ?>">
                See how the matching was decided →
            </a>
        </p>
    </div>
<?php endif; ?>

<!-- ===================== STEP 5 — DECISIONS ========================= -->
<?php if (!empty($decisionRows)): ?>
    <div class="card">
        <h2 class="section-heading" style="margin-top:0;">Student decisions</h2>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px;">
            <span class="pill" style="background:#dcfce7;color:#166534;">Accepted: <?= $decisionCounts['accepted'] ?></span>
            <span class="pill" style="background:#fee2e2;color:#991b1b;">Rejected: <?= $decisionCounts['rejected'] ?></span>
            <span class="pill" style="background:#fef9c3;color:#854d0e;">Not answered: <?= $decisionCounts['pending'] ?></span>
        </div>
        <?php if ($state === 'RESULTS_PUBLISHED' && $decisionCounts['pending'] > 0): ?>
            <p style="color:var(--slate-light);margin-top:0;">
                Waiting on <?= $decisionCounts['pending'] ?> student(s). Closing the decision window gives
                each of them a <strong>simulated</strong> answer — roughly one in three declines — so the
                cycle can continue without every student logging in. Students who answered keep their own choice.
            </p>
        <?php endif; ?>
        <table>
            <thead>
                <tr><th>Student</th><th>Offer</th><th>Round</th><th>Decision</th><th>Decided by</th><th>Responded</th></tr>
            </thead>
            <tbody>
            <?php foreach ($decisionRows as $d): ?>
                <?php
                    $cls = $d['decision'] === 'accepted' ? 'accepted'
                         : ($d['decision'] === 'pending' ? 'pending' : 'rejected');
                    $label = [
                        'accepted' => 'Accepted', 'rejected' => 'Rejected',
                        'timed_out' => 'Timed out', 'pending' => 'Not answered',
                    ][$d['decision']] ?? ucfirst($d['decision']);
                ?>
                <tr>
                    <td><?= htmlspecialchars($d['student_name']) ?> <span class="offer-meta">(<?= htmlspecialchars($d['matricola']) ?>)</span></td>
                    <td><?= htmlspecialchars($d['title']) ?></td>
                    <td>R<?= (int)$d['round_number'] ?></td>
                    <td><span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
                    <td>
                        <?php if ($d['decision_source'] === 'simulated'): ?>
                            <span class="pill" style="background:#fef9c3;color:#854d0e;">Simulated</span>
                        <?php elseif ($d['decision_source'] === 'student'): ?>
                            <span class="pill">Student</span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $d['decision_at'] ? htmlspecialchars(date('d M Y, H:i', strtotime($d['decision_at']))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- ======================== CHECK RESULTS =========================== -->
<?php if ($checkResults): ?>
    <div class="card">
        <h2 class="section-heading" style="margin-top:0;">Checks</h2>
        <?php if (empty($checkResults['results'])): ?>
            <p style="color:var(--slate);">Nothing to check for this step.</p>
        <?php else: ?>
            <div class="check-list">
                <?php foreach ($checkResults['results'] as $name => $r): ?>
                    <div class="check-row check-<?= $r['status'] ?>">
                        <div class="check-icon"><?= $r['status'] === 'pass' ? '✓' : ($r['status'] === 'warn' ? '⚠' : '✗') ?></div>
                        <div class="check-body">
                            <div class="check-title"><?= htmlspecialchars($r['title']) ?></div>
                            <div class="check-message"><?= htmlspecialchars($r['message']) ?></div>
                            <?php if (!empty($r['details']) && is_array($r['details'])): ?>
                                <details class="check-details">
                                    <summary>Show details (<?= count($r['details']) ?>)</summary>
                                    <pre><?= htmlspecialchars(json_encode($r['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            $hasWarn = false; $hasFail = false;
            foreach ($checkResults['results'] as $r) {
                if ($r['status'] === 'warn') $hasWarn = true;
                if ($r['status'] === 'fail') $hasFail = true;
            }
            ?>
            <?php if ($hasFail): ?>
                <div class="alert alert-error" style="margin-top:14px;">
                    Cannot continue — something below has failed. Fix it and run the checks again.
                </div>
            <?php elseif ($hasWarn): ?>
                <div class="alert alert-warning" style="margin-top:14px;">
                    There are warnings. You can continue anyway, but say why — it goes in the record.
                </div>
                <form method="POST" style="margin-top:12px;">
                    <input type="hidden" name="action" value="transition">
                    <input type="hidden" name="transition_key" value="<?= htmlspecialchars($checkResults['transition_key']) ?>">
                    <input type="hidden" name="override_warnings" value="1">
                    <label style="display:block;margin-bottom:8px;">Reason (recorded):</label>
                    <textarea name="notes" rows="2" style="width:100%;margin-bottom:8px;"
                              placeholder="e.g. Two students had no preferences — confirmed with them directly."></textarea>
                    <button type="submit" class="btn btn-primary" style="width:auto;">Continue anyway</button>
                </form>
            <?php else: ?>
                <div class="alert alert-success" style="margin-top:14px;">
                    Everything passed. You can continue.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- =========================== NEXT STEP ============================ -->
<?php if (empty($ordered) && !$cancelAvailable): ?>
    <div class="card empty-state">
        <p><?= $isCancelled ? 'This cycle was cancelled.' : 'The cycle is finished. There is nothing further to do here.' ?></p>
    </div>
<?php else: ?>
    <?php foreach ($ordered as $key => $trans): ?>
        <?php $isForward = ($key === $forwardKey); ?>
        <div class="card next-card<?= $isForward ? ' next-primary' : '' ?>">
            <h3 style="margin:0 0 6px;"><?= htmlspecialchars($trans['label']) ?></h3>
            <p style="margin:0 0 14px;font-size:14px;color:var(--slate);">
                <?= htmlspecialchars($EFFECTS[$key] ?? ('Moves the cycle to ' . $trans['target'] . '.')) ?>
            </p>

            <?php if (!empty($trans['requires'])): ?>
                <!-- This step has checks, so they are run first and the result is
                     shown above before anything moves. -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="preview_checks">
                    <input type="hidden" name="transition_key" value="<?= htmlspecialchars($key) ?>">
                    <button type="submit" class="btn btn-secondary" style="width:auto;">Run checks</button>
                </form>
            <?php endif; ?>

            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="transition">
                <input type="hidden" name="transition_key" value="<?= htmlspecialchars($key) ?>">
                <button type="submit" class="btn <?= $isForward ? 'btn-primary' : 'btn-secondary' ?>" style="width:auto;"
                        onclick="return confirm('<?= htmlspecialchars(addslashes($trans['label'])) ?> — are you ready?');">
                    <?= $isForward ? 'Next →' : htmlspecialchars($trans['label']) ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ============================ CANCEL ============================== -->
<?php if ($cancelAvailable): ?>
    <div class="card cancel-card">
        <h3 style="margin:0 0 6px;">Cancel this cycle</h3>
        <p style="margin:0 0 14px;font-size:14px;color:var(--slate);">
            <?= htmlspecialchars($EFFECTS['cancel']) ?>
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="transition">
            <input type="hidden" name="transition_key" value="cancel">
            <button type="submit" class="btn btn-danger" style="width:auto;"
                    onclick="return confirm('Cancel <?= htmlspecialchars(addslashes($cycle['name'])) ?>? Students will stop seeing offers immediately. This cannot be undone.');">
                Cancel cycle
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- ====================== START A NEW CYCLE ========================= -->
<?php $cycleFinished = in_array($state, ['FINAL_PUBLICATION', 'CANCELLED'], true); ?>
<details class="card">
    <summary style="cursor:pointer;font-family:var(--font-serif);font-size:18px;color:var(--ink-navy);">
        Start a new cycle
    </summary>

    <?php if (!$cycleFinished): ?>
        <div class="alert-warning" style="margin:14px 0;">
            <strong><?= htmlspecialchars($cycle['name']) ?></strong> is still running
            (<?= htmlspecialchars($stateDef['label'] ?? $state) ?>).
            Starting a new cycle retires this one and you cannot go back to it.
        </div>
    <?php endif; ?>

    <form method="POST" class="form-narrow" style="margin-top:14px;">
        <input type="hidden" name="action" value="create_cycle">
        <label for="new_name">Cycle name</label>
        <input type="text" id="new_name" name="name" required placeholder="e.g. Summer 2026">
        <label for="new_start">Start date</label>
        <input type="date" id="new_start" name="start_date" required value="<?= date('Y-m-d') ?>">
        <?php if (!$cycleFinished): ?>
            <label style="margin-top:10px;">
                <input type="checkbox" name="confirm_replace" required
                       style="width:auto;display:inline-block;margin-right:6px;">
                I understand this retires <?= htmlspecialchars($cycle['name']) ?>.
            </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary" style="width:auto;margin-top:16px;">Create cycle</button>
    </form>
</details>

<p style="font-size:13px;color:var(--slate-light);">
    The full history of every step in this cycle is on the
    <a href="<?= BASE_URL ?>/admin/cycles.php">Academic cycles</a> page.
</p>

<style>
/* ---- step bar ---- */
.stepbar { display:flex; gap:4px; margin-bottom:18px; flex-wrap:wrap; }
.step { flex:1 1 120px; text-align:center; padding:10px 6px; border-radius:4px; background:var(--paper); border:1px solid var(--border); }
.step-dot { width:26px; height:26px; line-height:26px; border-radius:50%; margin:0 auto 6px; font-family:var(--font-mono); font-size:13px; background:var(--border); color:var(--slate); }
.step-label { font-size:12px; color:var(--slate); }
.step-done .step-dot { background:#dcfce7; color:#166534; }
.step-done .step-label { color:var(--slate); }
.step-now { border-color:var(--strait-blue); background:white; }
.step-now .step-dot { background:var(--strait-blue); color:white; }
.step-now .step-label { color:var(--ink-navy); font-weight:600; }

/* ---- hero ---- */
.fsm-hero { background: linear-gradient(135deg, var(--parchment) 0%, white 100%); padding: 28px; }
.fsm-hero-label { font-family: var(--font-mono); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--slate); }
.fsm-hero-state { font-family: var(--font-serif); font-size: 30px; margin: 8px 0 12px 0; color: var(--ink-navy); }
.fsm-hero-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.fsm-hero-desc { color: var(--slate); font-size: 14px; margin: 0; }
.pill-mono { font-family: var(--font-mono); font-size: 11px; }
.pill-terminal { background: var(--mosaic-gold); color: white; }

/* ---- next / cancel ---- */
.next-card { border-left: 3px solid var(--border); }
.next-primary { border-left-color: var(--strait-blue); }
.cancel-card { border-left: 3px solid #c04040; }
.btn-danger { background:#c04040; color:white; border:1px solid #a03030; }
.btn-danger:hover { background:#a03030; }

/* ---- checks ---- */
.check-list { display: flex; flex-direction: column; gap: 8px; }
.check-row { display: grid; grid-template-columns: 28px 1fr; gap: 10px; padding: 10px; border: 1px solid var(--border); border-radius: 4px; }
.check-pass { background: #f4f9f4; border-color: #cfe3cf; }
.check-warn { background: #fff8e1; border-color: var(--mosaic-gold); }
.check-fail { background: #fdeaea; border-color: #c04040; }
.check-icon { font-size: 20px; text-align: center; }
.check-pass .check-icon { color: #4a8f4a; }
.check-warn .check-icon { color: #806300; }
.check-fail .check-icon { color: #c04040; }
.check-title { font-weight: 500; font-size: 14px; }
.check-message { font-size: 13px; color: var(--slate); margin-top: 2px; }
.check-details { margin-top: 8px; font-size: 12px; }
.check-details pre { background: white; padding: 8px; border-radius: 3px; overflow-x: auto; font-size: 11px; max-height: 200px; }

.alert-warning { background: #fff8e1; border: 1px solid var(--mosaic-gold); color: #806300; padding: 12px; border-radius: 4px; }
</style>

<?php require '../includes/footer.php'; ?>