<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/engine_runner.php';
requireRole(['company']);

$user = currentUser();
$activePage = 'applicants';
$message = '';
$cycleId = activeCycleId($db);

// Load company's offers
$stmt = $db->prepare("
    SELECT o.id, o.title, o.status,
           (SELECT COUNT(*) FROM applications a WHERE a.offer_id = o.id AND a.status != 'withdrawn') AS app_count
    FROM internship_offers o
    WHERE o.posted_by_user_id = ?
    ORDER BY o.status = 'open' DESC, o.created_at DESC
");
$stmt->execute([$user['id']]);
$offers = $stmt->fetchAll();

$selectedOfferId = (int)($_GET['offer_id'] ?? 0);

// Confirm ownership
$selectedOffer = null;
if ($selectedOfferId) {
    $stmt = $db->prepare("SELECT * FROM internship_offers WHERE id = ? AND posted_by_user_id = ?");
    $stmt->execute([$selectedOfferId, $user['id']]);
    $selectedOffer = $stmt->fetch();
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ranked_ids']) && $selectedOffer) {
    $rankedIds = json_decode($_POST['ranked_ids'], true);
    if (is_array($rankedIds)) {
        $db->prepare("DELETE FROM offer_rankings WHERE offer_id = ?")->execute([$selectedOfferId]);
        $ins = $db->prepare(
            "INSERT INTO offer_rankings (offer_id, student_id, `rank`, cycle_id, source)
             VALUES (?, ?, ?, ?, 'manual')"
        );
        foreach ($rankedIds as $rank => $studentId) {
            $ins->execute([$selectedOfferId, (int)$studentId, $rank + 1, $cycleId]);
        }
        $message = 'Ranking saved.';
    }
}

// Handle "load from applicant ranking"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_algorithm']) && $selectedOffer) {
    // Ensure applicant_rankings exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM applicant_rankings WHERE offer_id = ?");
    $stmt->execute([$selectedOfferId]);
    if ((int)$stmt->fetchColumn() === 0) {
        EngineRunner::run('applicant_ranking', ['offer-id' => $selectedOfferId]);
    }

    $stmt = $db->prepare("
        SELECT student_id, rank_position
        FROM applicant_rankings
        WHERE offer_id = ?
        ORDER BY rank_position ASC
    ");
    $stmt->execute([$selectedOfferId]);
    $ordered = $stmt->fetchAll();

    if (!empty($ordered)) {
        $db->prepare("DELETE FROM offer_rankings WHERE offer_id = ?")->execute([$selectedOfferId]);
        $ins = $db->prepare(
            "INSERT INTO offer_rankings (offer_id, student_id, `rank`, cycle_id, source)
             VALUES (?, ?, ?, ?, 'recommendation')"
        );
        foreach ($ordered as $rank => $row) {
            $ins->execute([$selectedOfferId, (int)$row['student_id'], $rank + 1, $cycleId]);
        }
        $message = 'Ranking loaded from the algorithmic applicant scores. Reorder as needed and save.';
    }
}

// Load current ranking + missing applicants (applicants not yet in the ranking list)
$ranking = [];
$missingApplicants = [];
if ($selectedOffer) {
    $stmt = $db->prepare("
        SELECT o_rank.student_id, o_rank.`rank`, o_rank.source,
               s.matricola, s.program, u.first_name, u.last_name
        FROM offer_rankings o_rank
        JOIN students s ON s.id = o_rank.student_id
        JOIN users u ON u.id = s.user_id
        WHERE o_rank.offer_id = ?
        ORDER BY o_rank.`rank` ASC
    ");
    $stmt->execute([$selectedOfferId]);
    $ranking = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT DISTINCT s.id AS student_id, s.matricola, s.program,
               u.first_name, u.last_name
        FROM applications a
        JOIN students s ON s.id = a.student_id
        JOIN users u ON u.id = s.user_id
        WHERE a.offer_id = ? AND a.status != 'withdrawn'
          AND s.id NOT IN (SELECT student_id FROM offer_rankings WHERE offer_id = ?)
    ");
    $stmt->execute([$selectedOfferId, $selectedOfferId]);
    $missingApplicants = $stmt->fetchAll();
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Rank applicants for matching</h1>
    <p>This ranking is used by the Gale-Shapley matching algorithm. <strong>1 is your top choice.</strong></p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<?php if (empty($offers)): ?>
    <div class="card empty-state"><p>You haven't posted any offers yet.</p></div>
<?php else: ?>

    <div class="card">
        <form method="GET">
            <label for="offer_id">Choose offer</label>
            <select id="offer_id" name="offer_id" onchange="this.form.submit()">
                <option value="">— Pick one of your offers —</option>
                <?php foreach ($offers as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $selectedOfferId === (int)$o['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($o['title']) ?> · <?= (int)$o['app_count'] ?> applicant<?= $o['app_count'] == 1 ? '' : 's' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($selectedOffer): ?>

        <?php if (empty($ranking) && empty($missingApplicants)): ?>
            <div class="card empty-state"><p>No applicants for this offer yet.</p></div>
        <?php else: ?>

            <div class="card" style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <h3 style="margin:0;"><?= htmlspecialchars($selectedOffer['title']) ?></h3>
                    <p style="margin:4px 0 0;font-size:13px;color:var(--slate);">
                        Drag rows to reorder, or load a suggested ranking from the algorithmic scores.
                    </p>
                </div>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="load_algorithm" value="1" class="btn btn-secondary" style="width:auto;">
                        ↓ Load from algorithm
                    </button>
                </form>
            </div>

            <form method="POST" id="rank-form">
                <input type="hidden" name="ranked_ids" id="ranked_ids">
                <div class="card">
                    <ul id="rank-list" class="pref-list">
                        <?php foreach ($ranking as $r): ?>
                            <li class="pref-item" data-id="<?= $r['student_id'] ?>">
                                <span class="pref-handle">☰</span>
                                <span class="pref-rank"></span>
                                <div style="flex:1;">
                                    <div style="font-weight:500;"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    <div class="offer-meta" style="margin-top:2px;">
                                        <span><?= htmlspecialchars($r['matricola']) ?></span>
                                        <span><?= htmlspecialchars($r['program']) ?></span>
                                        <?php if ($r['source'] === 'recommendation'): ?>
                                            <span class="badge badge-draft" style="font-size:10px;">from algorithm</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($missingApplicants as $r): ?>
                            <li class="pref-item pref-item-new" data-id="<?= $r['student_id'] ?>">
                                <span class="pref-handle">☰</span>
                                <span class="pref-rank"></span>
                                <div style="flex:1;">
                                    <div style="font-weight:500;"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    <div class="offer-meta" style="margin-top:2px;">
                                        <span><?= htmlspecialchars($r['matricola']) ?></span>
                                        <span><?= htmlspecialchars($r['program']) ?></span>
                                        <span class="badge badge-pending" style="font-size:10px;">unranked</span>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Save ranking</button>
            </form>

        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
// Same drag-reorder logic as student preferences
(function() {
    var list = document.getElementById('rank-list');
    if (!list) return;
    function refreshRankNumbers() {
        list.querySelectorAll('.pref-item').forEach(function(el, idx) {
            el.querySelector('.pref-rank').textContent = (idx + 1);
        });
    }
    refreshRankNumbers();
    var dragging = null;
    list.addEventListener('dragstart', function(e) {
        if (!e.target.classList.contains('pref-item')) return;
        dragging = e.target;
        e.target.style.opacity = '0.4';
    });
    list.addEventListener('dragend', function(e) {
        if (!dragging) return;
        dragging.style.opacity = '1';
        dragging = null;
        refreshRankNumbers();
    });
    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        var afterEl = getDragAfterElement(list, e.clientY);
        if (!dragging) return;
        if (afterEl == null) list.appendChild(dragging);
        else list.insertBefore(dragging, afterEl);
    });
    function getDragAfterElement(container, y) {
        var els = [...container.querySelectorAll('.pref-item:not([style*="opacity: 0.4"])')];
        return els.reduce(function(closest, child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) return { offset: offset, element: child };
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    list.querySelectorAll('.pref-item').forEach(function(el) { el.setAttribute('draggable', 'true'); });
    var form = document.getElementById('rank-form');
    if (form) {
        form.addEventListener('submit', function() {
            var ids = [];
            list.querySelectorAll('.pref-item').forEach(function(el) { ids.push(el.dataset.id); });
            document.getElementById('ranked_ids').value = JSON.stringify(ids);
        });
    }
})();
</script>

<?php require '../includes/footer.php'; ?>