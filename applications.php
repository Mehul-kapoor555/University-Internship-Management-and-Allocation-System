<?php
require 'includes/db.php';
require 'includes/auth.php';
requireLogin();

$user = currentUser();
$activePage = 'applications';
$rows = [];

if ($user['role'] === 'student') {
    $stmt = $db->prepare(
        "SELECT a.*, o.title, o.type
         FROM applications a
         JOIN internship_offers o ON o.id = a.offer_id
         JOIN students s ON s.id = a.student_id
         WHERE s.user_id = ?
         ORDER BY a.applied_at DESC"
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();

} elseif ($user['role'] === 'company' || $user['role'] === 'professor') {
    $stmt = $db->prepare(
        "SELECT a.*, o.title, CONCAT(su.first_name, ' ', su.last_name) AS student_name
         FROM applications a
         JOIN internship_offers o ON o.id = a.offer_id
         JOIN students s ON s.id = a.student_id
         JOIN users su ON su.id = s.user_id
         WHERE o.posted_by_user_id = ?
         ORDER BY a.applied_at DESC"
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();

} elseif ($user['role'] === 'coordinator') {
    $stmt = $db->query(
        "SELECT a.*, o.title, CONCAT(su.first_name, ' ', su.last_name) AS student_name
         FROM applications a
         JOIN internship_offers o ON o.id = a.offer_id
         JOIN students s ON s.id = a.student_id
         JOIN users su ON su.id = s.user_id
         ORDER BY a.applied_at DESC"
    );
    $rows = $stmt->fetchAll();
}

require 'includes/header.php';
?>

<div class="page-header">
    <h1><?= $user['role'] === 'student' ? 'My applications' : 'Applications' ?></h1>
</div>

<?php if (empty($rows)): ?>
    <div class="card empty-state"><p>No applications yet.</p></div>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>APP</th>
                    <th><?= $user['role'] === 'student' ? 'Offer' : 'Student · Offer' ?></th>
                    <th>Applied</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="offer-meta">APP-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td><?= $user['role'] === 'student' ? htmlspecialchars($r['title']) : htmlspecialchars($r['student_name']) . ' · ' . htmlspecialchars($r['title']) ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($r['applied_at']))) ?></td>
                    <td><span class="badge badge-<?= $r['status'] ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>