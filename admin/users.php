<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'users';

// Pending student registrations (surfaced at the top of the users hub).
$pending = $db->query("
    SELECT u.id AS user_id, u.first_name, u.last_name, u.email,
           s.matricola, s.program
    FROM students s
    JOIN users u ON u.id = s.user_id
    WHERE s.registration_status = 'pending'
    ORDER BY s.created_at ASC
")->fetchAll();

// All users.
$roleFilter = $_GET['role'] ?? '';
$sql = "SELECT * FROM users";
$params = [];
if (in_array($roleFilter, ['student','professor','company','coordinator'], true)) {
    $sql .= " WHERE role = ?";
    $params[] = $roleFilter;
}
$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

require '../includes/header.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h1>Users</h1>
    <a href="<?= BASE_URL ?>/admin/add-user.php" class="btn btn-primary" style="width:auto;">+ Add new user</a>
</div>

<?php if (!empty($pending)): ?>
    <div class="card" style="border-left:4px solid #d97706;">
        <h3 style="margin-top:0;">Pending registrations <span class="offer-meta">(<?= count($pending) ?>)</span></h3>
        <table>
            <thead><tr><th>Name</th><th>Matricola</th><th>Program</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= (int)$p['user_id'] ?>"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></a></td>
                    <td><?= htmlspecialchars($p['matricola']) ?></td>
                    <td><?= htmlspecialchars($p['program']) ?></td>
                    <td><a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= (int)$p['user_id'] ?>" class="btn btn-secondary" style="padding:4px 12px;font-size:12px;">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form method="GET" class="filter-bar">
    <select name="role" onchange="this.form.submit()">
        <option value="">All roles</option>
        <?php foreach (['student','professor','company','coordinator'] as $r): ?>
            <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></a></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars(ucfirst($u['role'])) ?></td>
                <td><span class="badge badge-<?= $u['is_active'] ? 'open' : 'closed' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require '../includes/footer.php'; ?>
