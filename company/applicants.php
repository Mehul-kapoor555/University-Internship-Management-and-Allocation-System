<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/engine_runner.php';
requireRole(['company']);

$user = currentUser();
$activePage = 'applicants';
$refreshMessage = '';
$refreshError = '';

// Applicants are shown for the cycle running now. Applications carry a cycle,
// and without the filter an offer listed applicants from every cycle it has
// ever been open in.
$cycleId = activeCycleId($db);

// Load this company's offers (with app counts)
$stmt = $db->prepare("
    SELECT o.id, o.title, o.status, o.filled, o.capacity,
           (SELECT COUNT(*) FROM applications a
             WHERE a.offer_id = o.id AND a.cycle_id = ? AND a.status != 'withdrawn') AS app_count
    FROM internship_offers o
    WHERE o.posted_by_user_id = ?
    ORDER BY o.status = 'open' DESC, o.created_at DESC
");
$stmt->execute([$cycleId, $user['id']]);
$offers = $stmt->fetchAll();

$selectedOfferId = (int)($_GET['offer_id'] ?? 0);

// Handle status change (accept/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id'])) {
    $appId = (int)$_POST['application_id'];
    $newStatus = $_POST['new_status'] ?? '';
    if (in_array($newStatus, ['accepted', 'rejected'], true)) {
        $stmt = $db->prepare("
            UPDATE applications a
            JOIN internship_offers o ON o.id = a.offer_id
            SET a.status = ?
            WHERE a.id = ? AND o.posted_by_user_id = ?
        ");
        $stmt->execute([$newStatus, $appId, $user['id']]);

        // Notify student
        $stmt = $db->prepare("
            SELECT s.user_id, o.title
            FROM applications a
            JOIN students s ON s.id = a.student_id
            JOIN internship_offers o ON o.id = a.offer_id
            WHERE a.id = ?
        ");
        $stmt->execute([$appId]);
        if ($row = $stmt->fetch()) {
            notify($db, $row['user_id'],
                'Application ' . $newStatus,
                'Your application to "' . $row['title'] . '" was ' . $newStatus . '.',
                $newStatus === 'accepted' ? 'success' : 'info',
                BASE_URL . '/applications.php');
        }
    }
}

// Handle rank refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_rank']) && $selectedOfferId) {
    // Confirm the offer belongs to this company
    $stmt = $db->prepare("SELECT id FROM internship_offers WHERE id = ? AND posted_by_user_id = ?");
    $stmt->execute([$selectedOfferId, $user['id']]);
    if ($stmt->fetch()) {
        $result = EngineRunner::run('applicant_ranking', ['offer-id' => $selectedOfferId]);
        if ($result['success']) {
            $refreshMessage = "Ranking refreshed ({$result['data']['applicants_ranked']} applicants, {$result['duration_ms']} ms).";
        } else {
            $refreshError = 'Refresh failed: ' . $result['error'];
        }
    }
}

// Load ranked applicants for the selected offer
$applicants = [];
$selectedOffer = null;
if ($selectedOfferId) {
    // Confirm ownership
    $stmt = $db->prepare("SELECT * FROM internship_offers WHERE id = ? AND posted_by_user_id = ?");
    $stmt->execute([$selectedOfferId, $user['id']]);
    $selectedOffer = $stmt->fetch();

    if ($selectedOffer) {
        // Auto-run engine if no rankings yet for this offer
        $stmt = $db->prepare("SELECT COUNT(*) FROM applicant_rankings WHERE offer_id = ?");
        $stmt->execute([$selectedOfferId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $result = EngineRunner::run('applicant_ranking', ['offer-id' => $selectedOfferId]);
            if (!$result['success'] && !$refreshError) {
                $refreshError = 'Auto-ranking failed: ' . $result['error'];
            }
        }

        $stmt = $db->prepare("
            SELECT ar.*, a.status, a.applied_at, a.cover_letter,
                   s.matricola, s.program, s.total_cfu,
                   u.first_name, u.last_name, u.email
            FROM applicant_rankings ar
            JOIN applications a ON a.id = ar.application_id
            JOIN students s ON s.id = ar.student_id
            JOIN users u ON u.id = s.user_id
            WHERE ar.offer_id = ? AND a.cycle_id = ?
            ORDER BY ar.rank_position ASC
        ");
        $stmt->execute([$selectedOfferId, $cycleId]);
        $applicants = $stmt->fetchAll();
    }
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Applicants</h1>
    <p>Select one of your offers to see applicants, ranked by fit.</p>
</div>

<?php if ($refreshMessage): ?><div class="alert alert-success"><?= htmlspecialchars($refreshMessage) ?></div><?php endif; ?>
<?php if ($refreshError): ?><div class="alert alert-error"><?= htmlspecialchars($refreshError) ?></div><?php endif; ?>

<?php if (empty($offers)): ?>
    <div class="card empty-state"><p>You haven't posted any offers yet.</p></div>
<?php else: ?>

    <div class="card">
        <form method="GET">
            <label for="offer_id">Choose offer</label>
            <select id="offer_id" name="offer_id" onchange="this.form.submit()">
                <option value="">— Pick one of your offers —</option>
                <?php foreach ($offers as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $selectedOfferId === (int)$o['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($o['title']) ?> · <?= (int)$o['app_count'] ?> applicant<?= $o['app_count'] == 1 ? '' : 's' ?>
                        (<?= htmlspecialchars($o['status']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($selectedOffer): ?>

        <?php if (empty($applicants)): ?>
            <div class="card empty-state"><p>No applicants yet for this offer.</p></div>
        <?php else: ?>

            <div class="card" style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <h3 style="margin:0;"><?= htmlspecialchars($selectedOffer['title']) ?></h3>
                    <p style="margin:4px 0 0;font-size:13px;color:var(--slate);">
                        <?= count($applicants) ?> applicant<?= count($applicants) == 1 ? '' : 's' ?> · ranked by fit
                    </p>
                </div>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="offer_id" value="<?= $selectedOfferId ?>">
                    <button type="submit" name="refresh_rank" value="1" class="btn btn-secondary" style="width:auto;">↻ Refresh ranking</button>
                </form>
            </div>

            <?php foreach ($applicants as $app): ?>
                <?php $scorePct = round((float)$app['final_score'] * 100); ?>
                <div class="card rec-card">
                    <div class="protocol">APP-<?= str_pad($app['application_id'], 4, '0', STR_PAD_LEFT) ?></div>

                    <div class="rec-header">
                        <div class="rec-score-ring rec-score-<?= $scorePct >= 70 ? 'high' : ($scorePct >= 45 ? 'mid' : 'low') ?>">
                            <span class="rec-score-value"><?= $scorePct ?></span>
                            <span class="rec-score-suffix">%</span>
                        </div>
                        <div style="flex:1;">
                            <h3><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></h3>
                            <div class="offer-meta">
                                <span><?= htmlspecialchars($app['matricola']) ?></span>
                                <span><?= htmlspecialchars($app['program']) ?></span>
                                <span><?= (int)$app['total_cfu'] ?> CFU</span>
                                <span>Applied <?= htmlspecialchars(date('d M Y', strtotime($app['applied_at']))) ?></span>
                                <span class="badge badge-<?= $app['status'] ?>"><?= htmlspecialchars($app['status']) ?></span>
                            </div>
                            <p style="margin-top:10px;"><?= htmlspecialchars($app['explanation']) ?></p>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <?php if ($app['status'] === 'pending'): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="offer_id" value="<?= $selectedOfferId ?>">
                                    <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                    <input type="hidden" name="new_status" value="accepted">
                                    <button type="submit" class="btn btn-primary" style="width:auto;padding:6px 12px;font-size:12px;">Accept</button>
                                </form>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="offer_id" value="<?= $selectedOfferId ?>">
                                    <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                    <input type="hidden" name="new_status" value="rejected">
                                    <button type="submit" class="btn btn-secondary" style="width:auto;padding:6px 12px;font-size:12px;">Reject</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rec-signals">
                        <div class="signal">
                            <div class="signal-label">Semantic fit</div>
                            <div class="signal-bar"><div class="signal-bar-fill" style="width: <?= round((float)$app['semantic_score'] * 100) ?>%;"></div></div>
                            <div class="signal-value"><?= round((float)$app['semantic_score'] * 100) ?>%</div>
                        </div>
                        <div class="signal">
                            <div class="signal-label">Skill coverage</div>
                            <div class="signal-bar"><div class="signal-bar-fill" style="width: <?= round((float)$app['coverage_score'] * 100) ?>%;"></div></div>
                            <div class="signal-value"><?= round((float)$app['coverage_score'] * 100) ?>%</div>
                        </div>
                        <div class="signal">
                            <div class="signal-label">Academic fit</div>
                            <div class="signal-bar"><div class="signal-bar-fill" style="width: <?= round((float)$app['academic_score'] * 100) ?>%;"></div></div>
                            <div class="signal-value"><?= round((float)$app['academic_score'] * 100) ?>%</div>
                        </div>
                        <div class="signal">
                            <div class="signal-label">Recency</div>
                            <div class="signal-bar"><div class="signal-bar-fill" style="width: <?= round((float)$app['recency_score'] * 100) ?>%;"></div></div>
                            <div class="signal-value"><?= round((float)$app['recency_score'] * 100) ?>%</div>
                        </div>
                    </div>

                    <?php if (!empty($app['cover_letter'])): ?>
                        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                            <div class="rec-gaps-label">Cover letter:</div>
                            <p style="font-size:13px;margin-top:6px;"><?= nl2br(htmlspecialchars($app['cover_letter'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

<?php require '../includes/footer.php'; ?>