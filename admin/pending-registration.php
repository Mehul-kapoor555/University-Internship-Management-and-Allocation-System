<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$user = currentUser();
$activePage = 'pending-registrations';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int)$_POST['student_id'];
    $action = $_POST['action'];

    $stmt = $db->prepare(
        "SELECT s.*, u.id AS user_id, u.first_name, u.last_name, u.email
         FROM students s JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND s.registration_status = 'pending'"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if ($student) {
        if ($action === 'approve') {
            $db->beginTransaction();
            $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$student['user_id']]);
            $db->prepare(
                "UPDATE students SET registration_status = 'approved', approved_by_user_id = ?, approved_at = NOW()
                 WHERE id = ?"
            )->execute([$user['id'], $studentId]);
            notify(
                $db, $student['user_id'],
                'Your registration has been approved',
                'Welcome to Sistema Tirocini. You may now log in and complete your profile.',
                'success',
                BASE_URL . '/profile.php'
            );
            $db->commit();
            $message = 'Approved ' . $student['first_name'] . ' ' . $student['last_name'] . '.';

        } elseif ($action === 'reject') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            $db->prepare(
                "UPDATE students SET registration_status = 'rejected', rejection_reason = ?,
                 approved_by_user_id = ?, approved_at = NOW() WHERE id = ?"
            )->execute([$reason, $user['id'], $studentId]);
            // user's is_active stays 0 — they cannot log in
            $message = 'Rejected ' . $student['first_name'] . ' ' . $student['last_name'] . '.';
        }
    }
}

$stmt = $db->query(
    "SELECT s.id AS student_id, s.matricola, s.program, s.enrollment_year, s.created_at,
            u.first_name, u.last_name, u.email
     FROM students s JOIN users u ON u.id = s.user_id
     WHERE s.registration_status = 'pending'
     ORDER BY s.created_at ASC"
);
$pending = $stmt->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Pending registrations</h1>
    <p>Student registration requests awaiting approval.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<?php if (empty($pending)): ?>
    <div class="card empty-state"><p>Nothing pending — all registrations reviewed.</p></div>
<?php else: ?>
    <?php foreach ($pending as $p): ?>
        <div class="card">
            <div class="protocol">REG-<?= str_pad($p['student_id'], 4, '0', STR_PAD_LEFT) ?></div>
            <h3><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></h3>
            <div class="offer-meta">
                <span><?= htmlspecialchars($p['matricola']) ?></span>
                <span><?= htmlspecialchars($p['email']) ?></span>
                <span><?= htmlspecialchars($p['program']) ?></span>
                <span>Enrolled <?= (int)$p['enrollment_year'] ?></span>
                <span>Requested <?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></span>
            </div>

            <form method="POST" style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="student_id" value="<?= $p['student_id'] ?>">
                <button type="submit" name="action" value="approve" class="btn btn-primary" style="width:auto;">Approve</button>
                <input type="text" name="rejection_reason" placeholder="Rejection reason (optional)" style="flex:1;min-width:200px;width:auto;">
                <button type="submit" name="action" value="reject" class="btn btn-secondary" style="width:auto;">Reject</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>