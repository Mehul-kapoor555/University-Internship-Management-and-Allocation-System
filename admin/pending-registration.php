<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'users';

$pending = $db->query("
    SELECT u.id AS user_id, u.first_name, u.last_name, s.matricola, s.program, s.created_at
    FROM students s
    JOIN users u ON u.id = s.user_id
    WHERE s.registration_status = 'pending'
    ORDER BY s.created_at ASC
")->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Pending registrations</h1>
    <p>Student registration requests awaiting review. Open a profile to review, edit, and approve.</p>
</div>

<p><a href="<?= BASE_URL ?>/admin/users.php">← Back to users</a></p>

<?php if (empty($pending)): ?>
    <div class="card empty-state"><p>Nothing pending — all registrations reviewed.</p></div>
<?php else: ?>
    <div class="card">
        <table>
            <thead><tr><th>Name</th><th>Matricola</th><th>Program</th><th>Requested</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= (int)$p['user_id'] ?>"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></a></td>
                    <td><?= htmlspecialchars($p['matricola']) ?></td>
                    <td><?= htmlspecialchars($p['program']) ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                    <td><a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= (int)$p['user_id'] ?>" class="btn btn-secondary" style="padding:4px 12px;font-size:12px;">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>
