<?php
require 'includes/db.php';
require 'includes/auth.php';
requireLogin();

$user = currentUser();
$activePage = 'applications';
$rows = [];
$decisionMessage = '';
$decisionError = '';

/* -------------------------------------------------------------------------
 * Resolve the current student's row (used by both the decision panel and
 * the applications list).
 * ---------------------------------------------------------------------- */
$student = null;
if ($user['role'] === 'student') {
    $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $student = $stmt->fetch();
}

/* -------------------------------------------------------------------------
 * DECISION HANDLER — a matched student accepts or rejects their allocation.
 *
 * Contract with the cycle state machine (see cycle_state_machine.php):
 *   - The cycle must be in RESULTS_PUBLISHED for decisions to be accepted.
 *   - accept  -> allocations.decision = 'accepted',  status = 'confirmed'
 *                and the offer seat is consumed (internship_offers.filled + 1;
 *                offer flips to 'closed' when it becomes full).
 *   - reject  -> allocations.decision = 'rejected',  status = 'rejected'
 *                the seat is NOT consumed, so the offer stays available for a
 *                later round (check_unclaimed_offers_exist reads capacity-filled).
 *   - A rejecting student is excluded from the next round by
 *     check_unmatched_pool_nonempty (decision IN ('accepted','rejected')).
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $user['role'] === 'student'
    && $student
    && isset($_POST['decision'], $_POST['allocation_id'])) {

    $choice       = $_POST['decision'] === 'accept' ? 'accepted' : 'rejected';
    $allocationId = (int)$_POST['allocation_id'];

    // Load the allocation, make sure it belongs to THIS student, is still
    // pending, and its cycle is actually in the decision window.
    $stmt = $db->prepare("
        SELECT a.id, a.offer_id, a.decision, a.round_id,
               o.title, o.capacity, o.filled, o.status AS offer_status,
               o.posted_by_user_id,
               c.current_state
        FROM allocations a
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN matching_rounds r   ON r.id = a.round_id
        JOIN academic_cycles c   ON c.id = r.cycle_id
        WHERE a.id = ? AND a.student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$allocationId, (int)$student['id']]);
    $alloc = $stmt->fetch();

    if (!$alloc) {
        $decisionError = 'That allocation was not found, or it is not yours.';
    } elseif ($alloc['current_state'] !== 'RESULTS_PUBLISHED') {
        $decisionError = 'The decision window is not open right now.';
    } elseif ($alloc['decision'] !== 'pending') {
        $decisionError = 'You have already responded to this match.';
    } else {
        try {
            $db->beginTransaction();

            // decision_source = 'student' marks this as the student's own choice,
            // so it is never confused with one the demo harness generated when
            // the coordinator closed the decision window.
            if ($choice === 'accepted') {
                $db->prepare("
                    UPDATE allocations
                    SET decision = 'accepted', status = 'confirmed', decision_at = NOW(),
                        decision_source = 'student'
                    WHERE id = ?
                ")->execute([$allocationId]);

                $decisionMessage = 'You have accepted your placement for "' . $alloc['title'] . '".';
            } else {
                $db->prepare("
                    UPDATE allocations
                    SET decision = 'rejected', status = 'rejected', decision_at = NOW(),
                        decision_source = 'student'
                    WHERE id = ?
                ")->execute([$allocationId]);

                $decisionMessage = 'You have rejected the match for "' . $alloc['title'] . '". '
                                 . 'You will not be considered for this offer again in this cycle.';
            }

            // Recount the seats on this offer from the decisions that now exist,
            // rather than adjusting a stored counter. Both branches run it: an
            // acceptance takes a seat, and a rejection has to give one back so a
            // later round can reuse it. The old code incremented on accept and
            // did nothing on reject, which leaked a seat on every rejection.
            recomputeOfferFilled($db, (int)$alloc['offer_id']);

            // Let the coordinator see the response land (in-app notification).
            $coordStmt = $db->query("SELECT id FROM users WHERE role = 'coordinator' LIMIT 1");
            $coord = $coordStmt->fetch();
            if ($coord) {
                $verb = $choice === 'accepted' ? 'accepted' : 'rejected';
                notify($db, (int)$coord['id'],
                    'Student ' . $verb . ' a match',
                    'A student has ' . $verb . ' the placement "' . $alloc['title'] . '".',
                    'info', '/admin/cycle-control.php');
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $decisionError = 'Could not record your decision. Please try again.';
        }
    }
}

/* -------------------------------------------------------------------------
 * DECISION PANEL DATA — the student's live allocation(s) awaiting a response,
 * plus any already-decided ones, but only while a cycle is in / past the
 * decision window so we don't surface tentative pre-publication matches.
 * ---------------------------------------------------------------------- */
$pendingMatches  = [];
$decidedMatches  = [];
if ($user['role'] === 'student' && $student) {
    $stmt = $db->prepare("
        SELECT a.id, a.decision, a.status, a.decision_at,
               o.title, o.type,
               COALESCE(cu.first_name, '') AS poster_first,
               COALESCE(cu.last_name,  '') AS poster_last,
               c.current_state, c.name AS cycle_name
        FROM allocations a
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN matching_rounds r   ON r.id = a.round_id
        JOIN academic_cycles c   ON c.id = r.cycle_id
        JOIN users cu            ON cu.id = o.posted_by_user_id
        WHERE a.student_id = ?
          AND c.current_state IN ('RESULTS_PUBLISHED','POST_DECISION_INTEGRITY','FINAL_PUBLICATION')
        ORDER BY a.id DESC
    ");
    $stmt->execute([(int)$student['id']]);
    foreach ($stmt->fetchAll() as $m) {
        if ($m['decision'] === 'pending' && $m['current_state'] === 'RESULTS_PUBLISHED') {
            $pendingMatches[] = $m;
        } else {
            $decidedMatches[] = $m;
        }
    }
}

/* -------------------------------------------------------------------------
 * APPLICATIONS LIST (existing behaviour, unchanged).
 * ---------------------------------------------------------------------- */
if ($user['role'] === 'student') {
    $stmt = $db->prepare(
        "SELECT a.*, o.title, o.type
         FROM applications a
         JOIN internship_offers o ON o.id = a.offer_id
         JOIN students s ON s.id = a.student_id
         WHERE s.user_id = ?
         ORDER BY a.applied_at DESC"
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();

} elseif ($user['role'] === 'company' || $user['role'] === 'professor') {
    $stmt = $db->prepare(
        "SELECT a.*, o.title, CONCAT(su.first_name, ' ', su.last_name) AS student_name
         FROM applications a
         JOIN internship_offers o ON o.id = a.offer_id
         JOIN students s ON s.id = a.student_id
         JOIN users su ON su.id = s.user_id
         WHERE o.posted_by_user_id = ?
         ORDER BY a.applied_at DESC"
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();

} elseif ($user['role'] === 'coordinator') {
    $stmt = $db->query(
        "SELECT a.*, o.title, CONCAT(su.first_name, ' ', su.last_name) AS student_name
         FROM applications a
         JOIN internship_offers o ON o.id = a.offer_id
         JOIN students s ON s.id = a.student_id
         JOIN users su ON su.id = s.user_id
         ORDER BY a.applied_at DESC"
    );
    $rows = $stmt->fetchAll();
}

require 'includes/header.php';
?>

<div class="page-header">
    <h1><?= $user['role'] === 'student' ? 'My applications' : 'Applications' ?></h1>
</div>

<?php if ($decisionMessage): ?>
    <div class="card" style="border-left:4px solid #16a34a;">
        <p style="margin:0;"><?= htmlspecialchars($decisionMessage) ?></p>
    </div>
<?php endif; ?>
<?php if ($decisionError): ?>
    <div class="card" style="border-left:4px solid #dc2626;">
        <p style="margin:0;"><?= htmlspecialchars($decisionError) ?></p>
    </div>
<?php endif; ?>

<?php if ($user['role'] === 'student' && !empty($pendingMatches)): ?>
    <div class="card" style="border-left:4px solid #d97706;">
        <h2 style="margin-top:0;">You have been matched — please respond</h2>
        <p style="color:var(--slate-light);margin-top:-4px;">
            Accepting confirms your placement. Rejecting frees the seat and removes you
            from any further matching round in this cycle.
        </p>
        <?php foreach ($pendingMatches as $m): ?>
            <?php $poster = trim($m['poster_first'] . ' ' . $m['poster_last']); ?>
            <div class="offer-card" style="padding:16px;margin-top:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div>
                        <strong style="font-size:16px;"><?= htmlspecialchars($m['title']) ?></strong>
                        <div class="offer-meta" style="margin-top:4px;">
                            <?= $m['type'] === 'research' ? 'Research offer' : 'Company offer' ?>
                            <?= $poster !== '' ? ' · ' . htmlspecialchars($poster) : '' ?>
                            · <?= htmlspecialchars($m['cycle_name']) ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="allocation_id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="decision" value="accept">
                            <button type="submit" class="btn btn-primary">Accept placement</button>
                        </form>
                        <form method="post" style="margin:0;"
                              onsubmit="return confirm('Reject this match? You will not be matched to this offer again in this cycle.');">
                            <input type="hidden" name="allocation_id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="decision" value="reject">
                            <button type="submit" class="btn btn-secondary">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($user['role'] === 'student' && !empty($decidedMatches)): ?>
    <div class="card">
        <h2 style="margin-top:0;">Your match</h2>
        <table>
            <thead>
                <tr><th>Offer</th><th>Outcome</th><th>Responded</th></tr>
            </thead>
            <tbody>
            <?php foreach ($decidedMatches as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['title']) ?></td>
                    <td>
                        <?php
                        $label = [
                            'accepted' => 'Accepted',
                            'rejected' => 'Rejected',
                            'timed_out'=> 'No response (timed out)',
                            'pending'  => 'Awaiting close',
                        ][$m['decision']] ?? ucfirst($m['decision']);
                        $cls = $m['decision'] === 'accepted' ? 'accepted'
                             : ($m['decision'] === 'pending' ? 'pending' : 'rejected');
                        ?>
                        <span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($label) ?></span>
                    </td>
                    <td><?= $m['decision_at'] ? htmlspecialchars(date('d M Y, H:i', strtotime($m['decision_at']))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="card empty-state"><p>No applications yet.</p></div>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>APP</th>
                    <th><?= $user['role'] === 'student' ? 'Offer' : 'Student · Offer' ?></th>
                    <th>Applied</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="offer-meta">APP-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td><?= $user['role'] === 'student' ? htmlspecialchars($r['title']) : htmlspecialchars($r['student_name']) . ' · ' . htmlspecialchars($r['title']) ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($r['applied_at']))) ?></td>
                    <td><span class="badge badge-<?= $r['status'] ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>