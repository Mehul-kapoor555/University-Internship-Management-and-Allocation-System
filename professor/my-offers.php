<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['professor']);

$user = currentUser();
$activePage = 'my-offers';

$stmt = $db->prepare("SELECT * FROM internship_offers WHERE posted_by_user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$offers = $stmt->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>My offers</h1>
    <p><a href="<?= BASE_URL ?>/professor/post-offer.php" class="btn btn-secondary" style="margin-top:8px;">+ Post a new offer</a></p>
</div>

<?php if (isset($_GET['posted'])): ?><div class="alert alert-success">Offer submitted for coordinator review.</div><?php endif; ?>

<?php if (empty($offers)): ?>
    <div class="card empty-state"><p>You haven't posted any offers yet.</p></div>
<?php else: ?>
    <?php foreach ($offers as $offer): ?>
        <div class="card offer-card">
            <div class="protocol">OFF-<?= str_pad($offer['id'], 4, '0', STR_PAD_LEFT) ?></div>
            <div>
                <h3><?= htmlspecialchars($offer['title']) ?></h3>
                <div class="offer-meta">
                    <span class="badge badge-<?= $offer['status'] ?>"><?= htmlspecialchars($offer['status']) ?></span>
                    <span><?= (int)$offer['filled'] ?>/<?= (int)$offer['capacity'] ?> filled</span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>