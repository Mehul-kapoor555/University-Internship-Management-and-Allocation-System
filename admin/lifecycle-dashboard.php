<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/LifecycleStateMachine.php';
requireRole(['coordinator']);

$me = currentUser();
$activePage = 'lifecycle';
$engine = new LifecycleStateMachine($db);
$message = '';
$error = '';

$activeCycle = $db->query("SELECT * FROM academic_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'spawn' && $activeCycle) {
        $n = $engine->spawnForCycle((int)$activeCycle['id']);
        $message = $n > 0
            ? "Spawned $n lifecycle instance(s) for accepted placements."
            : 'No new placements to spawn (every accepted placement already has a lifecycle).';

    } elseif ($action === 'fire') {
        $wfId = (int)($_POST['workflow_id'] ?? 0);
        $trans = $_POST['transition'] ?? '';
        $comment = trim($_POST['comment'] ?? '') ?: null;
        $res = $engine->fire($wfId, $trans, 'user', (int)$me['id'], $comment);
        if ($res['ok']) {
            $message = "Transition '$trans' fired. New state: " . $res['new_state'] . '.';
        } else {
            $error = $res['reason'];
        }
    }
}

// All placements with lifecycle state (accepted allocations in the active cycle).
$placements = [];
if ($activeCycle) {
    $stmt = $db->prepare("
        SELECT w.id AS workflow_id, w.current_state, w.correlation_token,
               a.id AS allocation_id,
               o.title AS offer_title,
               CONCAT(su.first_name,' ',su.last_name) AS student_name,
               s.matricola
        FROM workflow_states w
        JOIN allocations a       ON a.id = w.allocation_id
        JOIN matching_rounds r   ON r.id = a.round_id
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN students s          ON s.id = a.student_id
        JOIN users su            ON su.id = s.user_id
        WHERE r.cycle_id = ?
        ORDER BY w.id DESC
    ");
    $stmt->execute([(int)$activeCycle['id']]);
    $placements = $stmt->fetchAll();
}

// Count accepted placements not yet spawned.
$unspawned = 0;
if ($activeCycle) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM allocations a
        JOIN matching_rounds r ON r.id = a.round_id
        WHERE r.cycle_id = ? AND a.decision = 'accepted'
          AND a.id NOT IN (SELECT allocation_id FROM workflow_states)
    ");
    $stmt->execute([(int)$activeCycle['id']]);
    $unspawned = (int)$stmt->fetchColumn();
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Internship lifecycle</h1>
    <p>Each accepted placement runs its own lifecycle state machine — the twin of the
       cycle machine. Documents, forms, and emails fire on entry to each state.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$activeCycle): ?>
    <div class="card empty-state"><p>No active cycle.</p></div>
<?php else: ?>

    <?php if ($unspawned > 0): ?>
        <div class="card" style="border-left:4px solid #d97706;">
            <p style="margin:0 0 12px;"><?= $unspawned ?> accepted placement(s) have no lifecycle yet.</p>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="spawn">
                <button type="submit" class="btn btn-primary" style="width:auto;">Spawn lifecycle instances</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (empty($placements)): ?>
        <div class="card empty-state"><p>No placements in the lifecycle yet. Spawn instances once students have accepted.</p></div>
    <?php else: ?>
        <?php foreach ($placements as $p): ?>
            <?php
                $def = $engine->stateDef($p['current_state']);
                $label = $def['label'] ?? $p['current_state'];
                $transitions = $engine->availableTransitions($p['current_state']);
                // Coordinator-firable transitions only (webhook/professor ones excluded).
                $coordTrans = array_filter($transitions, function ($t) {
                    return ($t['trigger'] ?? 'user') === 'coordinator';
                });
                $missing = $p['current_state'] === 'final_docs'
                    ? $engine->finalJoinMissing((int)$p['workflow_id']) : [];
            ?>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                    <div>
                        <strong><?= htmlspecialchars($p['offer_title']) ?></strong>
                        <div class="offer-meta"><?= htmlspecialchars($p['student_name']) ?> · <?= htmlspecialchars($p['matricola']) ?></div>
                    </div>
                    <span class="badge badge-<?= in_array($p['current_state'], ['closed'], true) ? 'open' : (in_array($p['current_state'], ['rejected'], true) ? 'closed' : 'pending') ?>">
                        <?= htmlspecialchars($label) ?>
                    </span>
                </div>

                <?php if (!empty($def['description'])): ?>
                    <p style="font-size:13px;color:var(--slate-light);margin-top:10px;"><?= htmlspecialchars($def['description']) ?></p>
                <?php endif; ?>

                <?php if ($p['current_state'] === 'final_docs'): ?>
                    <div style="font-size:13px;margin-top:6px;">
                        Final documents received:
                        <?php foreach (['final_report'=>'Report','company_evaluation'=>'Company eval','professor_assessment'=>'Prof assessment'] as $k=>$lbl): ?>
                            <span class="badge badge-<?= in_array($k,$missing,true) ? 'pending' : 'open' ?>"><?= $lbl ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($coordTrans)): ?>
                    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                        <?php foreach ($coordTrans as $key => $t): ?>
                            <form method="post" style="margin:0;"
                                  onsubmit="return confirm('<?= htmlspecialchars(addslashes($t['label'])) ?>?');">
                                <input type="hidden" name="action" value="fire">
                                <input type="hidden" name="workflow_id" value="<?= (int)$p['workflow_id'] ?>">
                                <input type="hidden" name="transition" value="<?= htmlspecialchars($key) ?>">
                                <button type="submit" class="btn btn-secondary" style="width:auto;"><?= htmlspecialchars($t['label']) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-size:13px;color:var(--slate);">History &amp; documents</summary>
                    <?php
                        $hist = $engine->history((int)$p['workflow_id']);
                        $docs = $db->prepare("SELECT * FROM generated_documents WHERE workflow_id = ? ORDER BY id");
                        $docs->execute([(int)$p['workflow_id']]);
                        $docRows = $docs->fetchAll();
                    ?>
                    <?php if ($hist): ?>
                        <table style="margin-top:10px;">
                            <thead><tr><th>From</th><th>To</th><th>Transition</th><th>By</th><th>When</th></tr></thead>
                            <tbody>
                            <?php foreach ($hist as $h): ?>
                                <tr>
                                    <td class="offer-meta"><?= htmlspecialchars($h['from_state']) ?></td>
                                    <td class="offer-meta"><?= htmlspecialchars($h['to_state']) ?></td>
                                    <td><?= htmlspecialchars($h['transition']) ?></td>
                                    <td><?= htmlspecialchars($h['triggered_by_name'] ?? $h['trigger_source']) ?></td>
                                    <td class="offer-meta"><?= htmlspecialchars(date('d M H:i', strtotime($h['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="offer-meta" style="margin-top:8px;">No transitions yet.</p>
                    <?php endif; ?>
                    <?php if ($docRows): ?>
                        <p style="margin-top:10px;font-size:13px;"><strong>Generated documents:</strong>
                        <?php foreach ($docRows as $d): ?>
                            <span class="badge badge-open"><?= htmlspecialchars($d['doc_type']) ?> (<?= htmlspecialchars($d['format']) ?>)</span>
                        <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                </details>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>

<?php require '../includes/footer.php'; ?>
