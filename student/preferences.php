<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['student']);

$user = currentUser();
$activePage = 'preferences';

$stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
$stmt->execute([$user['id']]);
$student = $stmt->fetch();
$studentId = $student['id'] ?? null;
$cycleId = activeCycleId($db);

$message = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ranked_ids']) && $studentId) {
    $rankedIds = json_decode($_POST['ranked_ids'], true);
    if (is_array($rankedIds)) {
        $db->prepare("DELETE FROM student_preferences WHERE student_id = ?")->execute([$studentId]);
        $ins = $db->prepare(
            "INSERT INTO student_preferences (student_id, offer_id, `rank`, cycle_id, source)
             VALUES (?, ?, ?, ?, 'manual')"
        );
        foreach ($rankedIds as $rank => $offerId) {
            $ins->execute([$studentId, (int)$offerId, $rank + 1, $cycleId]);
        }
        $message = 'Preferences saved.';
    }
}

// Handle "load from recommendations"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_recommendations']) && $studentId) {
    // Get eligible applied offers, ordered by recommendation rank
    $stmt = $db->prepare("
        SELECT DISTINCT a.offer_id, rs.rank_position
        FROM applications a
        JOIN recommendation_scores rs ON rs.offer_id = a.offer_id AND rs.student_id = a.student_id
        WHERE a.student_id = ? AND a.status != 'withdrawn'
        ORDER BY rs.rank_position ASC
    ");
    $stmt->execute([$studentId]);
    $ordered = $stmt->fetchAll();

    if (!empty($ordered)) {
        $db->prepare("DELETE FROM student_preferences WHERE student_id = ?")->execute([$studentId]);
        $ins = $db->prepare(
            "INSERT INTO student_preferences (student_id, offer_id, `rank`, cycle_id, source)
             VALUES (?, ?, ?, ?, 'recommendation')"
        );
        foreach ($ordered as $rank => $row) {
            $ins->execute([$studentId, (int)$row['offer_id'], $rank + 1, $cycleId]);
        }
        $message = 'Preferences loaded from your recommendation ranking. Reorder as needed and save.';
    } else {
        $message = 'No recommendations available yet for offers you applied to.';
    }
}

// Load current preferences (ordered)
$preferences = [];
if ($studentId) {
    $stmt = $db->prepare("
        SELECT sp.offer_id, sp.`rank`, sp.source,
               o.title, o.type, o.is_paid, o.stipend_monthly,
               COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
        FROM student_preferences sp
        JOIN internship_offers o ON o.id = sp.offer_id
        JOIN users u ON u.id = o.posted_by_user_id
        LEFT JOIN companies c ON c.user_id = u.id
        WHERE sp.student_id = ?
        ORDER BY sp.`rank` ASC
    ");
    $stmt->execute([$studentId]);
    $preferences = $stmt->fetchAll();
}

// Also load applied offers not yet in preferences (add them at the bottom)
$missingAppliedOffers = [];
if ($studentId) {
    $stmt = $db->prepare("
        SELECT DISTINCT a.offer_id, o.title, o.type,
               COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
        FROM applications a
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN users u ON u.id = o.posted_by_user_id
        LEFT JOIN companies c ON c.user_id = u.id
        WHERE a.student_id = ? AND a.status != 'withdrawn'
          AND a.offer_id NOT IN (
              SELECT offer_id FROM student_preferences WHERE student_id = ?
          )
    ");
    $stmt->execute([$studentId, $studentId]);
    $missingAppliedOffers = $stmt->fetchAll();
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>My preferences</h1>
    <p>Rank the offers you've applied to. This is the ranked list used by the matching algorithm — <strong>1 is your top choice</strong>.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="card" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h3 style="margin:0;">Preference ranking</h3>
        <p style="margin:4px 0 0;font-size:13px;color:var(--slate);">
            Drag rows to reorder, or load a suggested ranking from your recommendations.
        </p>
    </div>
    <form method="POST" style="margin:0;">
        <button type="submit" name="load_recommendations" value="1" class="btn btn-secondary" style="width:auto;">
            ↓ Load from recommendations
        </button>
    </form>
</div>

<?php if (empty($preferences) && empty($missingAppliedOffers)): ?>
    <div class="card empty-state">
        <p>You haven't applied to any offers yet — <a href="<?= BASE_URL ?>/internships.php">browse open internships</a>.</p>
    </div>
<?php else: ?>

    <form method="POST" id="pref-form">
        <input type="hidden" name="ranked_ids" id="ranked_ids">
        <div class="card">
            <ul id="pref-list" class="pref-list">
                <?php foreach ($preferences as $p): ?>
                    <li class="pref-item" data-id="<?= $p['offer_id'] ?>">
                        <span class="pref-handle">☰</span>
                        <span class="pref-rank"></span>
                        <div style="flex:1;">
                            <div style="font-weight:500;"><?= htmlspecialchars($p['title']) ?></div>
                            <div class="offer-meta" style="margin-top:2px;">
                                <span><?= htmlspecialchars($p['poster_name']) ?></span>
                                <span><?= htmlspecialchars(ucfirst($p['type'])) ?></span>
                                <?php if ($p['is_paid']): ?><span class="badge badge-open" style="font-size:10px;">Paid</span><?php endif; ?>
                                <?php if ($p['source'] === 'recommendation'): ?>
                                    <span class="badge badge-draft" style="font-size:10px;">from recommendations</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($missingAppliedOffers as $p): ?>
                    <li class="pref-item pref-item-new" data-id="<?= $p['offer_id'] ?>">
                        <span class="pref-handle">☰</span>
                        <span class="pref-rank"></span>
                        <div style="flex:1;">
                            <div style="font-weight:500;"><?= htmlspecialchars($p['title']) ?></div>
                            <div class="offer-meta" style="margin-top:2px;">
                                <span><?= htmlspecialchars($p['poster_name']) ?></span>
                                <span><?= htmlspecialchars(ucfirst($p['type'])) ?></span>
                                <span class="badge badge-pending" style="font-size:10px;">unranked — applied</span>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Save preferences</button>
    </form>

<?php endif; ?>

<script>
(function() {
    var list = document.getElementById('pref-list');
    if (!list) return;

    function refreshRankNumbers() {
        var items = list.querySelectorAll('.pref-item');
        items.forEach(function(el, idx) {
            el.querySelector('.pref-rank').textContent = (idx + 1);
        });
    }
    refreshRankNumbers();

    // Drag & drop reorder
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
        if (afterEl == null) {
            list.appendChild(dragging);
        } else {
            list.insertBefore(dragging, afterEl);
        }
    });
    function getDragAfterElement(container, y) {
        var els = [...container.querySelectorAll('.pref-item:not([style*="opacity: 0.4"])')];
        return els.reduce(function(closest, child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    // Make items draggable
    list.querySelectorAll('.pref-item').forEach(function(el) {
        el.setAttribute('draggable', 'true');
    });

    // On submit, serialize order
    var form = document.getElementById('pref-form');
    if (form) {
        form.addEventListener('submit', function() {
            var ids = [];
            list.querySelectorAll('.pref-item').forEach(function(el) {
                ids.push(el.dataset.id);
            });
            document.getElementById('ranked_ids').value = JSON.stringify(ids);
        });
    }
})();
</script>

<?php require '../includes/footer.php'; ?>