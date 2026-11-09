<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/webhook_config.php';
require '../includes/LifecycleStateMachine.php';
requireRole(['student']);

$me = currentUser();
$activePage = 'my-placement';
$engine = new LifecycleStateMachine($db);

$student = $db->prepare("SELECT id FROM students WHERE user_id = ?");
$student->execute([$me['id']]);
$sRow = $student->fetch();
$studentId = $sRow['id'] ?? null;

$placements = [];
if ($studentId) {
    $stmt = $db->prepare("
        SELECT w.id AS workflow_id, w.current_state, w.correlation_token,
               o.title AS offer_title,
               CONCAT(pu.first_name,' ',pu.last_name) AS poster_name
        FROM workflow_states w
        JOIN allocations a       ON a.id = w.allocation_id
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN users pu            ON pu.id = o.posted_by_user_id
        WHERE a.student_id = ?
        ORDER BY w.id DESC
    ");
    $stmt->execute([$studentId]);
    $placements = $stmt->fetchAll();
}

// Which form (if any) the student can act on in each state.
function studentFormFor(string $state): ?array {
    // [kind, label]
    return [
        'doc_pending' => ['signed_agreement', 'Sign your agreement'],
        'revising'    => ['revision_resubmit', 'Resubmit revised documents'],
        'final_docs'  => ['final_report', 'Submit your final report'],
    ][$state] ?? null;
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>My placement</h1>
    <p>Track your internship from agreement through to completion.</p>
</div>

<?php if (empty($placements)): ?>
    <div class="card empty-state"><p>You have no active placement yet. Once you accept a match and the coordinator begins documentation, it will appear here.</p></div>
<?php else: ?>
    <?php foreach ($placements as $p): ?>
        <?php
            $def = $engine->stateDef($p['current_state']);
            $label = $def['label'] ?? $p['current_state'];
            $form = studentFormFor($p['current_state']);
            $link = $form
                ? FORM_BASE_URL . '?token=' . urlencode($p['correlation_token']) . '&kind=' . urlencode($form[0])
                : null;
        ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
                <div>
                    <strong><?= htmlspecialchars($p['offer_title']) ?></strong>
                    <div class="offer-meta"><?= htmlspecialchars($p['poster_name']) ?></div>
                </div>
                <span class="badge badge-<?= $p['current_state']==='closed'?'open':($p['current_state']==='rejected'?'closed':'pending') ?>">
                    <?= htmlspecialchars($label) ?>
                </span>
            </div>
            <?php if (!empty($def['description'])): ?>
                <p style="font-size:13px;color:var(--slate-light);margin-top:10px;"><?= htmlspecialchars($def['description']) ?></p>
            <?php endif; ?>
            <?php if ($link): ?>
                <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="btn btn-primary" style="width:auto;margin-top:8px;">
                    <?= htmlspecialchars($form[1]) ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>
