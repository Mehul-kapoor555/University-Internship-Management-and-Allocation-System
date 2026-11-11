<?php
require 'includes/db.php';   // also pulls in includes/functions.php
require 'includes/auth.php';
requireLogin();

$user = currentUser();
$activePage = 'dashboard';

/**
 * Every tile on this page is a live count and a link to the page that acts on
 * it. The dashboard previously rendered a literal em dash in every slot and
 * linked nowhere, which is why it felt dead: there was no query behind it and
 * nothing to click.
 *
 * Small helper so each tile is one readable line below.
 */
function countOf(PDO $db, string $sql, array $params = []): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$cycleId = activeCycleId($db);

// Current cycle, shown to every role so the stage of the semester is always
// visible — most actions are only legal in particular cycle states.
$cycle = null;
if ($cycleId) {
    $stmt = $db->prepare("SELECT id, name, current_state FROM academic_cycles WHERE id = ?");
    $stmt->execute([$cycleId]);
    $cycle = $stmt->fetch();
}

$tiles = [];
$statError = '';

// A dashboard is the first page every user lands on, so one failing query here
// takes down their entry point entirely — which is how this page came to look
// "unresponsive". Several counts read columns added by later migrations
// (allocations.decision and academic_cycles.current_state arrive in 007), so a
// database that is behind on migrations would otherwise produce a blank page
// with no explanation. Degrade to a visible, diagnostic message instead.
try {

// Counts below are for the cycle running now. Applications and preferences
// carry a cycle, and without the filter these tiles added up every cycle the
// user has ever taken part in.
$cycleId = activeCycleId($db);

if ($user['role'] === 'student') {
    $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $studentId = (int)$stmt->fetchColumn();

    $openOffers = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE status = 'open'");
    $applied    = countOf($db, "SELECT COUNT(*) FROM applications WHERE student_id = ? AND cycle_id = ? AND status <> 'withdrawn'", [$studentId, $cycleId]);
    $ranked     = countOf($db, "SELECT COUNT(*) FROM student_preferences WHERE student_id = ? AND cycle_id = ?", [$studentId, $cycleId]);

    // The placement, if the student has one they accepted.
    $stmt = $db->prepare("
        SELECT o.title
        FROM allocations a
        JOIN internship_offers o ON o.id = a.offer_id
        WHERE a.student_id = ? AND a.decision = 'accepted'
        ORDER BY a.id DESC LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $placement = $stmt->fetchColumn();

    $tiles = [
        ['value' => $openOffers, 'label' => 'Open offers to browse',  'href' => BASE_URL . '/internships.php'],
        ['value' => $applied,    'label' => 'Applications submitted', 'href' => BASE_URL . '/applications.php'],
        ['value' => $ranked,     'label' => 'Preferences ranked',     'href' => BASE_URL . '/student/preferences.php'],
        ['value' => $placement ? '1' : '0', 'label' => $placement ? 'Placement confirmed' : 'No placement yet',
         'href' => BASE_URL . '/student/my-placement.php'],
    ];

} elseif ($user['role'] === 'professor') {
    $stmt = $db->prepare("SELECT id FROM professors WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $profId = (int)$stmt->fetchColumn();

    $posted = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE posted_by_user_id = ?", [$user['id']]);
    $live   = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE posted_by_user_id = ? AND status = 'open'", [$user['id']]);

    $applicants = countOf($db, "
        SELECT COUNT(*) FROM applications ap
        JOIN internship_offers o ON o.id = ap.offer_id
        WHERE o.posted_by_user_id = ? AND ap.cycle_id = ? AND ap.status <> 'withdrawn'
    ", [$user['id'], $cycleId]);

    $supervised = countOf($db, "
        SELECT COUNT(*) FROM supervisor_assignments sa
        JOIN allocations a ON a.id = sa.allocation_id
        WHERE sa.professor_id = ? AND a.decision = 'accepted'
    ", [$profId]);

    // Placements sitting in a state that is waiting on this professor to act.
    $awaiting = countOf($db, "
        SELECT COUNT(*) FROM workflow_states w
        JOIN supervisor_assignments sa ON sa.allocation_id = w.allocation_id
        WHERE sa.professor_id = ?
          AND w.current_state IN ('awaiting_prof_approval', 'awaiting_final_approval')
    ", [$profId]);

    $tiles = [
        ['value' => $posted,     'label' => 'Offers posted (' . $live . ' live)', 'href' => BASE_URL . '/professor/my-offers.php'],
        ['value' => $applicants, 'label' => 'Applicants received',  'href' => BASE_URL . '/professor/applicants.php'],
        ['value' => $supervised, 'label' => 'Students supervised',  'href' => BASE_URL . '/professor/supervised.php'],
        ['value' => $awaiting,   'label' => 'Waiting on your approval', 'href' => BASE_URL . '/professor/supervised.php',
         'urgent' => $awaiting > 0],
    ];

} elseif ($user['role'] === 'company') {
    $live    = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE posted_by_user_id = ? AND status = 'open'", [$user['id']]);
    $draft   = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE posted_by_user_id = ? AND status = 'draft'", [$user['id']]);
    $total   = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE posted_by_user_id = ?", [$user['id']]);

    $applicants = countOf($db, "
        SELECT COUNT(*) FROM applications ap
        JOIN internship_offers o ON o.id = ap.offer_id
        WHERE o.posted_by_user_id = ? AND ap.cycle_id = ? AND ap.status <> 'withdrawn'
    ", [$user['id'], $cycleId]);

    $tiles = [
        ['value' => $live,       'label' => 'Offers live',          'href' => BASE_URL . '/company/my-offers.php'],
        ['value' => $draft,      'label' => 'Awaiting coordinator review', 'href' => BASE_URL . '/company/my-offers.php'],
        ['value' => $total,      'label' => 'Offers posted in total', 'href' => BASE_URL . '/company/my-offers.php'],
        ['value' => $applicants, 'label' => 'Applicants received',  'href' => BASE_URL . '/company/applicants.php'],
    ];

} elseif ($user['role'] === 'coordinator') {
    $users       = countOf($db, "SELECT COUNT(*) FROM users WHERE is_active = 1");
    $pendingReg  = countOf($db, "SELECT COUNT(*) FROM students WHERE registration_status = 'pending'");
    $pendingOff  = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE status = 'draft'");
    $openOffers  = countOf($db, "SELECT COUNT(*) FROM internship_offers WHERE status = 'open'");

    $tiles = [
        ['value' => $pendingOff, 'label' => 'Offers pending review', 'href' => BASE_URL . '/admin/offer-reviews.php',
         'urgent' => $pendingOff > 0],
        ['value' => $pendingReg, 'label' => 'Registrations pending', 'href' => BASE_URL . '/admin/pending-registration.php',
         'urgent' => $pendingReg > 0],
        ['value' => $openOffers, 'label' => 'Offers live',           'href' => BASE_URL . '/internships.php'],
        ['value' => $users,      'label' => 'Active users',          'href' => BASE_URL . '/admin/users.php'],
    ];
}

} catch (Throwable $e) {
    $tiles = [];
    $statError = $e->getMessage();
}

// Recent notifications, for every role.
$stmt = $db->prepare("
    SELECT title, message, type, link, is_read, created_at
    FROM notifications WHERE user_id = ?
    ORDER BY created_at DESC LIMIT 6
");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

$unread = countOf($db, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]);

require 'includes/header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <p>Welcome back, <?= htmlspecialchars($user['name']) ?>.<?php
        if ($cycle): ?> Current cycle: <strong><?= htmlspecialchars($cycle['name']) ?></strong>
        — <?= htmlspecialchars(str_replace('_', ' ', strtolower($cycle['current_state'] ?? ''))) ?>.<?php
        else: ?> No active academic cycle.<?php endif; ?></p>
</div>

<?php if ($statError): ?>
    <div class="alert alert-error">
        <strong>Could not load the summary counts.</strong>
        This usually means the database is behind on migrations — check that
        <code>007_cycle_fsm.sql</code> has been applied.
        <div style="font-size:12px;margin-top:6px;"><?= htmlspecialchars($statError) ?></div>
    </div>
<?php endif; ?>

<div class="card-grid">
    <?php foreach ($tiles as $t): ?>
        <a class="card stat-card stat-link<?= !empty($t['urgent']) ? ' stat-urgent' : '' ?>"
           href="<?= $t['href'] ?>">
            <div class="stat-value"><?= htmlspecialchars((string)$t['value']) ?></div>
            <div class="stat-label"><?= htmlspecialchars($t['label']) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>Recent notifications<?= $unread ? ' (' . $unread . ' unread)' : '' ?></h3>
    <?php if (empty($notifications)): ?>
        <p style="font-size:13px;color:var(--slate-light);margin-top:10px;">Nothing yet.</p>
    <?php else: ?>
        <table style="margin-top:12px;">
            <tbody>
            <?php foreach ($notifications as $n): ?>
                <tr>
                    <td style="width:1%;white-space:nowrap;">
                        <span class="badge badge-<?= $n['type'] === 'action' ? 'pending' : 'open' ?>">
                            <?= htmlspecialchars(ucfirst($n['type'])) ?>
                        </span>
                    </td>
                    <td>
                        <strong<?= $n['is_read'] ? '' : ' style="color:var(--ink-navy);"' ?>>
                            <?= htmlspecialchars($n['title']) ?>
                        </strong>
                        <div style="font-size:12px;color:var(--slate);"><?= htmlspecialchars($n['message']) ?></div>
                    </td>
                    <td style="width:1%;white-space:nowrap;font-size:12px;color:var(--slate-light);">
                        <?= htmlspecialchars(date('d M', strtotime($n['created_at']))) ?>
                    </td>
                    <td style="width:1%;white-space:nowrap;">
                        <?php if ($n['link']): ?>
                            <a href="<?= htmlspecialchars($n['link']) ?>">Open →</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
/* The tiles are anchors now, so strip the link styling and restore the card look. */
.stat-link {
    display: block;
    text-decoration: none;
    color: inherit;
    transition: border-color .15s ease, transform .15s ease;
}
.stat-link:hover {
    border-color: var(--strait-blue);
    transform: translateY(-2px);
}
/* A tile that represents work queued for this user, so it reads as a to-do
   rather than as a statistic. */
.stat-urgent .stat-value { color: var(--mosaic-gold); }
</style>

<?php require 'includes/footer.php'; ?>
