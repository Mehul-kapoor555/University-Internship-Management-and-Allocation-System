<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'offers-review';

/**
 * This page is now a queue, not a decision point.
 *
 * Approve and reject used to sit directly on this list, which meant a
 * coordinator could publish an offer to every student in the faculty without
 * ever having read past the first 200 characters of its description. The
 * decision now lives on offer-detail.php, where the full text, the eligibility
 * rules, the deadline and the required skills are all on screen first.
 */

// Flash message handed back by offer-detail.php after a decision.
$flash = '';
if (isset($_GET['approved'])) {
    $flash = 'Offer approved and published. The poster has been notified.';
} elseif (isset($_GET['rejected'])) {
    $flash = 'Offer rejected. The poster has been notified with your reason.';
}

$offers = $db->query(
    "SELECT o.*,
            COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name,
            (SELECT COUNT(*) FROM offer_skills os WHERE os.offer_id = o.id)  AS skill_count
     FROM internship_offers o
     JOIN users u ON u.id = o.posted_by_user_id
     LEFT JOIN companies c ON c.user_id = u.id
     WHERE o.status = 'draft'
     ORDER BY o.created_at ASC"
)->fetchAll();

// Recently decided offers, so the coordinator can see what they just did and
// reopen anything decided by mistake.
$recent = $db->query(
    "SELECT o.id, o.title, o.status, o.updated_at,
            COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
     FROM internship_offers o
     JOIN users u ON u.id = o.posted_by_user_id
     LEFT JOIN companies c ON c.user_id = u.id
     WHERE o.status IN ('open','archived')
     ORDER BY o.updated_at DESC
     LIMIT 8"
)->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Offers review</h1>
    <p>Offers waiting for approval before they appear publicly.
       Open an offer to read it in full, then approve or reject it there.</p>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if (empty($offers)): ?>
    <div class="card empty-state"><p>Nothing pending review.</p></div>
<?php else: ?>
    <p style="font-size:13px;color:var(--slate);margin-bottom:14px;">
        <strong><?= count($offers) ?></strong> offer<?= count($offers) === 1 ? '' : 's' ?> awaiting your decision.
    </p>

    <?php foreach ($offers as $offer): ?>
        <div class="card">
            <div class="protocol">OFF-<?= str_pad($offer['id'], 4, '0', STR_PAD_LEFT) ?></div>
            <h3><?= htmlspecialchars($offer['title']) ?></h3>
            <p><?= htmlspecialchars(mb_strimwidth($offer['description'], 0, 200, '...')) ?></p>

            <div class="offer-meta">
                <span><?= htmlspecialchars($offer['poster_name']) ?></span>
                <span><?= htmlspecialchars(ucfirst($offer['type'])) ?></span>
                <span><?= (int)$offer['capacity'] ?> place<?= (int)$offer['capacity'] === 1 ? '' : 's' ?></span>
                <span><?= (int)$offer['skill_count'] ?> skill<?= (int)$offer['skill_count'] === 1 ? '' : 's' ?></span>
                <?php if ($offer['application_deadline']): ?>
                    <span>Closes <?= htmlspecialchars(date('d M Y', strtotime($offer['application_deadline']))) ?></span>
                <?php else: ?>
                    <span class="badge badge-pending">No deadline set</span>
                <?php endif; ?>
            </div>

            <div style="margin-top:12px;">
                <a class="btn btn-primary" style="width:auto;display:inline-block;"
                   href="<?= BASE_URL ?>/admin/offer-detail.php?id=<?= $offer['id'] ?>">View details</a>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($recent)): ?>
    <div class="card">
        <h3>Recently decided</h3>
        <table style="margin-top:12px;">
            <thead><tr><th>Offer</th><th>Poster</th><th>Outcome</th><th>When</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['title']) ?></td>
                    <td><?= htmlspecialchars($r['poster_name']) ?></td>
                    <td>
                        <span class="badge badge-<?= $r['status'] === 'open' ? 'open' : 'closed' ?>">
                            <?= $r['status'] === 'open' ? 'Approved' : 'Rejected' ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:var(--slate-light);">
                        <?= htmlspecialchars(date('d M Y, H:i', strtotime($r['updated_at']))) ?>
                    </td>
                    <td><a href="<?= BASE_URL ?>/admin/offer-detail.php?id=<?= $r['id'] ?>">Open →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>