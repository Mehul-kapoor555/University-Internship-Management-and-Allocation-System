<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'offers-review';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $offerId = (int)$_POST['offer_id'];
    $action = $_POST['action'];
    $newStatus = $action === 'approve' ? 'open' : 'archived';
    $stmt = $db->prepare("UPDATE internship_offers SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $offerId]);
}

$stmt = $db->query(
    "SELECT o.*, COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
     FROM internship_offers o
     JOIN users u ON u.id = o.posted_by_user_id
     LEFT JOIN companies c ON c.user_id = u.id
     WHERE o.status = 'draft'
     ORDER BY o.created_at ASC"
);
$offers = $stmt->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Offers review</h1>
    <p>Offers waiting for approval before they appear publicly.</p>
</div>

<?php if (empty($offers)): ?>
    <div class="card empty-state"><p>Nothing pending review.</p></div>
<?php else: ?>
    <?php foreach ($offers as $offer): ?>
        <div class="card">
            <div class="protocol">OFF-<?= str_pad($offer['id'], 4, '0', STR_PAD_LEFT) ?></div>
            <h3><?= htmlspecialchars($offer['title']) ?></h3>
            <p><?= htmlspecialchars(mb_strimwidth($offer['description'], 0, 200, '...')) ?></p>
            <div class="offer-meta">
                <span><?= htmlspecialchars($offer['poster_name']) ?></span>
                <span><?= htmlspecialchars(ucfirst($offer['type'])) ?></span>
            </div>
            <form method="POST" style="margin-top:12px;">
                <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                <button type="submit" name="action" value="approve" class="btn btn-primary" style="width:auto;display:inline-block;">Approve</button>
                <button type="submit" name="action" value="reject" class="btn btn-secondary" style="width:auto;display:inline-block;">Reject</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>