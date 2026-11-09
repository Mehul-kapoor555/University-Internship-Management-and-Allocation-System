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

if (!$cycle) {
    require '../includes/header.php';
    echo '<div class="card empty-state"><p>No active academic cycle. <a href="' . BASE_URL . '/admin/cycles.php">Create one</a> to get started.</p></div>';
    require '../includes/footer.php';
    exit;
}

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
            $message = 'Transitioned to: ' . $result['new_state'];
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

$stateDef = $fsm->stateDef($cycle['current_state']);
$transitions = $fsm->availableTransitions($cycle['current_state']);
$history = $fsm->transitionHistory((int)$cycle['id']);

/* Decision summary — shown once results are published so the coordinator can
   see who accepted / rejected / timed out before choosing to run another round
   or finalise the cycle. */
$decisionRows = [];
$decisionCounts = ['accepted' => 0, 'rejected' => 0, 'timed_out' => 0, 'pending' => 0];
if (in_array($cycle['current_state'],
        ['RESULTS_PUBLISHED', 'POST_DECISION_INTEGRITY', 'FINAL_PUBLICATION'], true)) {
    $stmt = $db->prepare("
        SELECT a.decision, a.decision_at, a.round_number,
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
        if (isset($decisionCounts[$d['decision']])) {
            $decisionCounts[$d['decision']]++;
        }
    }
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Cycle control — <?= htmlspecialchars($cycle['name']) ?></h1>
    <p>State machine for the internship cycle. Every phase change is guarded, audited, and manual.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Current state hero -->
<div class="card fsm-hero">
    <div class="fsm-hero-label">Current state</div>
    <h2 class="fsm-hero-state"><?= htmlspecialchars($stateDef['label'] ?? $cycle['current_state']) ?></h2>
    <div class="fsm-hero-meta">
        <span class="pill">Round <?= (int)$cycle['current_round'] ?></span>
        <span class="pill pill-mono"><?= htmlspecialchars($cycle['current_state']) ?></span>
        <?php if (!empty($stateDef['is_terminal'])): ?>
            <span class="pill pill-terminal">Terminal state</span>
        <?php endif; ?>
    </div>
    <p class="fsm-hero-desc"><?= htmlspecialchars($stateDef['description'] ?? '') ?></p>
    <div class="fsm-hero-visibility">
        Visible to:
        <?php foreach (($stateDef['visible_to'] ?? []) as $role): ?>
            <span class="pill pill-role"><?= htmlspecialchars($role) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Decision summary (post-publication) -->
<?php if (!empty($decisionRows)): ?>
    <div class="card">
        <h2 class="section-heading" style="margin-top:0;">Student decisions</h2>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px;">
            <span class="pill" style="background:#dcfce7;color:#166534;">Accepted: <?= $decisionCounts['accepted'] ?></span>
            <span class="pill" style="background:#fee2e2;color:#991b1b;">Rejected: <?= $decisionCounts['rejected'] ?></span>
            <span class="pill" style="background:#f3f4f6;color:#374151;">Timed out: <?= $decisionCounts['timed_out'] ?></span>
            <span class="pill" style="background:#fef9c3;color:#854d0e;">Pending: <?= $decisionCounts['pending'] ?></span>
        </div>
        <?php if ($cycle['current_state'] === 'RESULTS_PUBLISHED' && $decisionCounts['pending'] > 0): ?>
            <p style="color:var(--slate-light);margin-top:0;">
                Waiting on <?= $decisionCounts['pending'] ?> student(s). Closing the decision
                window will mark any remaining pending responses as timed out.
            </p>
        <?php endif; ?>
        <table>
            <thead>
                <tr><th>Student</th><th>Offer</th><th>Round</th><th>Decision</th><th>Responded</th></tr>
            </thead>
            <tbody>
            <?php foreach ($decisionRows as $d): ?>
                <?php
                    $cls = $d['decision'] === 'accepted' ? 'accepted'
                         : ($d['decision'] === 'pending' ? 'pending' : 'rejected');
                    $label = [
                        'accepted' => 'Accepted', 'rejected' => 'Rejected',
                        'timed_out' => 'Timed out', 'pending' => 'Pending',
                    ][$d['decision']] ?? ucfirst($d['decision']);
                ?>
                <tr>
                    <td><?= htmlspecialchars($d['student_name']) ?> <span class="offer-meta">(<?= htmlspecialchars($d['matricola']) ?>)</span></td>
                    <td><?= htmlspecialchars($d['title']) ?></td>
                    <td>R<?= (int)$d['round_number'] ?></td>
                    <td><span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
                    <td><?= $d['decision_at'] ? htmlspecialchars(date('d M Y, H:i', strtotime($d['decision_at']))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Available transitions -->
<?php if (empty($transitions)): ?>
    <div class="card empty-state">
        <p>No transitions available from this state — cycle is in a terminal state.</p>
    </div>
<?php else: ?>
    <h2 class="section-heading">Available transitions</h2>
    <div class="transition-grid">
        <?php foreach ($transitions as $key => $trans): ?>
            <div class="transition-card">
                <div class="transition-header">
                    <div class="transition-label"><?= htmlspecialchars($trans['label']) ?></div>
                    <div class="transition-arrow">→ <span class="mono"><?= htmlspecialchars($trans['target']) ?></span></div>
                </div>
                <?php if (!empty($trans['requires'])): ?>
                    <div class="transition-checks">
                        <div class="tc-label">Requires:</div>
                        <ul>
                            <?php foreach ($trans['requires'] as $req): ?>
                                <li class="mono"><?= htmlspecialchars($req) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="transition-actions">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="preview_checks">
                        <input type="hidden" name="transition_key" value="<?= htmlspecialchars($key) ?>">
                        <button type="submit" class="btn btn-secondary" style="width:auto;">Preview checks</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="transition">
                        <input type="hidden" name="transition_key" value="<?= htmlspecialchars($key) ?>">
                        <button type="submit" class="btn btn-primary" style="width:auto;"
                                onclick="return confirm('Fire transition &quot;<?= htmlspecialchars($trans['label']) ?>&quot;? This will move the cycle to <?= htmlspecialchars($trans['target']) ?>.');">
                            Advance →
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Integrity check results -->
<?php if ($checkResults): ?>
    <h2 class="section-heading">
        Integrity check results
        <span class="section-subheading mono"><?= htmlspecialchars($checkResults['transition_key']) ?></span>
    </h2>
    <div class="card">
        <?php if (empty($checkResults['results'])): ?>
            <p style="color:var(--slate);">No integrity checks required for this transition. Safe to advance.</p>
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
                    Cannot advance — one or more hard checks failed. Fix the underlying data and re-preview.
                </div>
            <?php elseif ($hasWarn): ?>
                <div class="alert alert-warning" style="margin-top:14px;">
                    Warnings present. To advance despite warnings, use the override below.
                </div>
                <form method="POST" style="margin-top:12px;">
                    <input type="hidden" name="action" value="transition">
                    <input type="hidden" name="transition_key" value="<?= htmlspecialchars($checkResults['transition_key']) ?>">
                    <input type="hidden" name="override_warnings" value="1">
                    <label style="display:block;margin-bottom:8px;">Notes (recorded in audit log):</label>
                    <textarea name="notes" rows="2" style="width:100%;margin-bottom:8px;"
                              placeholder="e.g. Two applicants had incomplete preferences — verified out-of-band with the students."></textarea>
                    <button type="submit" class="btn btn-primary" style="width:auto;">Override warnings and advance</button>
                </form>
            <?php else: ?>
                <div class="alert alert-success" style="margin-top:14px;">
                    All checks passed. Safe to advance.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Audit trail -->
<h2 class="section-heading">Cycle audit trail</h2>
<div class="card">
    <?php if (empty($history)): ?>
        <p style="color:var(--slate);">No transitions yet.</p>
    <?php else: ?>
        <table class="history-table">
            <thead>
                <tr><th>When</th><th>From</th><th></th><th>To</th><th>Round</th><th>Trigger</th><th>By</th><th>Guard</th></tr>
            </thead>
            <tbody>
                <?php foreach ($history as $t): ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars($t['transitioned_at']) ?></td>
                        <td class="mono"><?= htmlspecialchars($t['from_state']) ?></td>
                        <td>→</td>
                        <td class="mono"><?= htmlspecialchars($t['to_state']) ?></td>
                        <td>R<?= (int)$t['round_number'] ?></td>
                        <td class="mono"><?= htmlspecialchars($t['transition_key']) ?></td>
                        <td><?= htmlspecialchars($t['triggered_by_name'] ?? '—') ?></td>
                        <td>
                            <span class="pill pill-<?= $t['guard_result'] === 'overridden' ? 'warn' : 'ok' ?>">
                                <?= htmlspecialchars($t['guard_result']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php if (!empty($t['notes'])): ?>
                        <tr><td colspan="8" class="history-notes"><?= htmlspecialchars($t['notes']) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.fsm-hero { background: linear-gradient(135deg, var(--parchment) 0%, white 100%); padding: 28px; }
.fsm-hero-label { font-family: var(--font-mono); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--slate); }
.fsm-hero-state { font-family: var(--font-serif); font-size: 32px; margin: 8px 0 12px 0; color: var(--ink-navy); }
.fsm-hero-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.fsm-hero-desc { color: var(--slate); font-size: 14px; margin: 0 0 12px 0; }
.fsm-hero-visibility { font-size: 12px; color: var(--slate); }

.pill { display: inline-block; padding: 3px 10px; background: white; border: 1px solid var(--border); border-radius: 20px; font-size: 12px; }
.pill-mono { font-family: var(--font-mono); }
.pill-terminal { background: var(--parchment); color: var(--slate); }
.pill-role { background: var(--strait-blue); color: white; border-color: var(--strait-blue); font-family: var(--font-mono); font-size: 11px; margin-left: 4px; }
.pill-ok { background: #f0f9f0; color: #2d5f2d; border-color: #a8d0a8; }
.pill-warn { background: #fff8e1; color: #806300; border-color: var(--mosaic-gold); }

.section-heading { margin: 30px 0 12px 0; font-size: 18px; display: flex; align-items: baseline; gap: 12px; }
.section-subheading { font-size: 12px; color: var(--slate); font-weight: normal; }

.transition-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; }
.transition-card { background: white; border: 1px solid var(--border); border-radius: 4px; padding: 16px; }
.transition-header { margin-bottom: 12px; }
.transition-label { font-weight: 500; font-size: 15px; margin-bottom: 4px; }
.transition-arrow { font-size: 12px; color: var(--slate); }
.transition-checks { margin: 10px 0; padding: 10px; background: var(--paper); border-radius: 3px; }
.tc-label { font-size: 11px; color: var(--slate); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.transition-checks ul { margin: 0; padding-left: 18px; font-size: 12px; }
.transition-actions { display: flex; gap: 8px; margin-top: 12px; }

.check-list { display: flex; flex-direction: column; gap: 8px; }
.check-row { display: grid; grid-template-columns: 32px 1fr; gap: 12px; padding: 12px; border-radius: 4px; border: 1px solid transparent; }
.check-pass { background: #f0f9f0; border-color: #a8d0a8; }
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

.history-table { width: 100%; }
.history-table td, .history-table th { font-size: 13px; padding: 8px; }
.history-table .mono { font-family: var(--font-mono); font-size: 12px; }
.history-notes { background: var(--parchment); font-size: 12px; color: var(--slate); font-style: italic; padding-left: 24px; }

.alert-warning { background: #fff8e1; border: 1px solid var(--mosaic-gold); color: #806300; padding: 12px; border-radius: 4px; }
</style>

<?php require '../includes/footer.php'; ?>