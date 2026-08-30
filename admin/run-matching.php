<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/engine_runner.php';
requireRole(['coordinator']);

$user = currentUser();
$activePage = 'run-matching';
$message = '';
$error = '';

// Handle action: run matching, verify stability, publish
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'run') {
        $cycleId = (int)$_POST['cycle_id'];
        $result = EngineRunner::run('matching', ['cycle-id' => $cycleId]);
        if ($result['success']) {
            $roundId = $result['data']['round_id'];
            // Immediately verify stability
            $verifyResult = EngineRunner::run('stability_verifier', ['round-id' => $roundId]);
            if ($verifyResult['success']) {
                $bp = $verifyResult['data']['blocking_pairs_count'];
                $message = "Matching complete. Round #{$roundId}: " .
                           "{$result['data']['matched_students']}/{$result['data']['total_students']} students matched. " .
                           "Stability check: " . ($bp === 0 ? "✓ stable (no blocking pairs)" : "✗ {$bp} blocking pairs!");
            } else {
                $message = "Matching complete (round #{$roundId}), but verification failed: " . $verifyResult['error'];
            }
        } else {
            $error = 'Matching failed: ' . $result['error'];
        }

    } elseif ($action === 'publish') {
        $roundId = (int)$_POST['round_id'];

        $db->beginTransaction();
        try {
            // Confirm this round is 'stable' before publishing
            $stmt = $db->prepare("SELECT status FROM matching_rounds WHERE id = ?");
            $stmt->execute([$roundId]);
            $row = $stmt->fetch();
            if (!$row || $row['status'] !== 'stable') {
                throw new Exception('Round is not in a stable state and cannot be published.');
            }

            // Confirm allocations
            $db->prepare("UPDATE allocations SET status = 'confirmed' WHERE round_id = ?")
               ->execute([$roundId]);

            // Update offer filled counts
            $stmt = $db->prepare("
                SELECT offer_id, COUNT(*) AS n FROM allocations
                WHERE round_id = ? AND status = 'confirmed'
                GROUP BY offer_id
            ");
            $stmt->execute([$roundId]);
            $updateFilled = $db->prepare("UPDATE internship_offers SET filled = filled + ? WHERE id = ?");
            foreach ($stmt->fetchAll() as $row) {
                $updateFilled->execute([(int)$row['n'], (int)$row['offer_id']]);
            }

            // Mark round as published
            $db->prepare("UPDATE matching_rounds SET status = 'published' WHERE id = ?")
               ->execute([$roundId]);

            // Notify each matched student
            $stmt = $db->prepare("
                SELECT a.student_id, s.user_id, o.title AS offer_title,
                       COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
                FROM allocations a
                JOIN students s ON s.id = a.student_id
                JOIN internship_offers o ON o.id = a.offer_id
                JOIN users u ON u.id = o.posted_by_user_id
                LEFT JOIN companies c ON c.user_id = u.id
                WHERE a.round_id = ?
            ");
            $stmt->execute([$roundId]);
            foreach ($stmt->fetchAll() as $row) {
                notify(
                    $db, $row['user_id'],
                    'You have been matched!',
                    'You have been matched with "' . $row['offer_title'] . '" at ' . $row['poster_name'] . '.',
                    'success',
                    BASE_URL . '/applications.php'
                );
            }

            $db->commit();
            $message = "Round #{$roundId} published. Students have been notified.";

        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Publish failed: ' . $e->getMessage();
        }

    } elseif ($action === 'discard') {
        $roundId = (int)$_POST['round_id'];
        // Delete the round and all its allocations/trace (CASCADE handles it)
        $db->prepare("DELETE FROM matching_rounds WHERE id = ? AND status != 'published'")
           ->execute([$roundId]);
        $message = "Round discarded.";
    }
}

// Load cycles
$cycles = $db->query("SELECT * FROM academic_cycles ORDER BY start_date DESC")->fetchAll();

// Load recent rounds
$rounds = $db->query("
    SELECT mr.*, ac.name AS cycle_name,
           (SELECT COUNT(*) FROM allocations WHERE round_id = mr.id) AS allocation_count
    FROM matching_rounds mr
    JOIN academic_cycles ac ON ac.id = mr.cycle_id
    ORDER BY mr.created_at DESC
    LIMIT 10
")->fetchAll();

// Pre-flight summary for the active cycle (if any)
$activeCycle = null;
foreach ($cycles as $c) {
    if ($c['is_active']) { $activeCycle = $c; break; }
}

$preflight = null;
if ($activeCycle) {
    $stmt = $db->prepare("
        SELECT
          (SELECT COUNT(DISTINCT student_id) FROM student_preferences sp
             JOIN internship_offers o ON o.id = sp.offer_id WHERE o.status = 'open') AS students_with_prefs,
          (SELECT COUNT(DISTINCT offer_id) FROM offer_rankings o_rank
             JOIN internship_offers o ON o.id = o_rank.offer_id WHERE o.status = 'open') AS offers_with_rankings,
          (SELECT COUNT(*) FROM internship_offers WHERE status = 'open') AS open_offers,
          (SELECT COUNT(*) FROM applications a JOIN internship_offers o ON o.id = a.offer_id
             WHERE o.status = 'open' AND a.status != 'withdrawn') AS total_applications
    ");
    $stmt->execute();
    $preflight = $stmt->fetch();
}

// Load the most recent unpublished round's details for review
$reviewRound = null;
$reviewAllocations = [];
foreach ($rounds as $r) {
    if (in_array($r['status'], ['stable', 'unstable'], true)) {
        $reviewRound = $r;
        break;
    }
}
if ($reviewRound) {
    $stmt = $db->prepare("
        SELECT al.*, o.title AS offer_title,
               CONCAT(u.first_name, ' ', u.last_name) AS student_name,
               s.matricola
        FROM allocations al
        JOIN internship_offers o ON o.id = al.offer_id
        JOIN students s ON s.id = al.student_id
        JOIN users u ON u.id = s.user_id
        WHERE al.round_id = ?
        ORDER BY o.title, s.matricola
    ");
    $stmt->execute([$reviewRound['id']]);
    $reviewAllocations = $stmt->fetchAll();
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Run matching</h1>
    <p>Executes the Gale-Shapley many-to-one matching algorithm for the active academic cycle.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$activeCycle): ?>
    <div class="card empty-state">
        <p>No active academic cycle. <a href="<?= BASE_URL ?>/admin/cycles.php">Create or activate one</a> before running matching.</p>
    </div>
<?php else: ?>

    <div class="card">
        <h3>Pre-flight check — cycle "<?= htmlspecialchars($activeCycle['name']) ?>"</h3>
        <div class="field-row"><span>Open offers</span><span><?= (int)$preflight['open_offers'] ?></span></div>
        <div class="field-row"><span>Total applications</span><span><?= (int)$preflight['total_applications'] ?></span></div>
        <div class="field-row"><span>Students with preferences set</span><span><?= (int)$preflight['students_with_prefs'] ?></span></div>
        <div class="field-row"><span>Offers with applicant rankings</span><span><?= (int)$preflight['offers_with_rankings'] ?></span></div>

        <?php
        $ready = $preflight['students_with_prefs'] > 0 && $preflight['offers_with_rankings'] > 0;
        ?>

        <?php if (!$ready): ?>
            <div class="alert alert-error" style="margin-top:14px;">
                Not enough data to run matching. Students must rank offers AND offer posters must rank applicants.
            </div>
        <?php else: ?>
            <form method="POST" style="margin-top:14px;">
                <input type="hidden" name="action" value="run">
                <input type="hidden" name="cycle_id" value="<?= $activeCycle['id'] ?>">
                <button type="submit" class="btn btn-primary" style="width:auto;"
                        onclick="return confirm('Run Gale-Shapley matching for cycle \'<?= htmlspecialchars($activeCycle['name']) ?>\'? Results will be tentative until you publish them.');">
                    ▶ Run matching
                </button>
            </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($reviewRound): ?>
    <h2 style="margin-top:32px;font-size:18px;">Round #<?= $reviewRound['id'] ?> — review before publishing</h2>

    <div class="card">
        <div class="field-row"><span>Cycle</span><span><?= htmlspecialchars($reviewRound['cycle_name']) ?></span></div>
        <div class="field-row"><span>Ran at</span><span><?= htmlspecialchars($reviewRound['run_at']) ?></span></div>
        <div class="field-row"><span>Total allocations</span><span><?= (int)$reviewRound['allocation_count'] ?></span></div>
        <div class="field-row">
            <span>Stability</span>
            <span>
                <?php if ((int)$reviewRound['blocking_pairs'] === 0): ?>
                    <span class="badge badge-open">✓ Stable (0 blocking pairs)</span>
                <?php else: ?>
                    <span class="badge badge-closed">✗ <?= (int)$reviewRound['blocking_pairs'] ?> blocking pairs</span>
                <?php endif; ?>
            </span>
        </div>

        <?php if (!empty($reviewAllocations)): ?>
            <h3 style="margin-top:18px;font-size:15px;">Tentative allocations</h3>
            <table>
                <thead><tr><th>Offer</th><th>Student</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($reviewAllocations as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['offer_title']) ?></td>
                        <td><?= htmlspecialchars($a['student_name']) ?> · <span class="offer-meta"><?= htmlspecialchars($a['matricola']) ?></span></td>
                        <td><span class="badge badge-pending"><?= htmlspecialchars($a['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="margin-top:14px;color:var(--slate);">No allocations produced by this round.</p>
        <?php endif; ?>

        <div style="margin-top:18px;display:flex;gap:10px;">
            <a href="<?= BASE_URL ?>/admin/matching-visualizer.php?round_id=<?= $reviewRound['id'] ?>"
                class="btn btn-secondary" style="width:auto;">
                📊 Visualize round
            </a>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="publish">
                <input type="hidden" name="round_id" value="<?= $reviewRound['id'] ?>">
                <button type="submit" class="btn btn-primary" style="width:auto;"
                        onclick="return confirm('Publish this matching round? Students will be notified and results become final.');">
                    ✓ Publish results
                </button>
            </form>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="discard">
                <input type="hidden" name="round_id" value="<?= $reviewRound['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="width:auto;"
                        onclick="return confirm('Discard this round? All tentative allocations will be deleted.');">
                    ✗ Discard round
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<h2 style="margin-top:40px;font-size:18px;">Round history</h2>
<?php if (empty($rounds)): ?>
    <div class="card empty-state"><p>No matching rounds have been run yet.</p></div>
<?php else: ?>
    <div class="card">
        <table>
            <thead><tr><th>Round</th><th>Cycle</th><th>Status</th><th>Blocking pairs</th><th>Allocations</th><th>Ran at</th></tr></thead>
            <tbody>
            <?php foreach ($rounds as $r): ?>
                <tr>
                    <td class="offer-meta">
                        <a href="<?= BASE_URL ?>/admin/matching-visualizer.php?round_id=<?= (int)$r['id'] ?>">
                            #<?= (int)$r['id'] ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($r['cycle_name']) ?></td>
                    <td><span class="badge badge-<?= $r['status'] === 'published' ? 'open' : ($r['status'] === 'unstable' ? 'closed' : 'pending') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= (int)$r['blocking_pairs'] ?></td>
                    <td><?= (int)$r['allocation_count'] ?></td>
                    <td><?= htmlspecialchars($r['run_at'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>