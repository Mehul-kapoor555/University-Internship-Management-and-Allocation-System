<?php
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/engine_runner.php';
requireLogin();

$user = currentUser();
$activePage = 'internships';

// Handle manual refresh (student only)
$refreshMessage = '';
$refreshError = '';
$studentId = null;

if ($user['role'] === 'student') {
    $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $studentRow = $stmt->fetch();
    $studentId = $studentRow['id'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh']) && $studentId) {
        $result = EngineRunner::run('recommendation', ['student-id' => $studentId]);
        if ($result['success']) {
            $refreshMessage = "Ranking refreshed ({$result['data']['recommendations_written']} eligible offers, {$result['duration_ms']} ms).";
        } else {
            $refreshError = 'Refresh failed: ' . $result['error'];
        }
    }

    // If no scores exist yet, run the engine automatically (first visit).
    $stmt = $db->prepare("SELECT COUNT(*) FROM recommendation_scores WHERE student_id = ?");
    $stmt->execute([$studentId]);
    if ($studentId && (int)$stmt->fetchColumn() === 0) {
        $result = EngineRunner::run('recommendation', ['student-id' => $studentId]);
        if (!$result['success'] && !$refreshError) {
            $refreshError = 'Auto-ranking failed on first load: ' . $result['error'];
        }
    }
}

// Filters (kept even in ranked view — a student can still narrow by type/paid)
$typeFilter = $_GET['type'] ?? '';
$domainFilter = $_GET['domain'] ?? '';
$paidOnly = isset($_GET['paid_only']);

// --------------------------------------------------------
// STUDENT: eligible ranked + ineligible ranked (two sections)
// --------------------------------------------------------
$eligibleOffers = [];
$ineligibleOffers = [];
$offerSkillsById = [];
$gapsByOffer = [];

if ($user['role'] === 'student' && $studentId) {

    // Eligible: driven by recommendation_scores (ranked)
    $sql = "SELECT rs.score, rs.rank_position, rs.explanation,
                   ss.cosine_similarity, ss.coverage,
                   o.*, COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
            FROM recommendation_scores rs
            JOIN internship_offers o ON o.id = rs.offer_id
            JOIN users u ON u.id = o.posted_by_user_id
            LEFT JOIN companies c ON c.user_id = u.id
            LEFT JOIN similarity_scores ss ON ss.student_id = rs.student_id AND ss.offer_id = rs.offer_id
            WHERE rs.student_id = ? AND o.status = 'open'";
    $params = [$studentId];
    if ($typeFilter !== '') { $sql .= " AND o.type = ?"; $params[] = $typeFilter; }
    if ($domainFilter !== '') { $sql .= " AND o.domain LIKE ?"; $params[] = '%' . $domainFilter . '%'; }
    if ($paidOnly) { $sql .= " AND o.is_paid = 1"; }
    $sql .= " ORDER BY rs.rank_position ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $eligibleOffers = $stmt->fetchAll();

    // Ineligible: all open offers NOT in recommendation_scores for this student.
    // Rank ineligible ones just by coverage (informational, so students see what
    // they *could* qualify for once they meet requirements).
    $sql = "SELECT o.*, e.reason AS ineligibility_reason,
                   COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
            FROM internship_offers o
            LEFT JOIN eligibility e ON e.offer_id = o.id AND e.student_id = ?
            JOIN users u ON u.id = o.posted_by_user_id
            LEFT JOIN companies c ON c.user_id = u.id
            WHERE o.status = 'open'
              AND o.id NOT IN (SELECT offer_id FROM recommendation_scores WHERE student_id = ?)";
    $params = [$studentId, $studentId];
    if ($typeFilter !== '') { $sql .= " AND o.type = ?"; $params[] = $typeFilter; }
    if ($domainFilter !== '') { $sql .= " AND o.domain LIKE ?"; $params[] = '%' . $domainFilter . '%'; }
    if ($paidOnly) { $sql .= " AND o.is_paid = 1"; }
    $sql .= " ORDER BY o.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $ineligibleOffers = $stmt->fetchAll();

    // Preload skills for all listed offers
    $allOfferIds = array_merge(array_column($eligibleOffers, 'id'), array_column($ineligibleOffers, 'id'));
    if (!empty($allOfferIds)) {
        $in = implode(',', array_fill(0, count($allOfferIds), '?'));
        $stmt = $db->prepare(
            "SELECT os.offer_id, os.importance, os.minimum_proficiency, s.name
             FROM offer_skills os JOIN skills s ON s.id = os.skill_id
             WHERE os.offer_id IN ($in)
             ORDER BY os.importance ASC, s.name ASC"
        );
        $stmt->execute($allOfferIds);
        foreach ($stmt->fetchAll() as $row) {
            $offerSkillsById[$row['offer_id']][] = $row;
        }

        // Also load competency gaps for the eligible offers (drives the chip strip)
        $eligibleIds = array_column($eligibleOffers, 'id');
        if (!empty($eligibleIds)) {
            $in2 = implode(',', array_fill(0, count($eligibleIds), '?'));
            $stmt = $db->prepare("
                SELECT cg.offer_id, cg.gap_type, s.name AS skill_name
                FROM competency_gaps cg JOIN skills s ON s.id = cg.skill_id
                WHERE cg.student_id = ? AND cg.offer_id IN ($in2)
            ");
            $stmt->execute(array_merge([$studentId], $eligibleIds));
            foreach ($stmt->fetchAll() as $row) {
                $gapsByOffer[$row['offer_id']][] = $row;
            }
        }
    }
}

// --------------------------------------------------------
// NON-STUDENT: flat listing of open offers (no ranking)
// --------------------------------------------------------
$genericOffers = [];
if ($user['role'] !== 'student') {
    $sql = "SELECT o.*, COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
            FROM internship_offers o
            JOIN users u ON u.id = o.posted_by_user_id
            LEFT JOIN companies c ON c.user_id = u.id
            WHERE o.status = 'open'";
    $params = [];
    if ($typeFilter !== '') { $sql .= " AND o.type = ?"; $params[] = $typeFilter; }
    if ($domainFilter !== '') { $sql .= " AND o.domain LIKE ?"; $params[] = '%' . $domainFilter . '%'; }
    if ($paidOnly) { $sql .= " AND o.is_paid = 1"; }
    $sql .= " ORDER BY o.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $genericOffers = $stmt->fetchAll();

    if (!empty($genericOffers)) {
        $ids = array_column($genericOffers, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT os.offer_id, os.importance, os.minimum_proficiency, s.name
             FROM offer_skills os JOIN skills s ON s.id = os.skill_id
             WHERE os.offer_id IN ($in)
             ORDER BY os.importance ASC, s.name ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $offerSkillsById[$row['offer_id']][] = $row;
        }
    }
}

require 'includes/header.php';
?>

<div class="page-header">
    <h1>Browse internships</h1>
    <?php if ($user['role'] === 'student'): ?>
        <p>Offers ranked by fit with your profile. Ineligible offers are shown below for reference.</p>
    <?php else: ?>
        <p>Open offers from companies and research supervisors.</p>
    <?php endif; ?>
</div>

<?php if ($refreshMessage): ?><div class="alert alert-success"><?= htmlspecialchars($refreshMessage) ?></div><?php endif; ?>
<?php if ($refreshError): ?><div class="alert alert-error"><?= htmlspecialchars($refreshError) ?></div><?php endif; ?>

<form method="GET" class="filter-bar">
    <select name="type" onchange="this.form.submit()">
        <option value="">All types</option>
        <option value="company" <?= $typeFilter === 'company' ? 'selected' : '' ?>>Company</option>
        <option value="research" <?= $typeFilter === 'research' ? 'selected' : '' ?>>Research</option>
    </select>
    <input type="text" name="domain" placeholder="Filter by domain..." value="<?= htmlspecialchars($domainFilter) ?>">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:normal;margin:0;">
        <input type="checkbox" name="paid_only" value="1" <?= $paidOnly ? 'checked' : '' ?> onchange="this.form.submit()" style="width:auto;">
        Paid only
    </label>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($user['role'] === 'student'): ?>
        <form method="POST" style="margin:0;display:inline;">
            <button type="submit" name="refresh" value="1" class="btn btn-secondary" style="margin:0;">↻ Refresh ranking</button>
        </form>
    <?php endif; ?>
</form>

<?php
// ============================================================
// RENDER HELPERS (inline for a single-file page)
// ============================================================
function render_ranked_offer($offer, $skills, $gaps, $isEligible = true) {
    $poster = $offer['poster_name'];
    $offerId = (int)$offer['id'];
    ?>
    <div class="card offer-card <?= $isEligible ? 'offer-card-eligible' : 'offer-card-ineligible' ?>">
        <div class="protocol">OFF-<?= str_pad($offerId, 4, '0', STR_PAD_LEFT) ?></div>

        <?php if ($isEligible && isset($offer['score'])): ?>
            <?php $scorePct = round((float)$offer['score'] * 100); ?>
            <div class="rec-score-ring rec-score-<?= $scorePct >= 70 ? 'high' : ($scorePct >= 45 ? 'mid' : 'low') ?>" style="width:64px;height:64px;flex-shrink:0;">
                <span class="rec-score-value" style="font-size:22px;"><?= $scorePct ?></span>
                <span class="rec-score-suffix">%</span>
            </div>
        <?php endif; ?>

        <div style="flex:1;">
            <h3><?= htmlspecialchars($offer['title']) ?></h3>
            <p><?= htmlspecialchars(mb_strimwidth($offer['description'], 0, 160, '...')) ?></p>

            <div class="offer-meta">
                <span><?= htmlspecialchars($poster) ?></span>
                <span><?= htmlspecialchars(ucfirst($offer['type'])) ?></span>
                <?php if ($offer['domain']): ?><span><?= htmlspecialchars($offer['domain']) ?></span><?php endif; ?>
                <span><?= (int)$offer['filled'] ?>/<?= (int)$offer['capacity'] ?> filled</span>
                <?php if ($offer['is_paid']): ?>
                    <span class="badge badge-open" style="font-size:10px;">
                        Paid<?php if ($offer['stipend_monthly']): ?> · €<?= number_format((float)$offer['stipend_monthly'], 0) ?>/mo<?php endif; ?>
                    </span>
                <?php endif; ?>
                <?php if ($offer['is_remote']): ?><span class="badge badge-draft" style="font-size:10px;">Remote</span><?php endif; ?>
            </div>

            <?php if ($isEligible && !empty($offer['explanation'])): ?>
                <p style="margin-top:10px;font-size:13px;"><?= htmlspecialchars($offer['explanation']) ?></p>
            <?php endif; ?>

            <?php if (!$isEligible && !empty($offer['ineligibility_reason'])): ?>
                <p style="margin-top:10px;font-size:13px;color:var(--clay-red);">
                    <strong>Not eligible:</strong> <?= htmlspecialchars($offer['ineligibility_reason']) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($skills)): ?>
                <div class="skill-tag-list">
                    <?php foreach ($skills as $sk): ?>
                        <span class="skill-tag skill-tag-<?= $sk['importance'] ?>">
                            <?= htmlspecialchars($sk['name']) ?>
                            <span class="skill-tag-level"><?= substr($sk['minimum_proficiency'], 0, 3) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($isEligible && !empty($gaps)): ?>
                <div class="rec-gaps" style="margin-top:12px;padding-top:12px;">
                    <div class="rec-gaps-label">Gaps:</div>
                    <?php foreach ($gaps as $g): ?>
                        <span class="skill-tag skill-tag-outcome-<?= $g['gap_type'] === 'missing' ? 'missing' : 'insufficient' ?>">
                            <?= htmlspecialchars($g['skill_name']) ?>
                            <span class="skill-tag-level"><?= $g['gap_type'] ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <a href="<?= BASE_URL ?>/student/offer-detail.php?id=<?= $offerId ?>" class="btn btn-secondary">
            <?= $isEligible ? 'View &amp; apply' : 'View' ?>
        </a>
    </div>
    <?php
}

function render_plain_offer($offer, $skills) {
    ?>
    <div class="card offer-card">
        <div class="protocol">OFF-<?= str_pad($offer['id'], 4, '0', STR_PAD_LEFT) ?></div>
        <div style="flex:1;">
            <h3><?= htmlspecialchars($offer['title']) ?></h3>
            <p><?= htmlspecialchars(mb_strimwidth($offer['description'], 0, 160, '...')) ?></p>
            <div class="offer-meta">
                <span><?= htmlspecialchars($offer['poster_name']) ?></span>
                <span><?= htmlspecialchars(ucfirst($offer['type'])) ?></span>
                <?php if ($offer['domain']): ?><span><?= htmlspecialchars($offer['domain']) ?></span><?php endif; ?>
                <span><?= (int)$offer['filled'] ?>/<?= (int)$offer['capacity'] ?> filled</span>
                <?php if ($offer['is_paid']): ?><span class="badge badge-open" style="font-size:10px;">Paid</span><?php endif; ?>
                <?php if ($offer['is_remote']): ?><span class="badge badge-draft" style="font-size:10px;">Remote</span><?php endif; ?>
            </div>
            <?php if (!empty($skills)): ?>
                <div class="skill-tag-list">
                    <?php foreach ($skills as $sk): ?>
                        <span class="skill-tag skill-tag-<?= $sk['importance'] ?>">
                            <?= htmlspecialchars($sk['name']) ?>
                            <span class="skill-tag-level"><?= substr($sk['minimum_proficiency'], 0, 3) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<?php if ($user['role'] === 'student'): ?>

    <?php if (empty($eligibleOffers) && empty($ineligibleOffers)): ?>
        <div class="card empty-state"><p>No open offers match your filters.</p></div>
    <?php else: ?>

        <?php if (!empty($eligibleOffers)): ?>
            <h2 style="margin-top:32px;font-size:18px;color:var(--strait-blue);">
                Recommended for you
                <span style="font-size:12px;color:var(--slate-light);font-family:var(--font-mono);text-transform:uppercase;margin-left:8px;"><?= count($eligibleOffers) ?> eligible</span>
            </h2>
            <?php foreach ($eligibleOffers as $offer): ?>
                <?= render_ranked_offer($offer, $offerSkillsById[$offer['id']] ?? [], $gapsByOffer[$offer['id']] ?? [], true) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card empty-state"><p>No eligible offers yet — but here's what you could work toward:</p></div>
        <?php endif; ?>

        <?php if (!empty($ineligibleOffers)): ?>
            <h2 style="margin-top:40px;font-size:18px;color:var(--slate-light);">
                Not currently eligible
                <span style="font-size:12px;color:var(--slate-light);font-family:var(--font-mono);text-transform:uppercase;margin-left:8px;"><?= count($ineligibleOffers) ?></span>
            </h2>
            <?php foreach ($ineligibleOffers as $offer): ?>
                <?= render_ranked_offer($offer, $offerSkillsById[$offer['id']] ?? [], [], false) ?>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

<?php else: ?>

    <?php if (empty($genericOffers)): ?>
        <div class="card empty-state"><p>No open offers match your filters.</p></div>
    <?php else: ?>
        <?php foreach ($genericOffers as $offer): ?>
            <?= render_plain_offer($offer, $offerSkillsById[$offer['id']] ?? []) ?>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>