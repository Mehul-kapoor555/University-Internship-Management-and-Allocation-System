<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int)$_POST['user_id'];
    $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$targetId]);
}

$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Users</h1>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars(ucfirst($u['role'])) ?></td>
                <td><span class="badge badge-<?= $u['is_active'] ? 'open' : 'closed' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require '../includes/footer.php'; ?>