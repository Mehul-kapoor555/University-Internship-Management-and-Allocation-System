<?php
$user = currentUser();

$unreadCount = 0;
if (isset($user['id']) && $user['id']) {
    global $db;
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unreadCount = (int)$stmt->fetchColumn();
}

$navByRole = [
    'student' => [
        ['Dashboard', BASE_URL . '/dashboard.php', 'dashboard'],
        ['Browse internships', BASE_URL . '/internships.php', 'internships'],
        ['My preferences', BASE_URL . '/student/preferences.php', 'preferences'],
        ['My applications', BASE_URL . '/applications.php', 'applications'],
        ['My placement', BASE_URL . '/student/my-placement.php', 'my-placement'],
        ['My profile', BASE_URL . '/profile.php', 'profile'],
    ],
    'professor' => [
        ['Dashboard', BASE_URL . '/dashboard.php', 'dashboard'],
        ['My offers', BASE_URL . '/professor/my-offers.php', 'my-offers'],
        ['Post an offer', BASE_URL . '/professor/post-offer.php', 'post-offer'],
        ['Applicants', BASE_URL . '/professor/applicants.php', 'applicants'],
        ['Rank applicants', BASE_URL . '/professor/rank-applicants.php', 'rank-applicants'],
        ['My students', BASE_URL . '/professor/students.php', 'students'],
        ['Supervised placements', BASE_URL . '/professor/supervised.php', 'supervised'],
        ['My profile', BASE_URL . '/profile.php', 'profile'],
    ],
    'company' => [
        ['Dashboard', BASE_URL . '/dashboard.php', 'dashboard'],
        ['My offers', BASE_URL . '/company/my-offers.php', 'my-offers'],
        ['Post an offer', BASE_URL . '/company/post-offer.php', 'post-offer'],
        ['Applicants', BASE_URL . '/company/applicants.php', 'applicants'],
        ['Rank applicants', BASE_URL . '/company/rank-applicants.php', 'rank-applicants'],
        ['My profile', BASE_URL . '/profile.php', 'profile'],
    ],
    'coordinator' => [
        ['Dashboard', BASE_URL . '/dashboard.php', 'dashboard'],
        ['Cycle control', BASE_URL . '/admin/cycle-control.php', 'cycle-control'],
        ['Users', BASE_URL . '/admin/users.php', 'users'],
        ['Offers review', BASE_URL . '/admin/offer-reviews.php', 'offer-reviews'],
        ['Academic cycles', BASE_URL . '/admin/cycles.php', 'cycles'],
        ['Run matching', BASE_URL . '/admin/run-matching.php', 'run-matching'],
        ['Supervisor assignment', BASE_URL . '/admin/supervisor-assignments.php', 'supervisor-assignments'],
        ['Internship lifecycle', BASE_URL . '/admin/lifecycle-dashboard.php', 'lifecycle'],
        ['Email log', BASE_URL . '/admin/email-log.php', 'email-log'],
    ],
];

$navItems = $navByRole[$user['role']] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UNIME · Internship System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <div class="letterhead">
        <div class="crest">Università di Messina <span>SISTEMA TIROCINI</span></div>
        <div class="who">
            <a href="<?= BASE_URL ?>/notifications.php" class="bell">
                🔔 <?php if ($unreadCount > 0): ?><span class="bell-count"><?= $unreadCount ?></span><?php endif; ?>
            </a>
            <span><strong><?= htmlspecialchars($user['name']) ?></strong> · <?= htmlspecialchars(ucfirst($user['role'])) ?></span>
            <a href="<?= BASE_URL ?>/logout.php">Log out</a>
        </div>
    </div>

    <aside class="sidebar">
        <nav>
            <div class="nav-label">Menu</div>
            <?php foreach ($navItems as [$label, $href, $pageId]): ?>
                <a class="nav-item <?= (isset($activePage) && $activePage === $pageId) ? 'active' : '' ?>" href="<?= $href ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main">