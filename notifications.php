<?php
require 'includes/db.php';
require 'includes/auth.php';
requireLogin();

$user = currentUser();
$activePage = 'notifications';

// Mark all as read on page load
$db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user['id']]);

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

require 'includes/header.php';
?>

<div class="page-header">
    <h1>Notifications</h1>
</div>

<?php if (empty($notifications)): ?>
    <div class="card empty-state"><p>No notifications yet.</p></div>
<?php else: ?>
    <?php foreach ($notifications as $n): ?>
        <div class="card">
            <h3 style="font-size:15px;"><?= htmlspecialchars($n['title']) ?></h3>
            <p><?= htmlspecialchars($n['message']) ?></p>
            <div class="offer-meta">
                <span><?= htmlspecialchars(date('d M Y H:i', strtotime($n['created_at']))) ?></span>
                <span class="badge badge-<?= $n['type'] === 'action' ? 'pending' : ($n['type'] === 'success' ? 'open' : 'draft') ?>"><?= htmlspecialchars($n['type']) ?></span>
                <?php if ($n['link']): ?><span><a href="<?= htmlspecialchars($n['link']) ?>">Go →</a></span><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>