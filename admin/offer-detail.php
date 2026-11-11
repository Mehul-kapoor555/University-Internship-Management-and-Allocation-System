<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$me = currentUser();
$activePage = 'offers-review';
$error = '';

/**
 * Full review page for a single offer.
 *
 * The approve/reject decision lives here rather than on the queue, so the
 * coordinator has read the whole description, the eligibility rules, the
 * deadline and the required skills before publishing anything to the student
 * body. Either decision notifies the poster: previously an offer could be
 * approved or rejected and the professor or company was never told, so they
 * had no way to know their submission had been handled.
 */

$offerId = (int)($_GET['id'] ?? 0);
if ($offerId <= 0) {
    header('Location: ' . BASE_URL . '/admin/offer-reviews.php');
    exit;
}

// ---------------------------------------------------------------- decision --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    $stmt = $db->prepare("
        SELECT o.id, o.title, o.posted_by_user_id, u.role AS poster_role
        FROM internship_offers o
        JOIN users u ON u.id = o.posted_by_user_id
        WHERE o.id = ?
    ");
    $stmt->execute([$offerId]);
    $target = $stmt->fetch();

    if (!$target) {
        $error = 'That offer no longer exists.';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $error = 'Unknown action.';
    } elseif ($action === 'reject' && $reason === '') {
        $error = 'A rejection needs a reason. The poster has to know what to fix.';
    } else {
        try {
            $db->beginTransaction();

            $newStatus = $action === 'approve' ? 'open' : 'archived';
            $db->prepare("UPDATE internship_offers SET status = ? WHERE id = ?")
               ->execute([$newStatus, $offerId]);

            // Send the poster back to their own offers list, which differs by role.
            $posterLink = $target['poster_role'] === 'company'
                ? BASE_URL . '/company/my-offers.php'
                : BASE_URL . '/professor/my-offers.php';

            if ($action === 'approve') {
                notify(
                    $db, (int)$target['posted_by_user_id'],
                    'Offer approved',
                    'Your offer "' . $target['title'] . '" has been approved and is now visible to students.'
                        . ($reason !== '' ? ' Coordinator note: ' . $reason : ''),
                    'success',
                    $posterLink
                );
            } else {
                notify(
                    $db, (int)$target['posted_by_user_id'],
                    'Offer not approved',
                    'Your offer "' . $target['title'] . '" was not approved. Reason: ' . $reason,
                    'warning',
                    $posterLink
                );
            }

            $db->commit();

            $flag = $action === 'approve' ? 'approved' : 'rejected';
            header('Location: ' . BASE_URL . '/admin/offer-reviews.php?' . $flag . '=1');
            exit;

        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Could not record the decision: ' . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------- load ---
$stmt = $db->prepare("
    SELECT o.*,
           u.first_name, u.last_name, u.email AS poster_email, u.role AS poster_role,
           c.company_name, c.industry, c.website,
           p.department
    FROM internship_offers o
    JOIN users u ON u.id = o.posted_by_user_id
    LEFT JOIN companies  c ON c.user_id = u.id
    LEFT JOIN professors p ON p.user_id = u.id
    WHERE o.id = ?
");
$stmt->execute([$offerId]);
$offer = $stmt->fetch();

if (!$offer) {
    $activePage = 'offers-review';
    require '../includes/header.php';
    echo '<div class="alert alert-error">That offer does not exist.</div>';
    echo '<p><a href="' . BASE_URL . '/admin/offer-reviews.php">← Back to review queue</a></p>';
    require '../includes/footer.php';
    exit;
}

$stmt = $db->prepare("
    SELECT s.name, os.importance, os.minimum_proficiency
    FROM offer_skills os
    JOIN skills s ON s.id = os.skill_id
    WHERE os.offer_id = ?
    ORDER BY os.importance ASC, s.name ASC
");
$stmt->execute([$offerId]);
$skills = $stmt->fetchAll();

$posterName = $offer['company_name'] ?: trim($offer['first_name'] . ' ' . $offer['last_name']);
$isPending  = $offer['status'] === 'draft';

require '../includes/header.php';
?>

<div class="page-header">
    <h1><?= htmlspecialchars($offer['title']) ?></h1>
    <p>
        <span class="protocol">OFF-<?= str_pad($offer['id'], 4, '0', STR_PAD_LEFT) ?></span>
        · <?= htmlspecialchars(ucfirst($offer['type'])) ?> placement
        · <span class="badge badge-<?= $isPending ? 'pending' : ($offer['status'] === 'open' ? 'open' : 'closed') ?>">
            <?= $isPending ? 'Awaiting review' : ($offer['status'] === 'open' ? 'Approved' : 'Rejected') ?>
          </span>
    </p>
</div>

<p><a href="<?= BASE_URL ?>/admin/offer-reviews.php">← Back to review queue</a></p>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <h3>Description</h3>
    <p style="white-space:pre-wrap;margin-top:8px;"><?= htmlspecialchars($offer['description']) ?></p>
</div>

<div class="card">
    <h3>Placement terms</h3>
    <div class="field-row"><span>Domain</span><span><?= htmlspecialchars($offer['domain'] ?: '—') ?></span></div>
    <div class="field-row"><span>Capacity</span><span><?= (int)$offer['capacity'] ?> student<?= (int)$offer['capacity'] === 1 ? '' : 's' ?></span></div>
    <div class="field-row"><span>Duration</span><span><?= (int)$offer['duration_months'] ?> months</span></div>
    <div class="field-row">
        <span>Compensation</span>
        <span><?php
            if ((int)$offer['is_paid'] === 1) {
                echo $offer['stipend_monthly'] !== null
                    ? 'Paid · €' . number_format((float)$offer['stipend_monthly'], 2) . ' / month'
                    : 'Paid · amount not stated';
            } else {
                echo 'Unpaid';
            }
        ?></span>
    </div>
    <?php if ($offer['type'] === 'company'): ?>
        <div class="field-row"><span>Location</span><span><?= htmlspecialchars($offer['location'] ?: '—') ?></span></div>
        <div class="field-row"><span>Remote</span><span><?= (int)$offer['is_remote'] === 1 ? 'Available' : 'On site' ?></span></div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Eligibility</h3>
    <div class="field-row"><span>Minimum CFU</span><span><?= $offer['min_cfu'] !== null ? (int)$offer['min_cfu'] : 'No minimum' ?></span></div>
    <div class="field-row"><span>Required program</span><span><?= htmlspecialchars($offer['required_program'] ?: 'Any program') ?></span></div>

    <h3 style="margin-top:18px;">Required skills</h3>
    <?php if (empty($skills)): ?>
        <p style="font-size:13px;color:var(--slate-light);margin-top:8px;">
            No skills attached. Matching will fall back on the description text alone,
            which weakens the recommendation scores for this offer.
        </p>
    <?php else: ?>
        <table style="margin-top:10px;">
            <thead><tr><th>Skill</th><th>Importance</th><th>Minimum level</th></tr></thead>
            <tbody>
            <?php foreach ($skills as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['name']) ?></td>
                    <td><span class="badge badge-<?= $s['importance'] === 'required' ? 'open' : 'draft' ?>"><?= htmlspecialchars(ucfirst($s['importance'])) ?></span></td>
                    <td><?= htmlspecialchars(ucfirst($s['minimum_proficiency'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Posted by</h3>
    <div class="field-row"><span>Name</span><span><?= htmlspecialchars($posterName) ?></span></div>
    <div class="field-row"><span>Role</span><span><?= htmlspecialchars(ucfirst($offer['poster_role'])) ?></span></div>
    <div class="field-row"><span>Account email</span><span><?= htmlspecialchars($offer['poster_email']) ?></span></div>
    <?php if ($offer['contact_email']): ?>
        <div class="field-row"><span>Contact email for this offer</span><span><?= htmlspecialchars($offer['contact_email']) ?></span></div>
    <?php endif; ?>
    <?php if ($offer['poster_role'] === 'professor' && $offer['department']): ?>
        <div class="field-row"><span>Department</span><span><?= htmlspecialchars($offer['department']) ?></span></div>
    <?php endif; ?>
    <?php if ($offer['poster_role'] === 'company'): ?>
        <div class="field-row"><span>Industry</span><span><?= htmlspecialchars($offer['industry'] ?: '—') ?></span></div>
        <?php if ($offer['website']): ?>
            <div class="field-row"><span>Website</span><span><?= htmlspecialchars($offer['website']) ?></span></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="field-row"><span>Submitted</span><span><?= htmlspecialchars(date('d M Y, H:i', strtotime($offer['created_at']))) ?></span></div>
</div>

<div class="card">
    <h3><?= $isPending ? 'Decision' : 'Change decision' ?></h3>
    <?php if (!$isPending): ?>
        <p style="font-size:13px;color:var(--slate);margin:8px 0 14px;">
            This offer has already been
            <strong><?= $offer['status'] === 'open' ? 'approved' : 'rejected' ?></strong>.
            Submitting again overrides that and notifies the poster of the new outcome.
        </p>
    <?php endif; ?>

    <form method="POST">
        <label for="reason">
            Reason / note
            <span style="font-weight:400;color:var(--slate-light);">
                — required when rejecting, optional when approving
            </span>
        </label>
        <textarea id="reason" name="reason" rows="3"
                  placeholder="e.g. The description does not state what the student will actually build."></textarea>

        <div style="margin-top:14px;">
            <button type="submit" name="action" value="approve" class="btn btn-primary"
                    style="width:auto;display:inline-block;">Approve and publish</button>
            <button type="submit" name="action" value="reject" class="btn btn-secondary"
                    style="width:auto;display:inline-block;">Reject</button>
        </div>
        <p style="font-size:12px;color:var(--slate-light);margin-top:10px;">
            Either decision sends a notification to <?= htmlspecialchars($posterName) ?>.
        </p>
    </form>
</div>

<?php require '../includes/footer.php'; ?>