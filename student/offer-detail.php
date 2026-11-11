<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['student']);

$user = currentUser();
$activePage = 'internships';

$offerId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT o.*, COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
     FROM internship_offers o
     JOIN users u ON u.id = o.posted_by_user_id
     LEFT JOIN companies c ON c.user_id = u.id
     WHERE o.id = ?"
);
$stmt->execute([$offerId]);
$offer = $stmt->fetch();

if (!$offer) {
    die("Offer not found.");
}

$stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
$stmt->execute([$user['id']]);
$student = $stmt->fetch();
$studentId = $student['id'] ?? null;

// Is this offer eligible for this student? An offer is eligible when the
// recommendation engine wrote a score row for it (same signal the browse page
// uses to split the eligible / not-eligible lists). If it isn't there, we also
// pull the stored reason so we can tell the student why they can't apply.
$isEligible = false;
$ineligibilityReason = null;
if ($studentId) {
    $stmt = $db->prepare(
        "SELECT 1 FROM recommendation_scores WHERE student_id = ? AND offer_id = ? LIMIT 1"
    );
    $stmt->execute([$studentId, $offerId]);
    $isEligible = (bool)$stmt->fetchColumn();

    if (!$isEligible) {
        $stmt = $db->prepare(
            "SELECT reason FROM eligibility WHERE student_id = ? AND offer_id = ? LIMIT 1"
        );
        $stmt->execute([$studentId, $offerId]);
        $ineligibilityReason = $stmt->fetchColumn() ?: null;
    }
}

$alreadyApplied = false;
$existing = null;
if ($studentId) {
    $stmt = $db->prepare("SELECT id, status FROM applications WHERE student_id = ? AND offer_id = ?");
    $stmt->execute([$studentId, $offerId]);
    $existing = $stmt->fetch();
    $alreadyApplied = (bool)$existing;
}

// Applying is only possible while the coordinator has applications open. The
// matching runs on the applications as they stood when that window closed, so
// one arriving afterwards would not be accounted for anywhere.
$canApply = studentsCanApply($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $studentId && !$alreadyApplied && $isEligible && $canApply) {
    $coverLetter = trim($_POST['cover_letter'] ?? '');
    $stmt = $db->prepare(
        "INSERT INTO applications (student_id, offer_id, cover_letter) VALUES (?, ?, ?)"
    );
    $stmt->execute([$studentId, $offerId, $coverLetter]);

    // Notify the offer poster
    notify(
        $db, $offer['posted_by_user_id'],
        'New application received',
        'A student applied to your offer "' . $offer['title'] . '".',
        'info',
        BASE_URL . '/' . ($offer['type'] === 'company' ? 'company/applicants.php' : 'applications.php')
    );

    header('Location: ' . BASE_URL . '/student/offer-detail.php?id=' . $offerId . '&applied=1');
    exit;
}

// Load offer's required skills
$stmt = $db->prepare(
    "SELECT os.skill_id, os.importance, os.minimum_proficiency, s.name
     FROM offer_skills os
     JOIN skills s ON s.id = os.skill_id
     WHERE os.offer_id = ?
     ORDER BY os.importance ASC, s.name ASC"
);
$stmt->execute([$offerId]);
$offerSkills = $stmt->fetchAll();

// Load student's own skills, indexed by skill_id
$studentSkillMap = [];
if ($studentId) {
    $stmt = $db->prepare("SELECT skill_id, proficiency FROM student_skills WHERE student_id = ?");
    $stmt->execute([$studentId]);
    foreach ($stmt->fetchAll() as $row) {
        $studentSkillMap[(int)$row['skill_id']] = $row['proficiency'];
    }
}

// Compute per-skill match outcome
$profRank = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
$matched = 0; $insufficient = 0; $missing = 0; $requiredTotal = 0;
$skillsWithMatch = [];
foreach ($offerSkills as $sk) {
    $skId = (int)$sk['skill_id'];
    $needed = $profRank[$sk['minimum_proficiency']];
    $studentHas = $studentSkillMap[$skId] ?? null;
    $studentLevel = $studentHas ? $profRank[$studentHas] : 0;

    if ($studentLevel === 0) {
        $outcome = 'missing';
        if ($sk['importance'] === 'required') $missing++;
    } elseif ($studentLevel >= $needed) {
        $outcome = 'matched';
        if ($sk['importance'] === 'required') $matched++;
    } else {
        $outcome = 'insufficient';
        if ($sk['importance'] === 'required') $insufficient++;
    }

    if ($sk['importance'] === 'required') $requiredTotal++;

    $skillsWithMatch[] = array_merge($sk, [
        'outcome' => $outcome,
        'student_level' => $studentHas,
    ]);
}
$coveragePct = $requiredTotal > 0 ? round(($matched / $requiredTotal) * 100) : 100;

require '../includes/header.php';
?>

<div class="page-header">
    <h1><?= htmlspecialchars($offer['title']) ?></h1>
    <p><?= htmlspecialchars($offer['poster_name']) ?> · <?= htmlspecialchars(ucfirst($offer['type'])) ?></p>
</div>

<?php if (isset($_GET['applied'])): ?><div class="alert alert-success">Application submitted.</div><?php endif; ?>
<?php if (!$studentId): ?><div class="alert alert-error">Your student profile is incomplete — contact the coordinator's office.</div><?php endif; ?>

<div class="detail-grid">
    <div>
        <div class="card">
            <h3>Description</h3>
            <p><?= nl2br(htmlspecialchars($offer['description'])) ?></p>
        </div>

        <?php if (!empty($skillsWithMatch)): ?>
            <div class="card">
                <h3>Required skills</h3>
                <?php if ($requiredTotal > 0 && $studentId): ?>
                    <div style="margin: 8px 0 16px;">
                        <div class="offer-meta"><span>Skill match: <?= $coveragePct ?>% (<?= $matched ?>/<?= $requiredTotal ?> required)</span></div>
                        <div class="match-bar">
                            <div class="match-bar-fill" style="width: <?= $coveragePct ?>%;"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="skill-tag-list">
                    <?php foreach ($skillsWithMatch as $sk): ?>
                        <span class="skill-tag skill-tag-<?= $sk['importance'] ?> skill-tag-outcome-<?= $sk['outcome'] ?>">
                            <?= htmlspecialchars($sk['name']) ?>
                            <span class="skill-tag-level">
                                min <?= substr($sk['minimum_proficiency'], 0, 3) ?><?= $sk['importance'] === 'preferred' ? ' · pref' : '' ?>
                            </span>
                            <?php if ($sk['outcome'] === 'matched'): ?>
                                <span class="skill-tag-icon" title="You have this skill at required level or above">✓</span>
                            <?php elseif ($sk['outcome'] === 'insufficient'): ?>
                                <span class="skill-tag-icon" title="You have this skill but below required level">△</span>
                            <?php elseif ($sk['outcome'] === 'missing'): ?>
                                <span class="skill-tag-icon" title="You do not have this skill yet">✕</span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <p style="font-size:12px;color:var(--slate-light);margin-top:14px;">
                    ✓ meets or exceeds required level · △ has skill but below minimum · ✕ skill missing from your profile
                </p>
            </div>
        <?php endif; ?>

        <?php if ($studentId && $isEligible): ?>
            <div class="card">
                <h3>Apply</h3>
                <?php if ($alreadyApplied): ?>
                    <p>You've already applied to this offer. Status: <span class="badge badge-<?= $existing['status'] ?>"><?= htmlspecialchars($existing['status']) ?></span></p>
                <?php elseif (!$canApply): ?>
                    <p style="margin:0;">Applications are closed for this cycle, so this offer is view-only now.</p>
                <?php else: ?>
                    <form method="POST">
                        <label for="cover_letter">Cover letter (optional)</label>
                        <textarea id="cover_letter" name="cover_letter" placeholder="Why are you a good fit for this internship?"></textarea>
                        <button type="submit" class="btn btn-primary">Submit application</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($studentId && !$isEligible): ?>
            <div class="card" style="border-left:4px solid var(--clay-red,#dc2626);">
                <h3>Not eligible</h3>
                <p style="margin:0;">
                    You do not currently meet the requirements for this offer, so you cannot apply to it.
                    <?php if ($ineligibilityReason): ?>
                        <br><strong>Reason:</strong> <?= htmlspecialchars($ineligibilityReason) ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <div class="side">
        <div class="card">
            <div class="field-row"><span>Status</span><span class="badge badge-<?= $offer['status'] ?>"><?= htmlspecialchars($offer['status']) ?></span></div>
            <div class="field-row"><span>Domain</span><span><?= htmlspecialchars($offer['domain'] ?? '—') ?></span></div>
            <div class="field-row"><span>Duration</span><span><?= (int)$offer['duration_months'] ?> months</span></div>
            <div class="field-row"><span>Location</span><span><?= htmlspecialchars($offer['location'] ?? ($offer['is_remote'] ? 'Remote' : '—')) ?></span></div>
            <div class="field-row"><span>Paid</span><span><?= $offer['is_paid'] ? ('Yes' . ($offer['stipend_monthly'] ? ' · €' . number_format((float)$offer['stipend_monthly'], 0) . '/mo' : '')) : 'No' ?></span></div>
            <div class="field-row"><span>Min. CFU</span><span><?= $offer['min_cfu'] ?? '—' ?></span></div>
            <div class="field-row"><span>Program</span><span><?= htmlspecialchars($offer['required_program'] ?? 'Any') ?></span></div>
            <div class="field-row"><span>Capacity</span><span><?= (int)$offer['filled'] ?>/<?= (int)$offer['capacity'] ?></span></div>
            <?php if ($offer['contact_email']): ?>
                <div class="field-row"><span>Contact</span><span style="font-family:var(--font-mono);font-size:11px;"><?= htmlspecialchars($offer['contact_email']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require '../includes/footer.php'; ?>