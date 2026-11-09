<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/LifecycleStateMachine.php';
requireRole(['professor']);

$me = currentUser();
$activePage = 'supervised';
$engine = new LifecycleStateMachine($db);
$message = '';
$error = '';

// This professor's professors.id
$prof = $db->prepare("SELECT id FROM professors WHERE user_id = ?");
$prof->execute([$me['id']]);
$profRow = $prof->fetch();
$profId = $profRow['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profId) {
    $wfId  = (int)($_POST['workflow_id'] ?? 0);
    $trans = $_POST['transition'] ?? '';
    $comment = trim($_POST['comment'] ?? '') ?: null;

    // Ownership check: the workflow must belong to a placement this professor supervises.
    $own = $db->prepare("
        SELECT w.id FROM workflow_states w
        JOIN supervisor_assignments sa ON sa.allocation_id = w.allocation_id
        WHERE w.id = ? AND sa.professor_id = ?
    ");
    $own->execute([$wfId, $profId]);
    if (!$own->fetch()) {
        $error = 'That placement is not one you supervise.';
    } else {
        $res = $engine->fire($wfId, $trans, 'user', (int)$me['id'], $comment);
        if ($res['ok']) $message = "Done. New state: " . $res['new_state'] . '.';
        else $error = $res['reason'];
    }
}

$placements = [];
if ($profId) {
    $stmt = $db->prepare("
        SELECT w.id AS workflow_id, w.current_state,
               o.title AS offer_title,
               CONCAT(su.first_name,' ',su.last_name) AS student_name
        FROM workflow_states w
        JOIN supervisor_assignments sa ON sa.allocation_id = w.allocation_id
        JOIN allocations a       ON a.id = w.allocation_id
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN students s          ON s.id = a.student_id
        JOIN users su            ON su.id = s.user_id
        WHERE sa.professor_id = ?
        ORDER BY w.id DESC
    ");
    $stmt->execute([$profId]);
    $placements = $stmt->fetchAll();
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Supervised placements</h1>
    <p>Placements you supervise, and their point in the internship lifecycle.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (empty($placements)): ?>
    <div class="card empty-state"><p>You are not supervising any placements yet.</p></div>
<?php else: ?>
    <?php foreach ($placements as $p): ?>
        <?php
            $def = $engine->stateDef($p['current_state']);
            $label = $def['label'] ?? $p['current_state'];
            $trans = $engine->availableTransitions($p['current_state']);
            $profTrans = array_filter($trans, fn($t) => ($t['trigger'] ?? 'user') === 'professor');
        ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
                <div>
                    <strong><?= htmlspecialchars($p['offer_title']) ?></strong>
                    <div class="offer-meta"><?= htmlspecialchars($p['student_name']) ?></div>
                </div>
                <span class="badge badge-pending"><?= htmlspecialchars($label) ?></span>
            </div>
            <?php if (!empty($profTrans)): ?>
                <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                    <?php foreach ($profTrans as $key => $t): ?>
                        <form method="post" style="margin:0;"
                              onsubmit="return confirm('<?= htmlspecialchars(addslashes($t['label'])) ?>?');">
                            <input type="hidden" name="workflow_id" value="<?= (int)$p['workflow_id'] ?>">
                            <input type="hidden" name="transition" value="<?= htmlspecialchars($key) ?>">
                            <button type="submit" class="btn btn-secondary" style="width:auto;"><?= htmlspecialchars($t['label']) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="offer-meta" style="margin-top:10px;">No action needed from you at this stage.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>
