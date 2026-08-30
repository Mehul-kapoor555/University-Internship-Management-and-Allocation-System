<?php
require 'includes/db.php';
require 'includes/auth.php';
requireLogin();

$user = currentUser();
$activePage = 'dashboard';
require 'includes/header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <p>Welcome back, <?= htmlspecialchars($user['name']) ?>.</p>
</div>

<?php if ($user['role'] === 'student'): ?>
    <div class="card-grid">
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Open offers matching you</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Applications submitted</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Preferences ranked</div></div>
    </div>

<?php elseif ($user['role'] === 'professor'): ?>
    <div class="card-grid">
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Offers posted</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Students supervised</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Pending approvals</div></div>
    </div>

<?php elseif ($user['role'] === 'company'): ?>
    <div class="card-grid">
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Active offers</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Total applicants</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Offers awaiting review</div></div>
    </div>

<?php elseif ($user['role'] === 'coordinator'): ?>
    <div class="card-grid">
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Total users</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Offers pending review</div></div>
        <div class="card stat-card"><div class="stat-value">—</div><div class="stat-label">Active academic cycle</div></div>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>