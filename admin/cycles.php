<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'cycles';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    if ($name === '' || !$start || !$end) {
        $error = 'All fields are required.';
    } else {
        $stmt = $db->prepare("INSERT INTO academic_cycles (name, start_date, end_date) VALUES (?, ?, ?)");
        $stmt->execute([$name, $start, $end]);
    }
}

$cycles = $db->query("SELECT * FROM academic_cycles ORDER BY start_date DESC")->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Academic cycles</h1>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" class="card form-narrow">
    <label for="name">Cycle name</label>
    <input type="text" id="name" name="name" placeholder="e.g. 2026-2027">
    <label for="start_date">Start date</label>
    <input type="date" id="start_date" name="start_date">
    <label for="end_date">End date</label>
    <input type="date" id="end_date" name="end_date">
    <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Create cycle</button>
</form>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($cycles as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['start_date']) ?></td>
                <td><?= htmlspecialchars($c['end_date']) ?></td>
                <td><span class="badge badge-<?= $c['is_active'] ? 'open' : 'closed' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require '../includes/footer.php'; ?>