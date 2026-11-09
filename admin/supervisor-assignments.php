<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/engine_runner.php';
requireRole(['coordinator']);

$user = currentUser();
$activePage = 'supervisor-assignments';
$message = '';
$error = '';
$runStats = null;

// Active cycle
$activeCycle = $db->query(
    "SELECT * FROM academic_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
)->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $activeCycle) {
    $action = $_POST['action'] ?? '';

    if ($action === 'optimize' || $action === 'preview') {
        $args = ['cycle-id' => (int)$activeCycle['id']];
        if ($action === 'preview') {
            $args['dry-run'] = '1';
        }
        // EngineRunner emits "--key value" pairs. The optimizer accepts
        // --dry-run as a valued flag, so preview sends --dry-run 1 (compute
        // only) while optimize sends no dry-run arg at all (defaults to write).
        $result = EngineRunner::run('supervisor_optimizer', $args);

        if ($result['success']) {
            $runStats = $result['data'];
            $verb = ($action === 'preview') ? 'Preview' : 'Optimization';
            $message = $verb . " complete in {$result['duration_ms']} ms. "
                     . "Greedy cost {$runStats['greedy_cost']} → final {$runStats['total_cost']} "
                     . "({$runStats['swaps_applied']} swap" . ($runStats['swaps_applied'] == 1 ? '' : 's') . " applied).";
        } else {
            $error = 'Optimizer failed: ' . $result['error'];
        }

    } elseif ($action === 'clear') {
        // Undo assignments for this cycle (also roll back the load counters).
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT sa.professor_id, COUNT(*) AS n
                FROM supervisor_assignments sa
                JOIN allocations a       ON a.id = sa.allocation_id
                JOIN matching_rounds r   ON r.id = a.round_id
                WHERE r.cycle_id = ?
                GROUP BY sa.professor_id
            ");
            $stmt->execute([(int)$activeCycle['id']]);
            $dec = $db->prepare("UPDATE professors SET current_load = GREATEST(0, current_load - ?) WHERE id = ?");
            foreach ($stmt->fetchAll() as $row) {
                $dec->execute([(int)$row['n'], (int)$row['professor_id']]);
            }
            $db->prepare("
                DELETE sa FROM supervisor_assignments sa
                JOIN allocations a     ON a.id = sa.allocation_id
                JOIN matching_rounds r ON r.id = a.round_id
                WHERE r.cycle_id = ?
            ")->execute([(int)$activeCycle['id']]);
            $db->commit();
            $message = 'Supervisor assignments cleared for this cycle.';
        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Could not clear assignments: ' . $e->getMessage();
        }
    }
}

// ---- Data for display -------------------------------------------------------

// Accepted placements in the active cycle + their current supervisor (if any)
$placements = [];
$assignedCount = 0;
$pendingCount = 0;
if ($activeCycle) {
    $stmt = $db->prepare("
        SELECT a.id AS allocation_id,
               o.title AS offer_title, o.domain,
               CONCAT(su.first_name, ' ', su.last_name) AS student_name,
               s.matricola,
               sa.cost_score,
               CONCAT(pu.first_name, ' ', pu.last_name) AS professor_name,
               p.department
        FROM allocations a
        JOIN matching_rounds r   ON r.id = a.round_id
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN students s          ON s.id = a.student_id
        JOIN users su            ON su.id = s.user_id
        LEFT JOIN supervisor_assignments sa ON sa.allocation_id = a.id
        LEFT JOIN professors p   ON p.id = sa.professor_id
        LEFT JOIN users pu       ON pu.id = p.user_id
        WHERE r.cycle_id = ? AND a.decision = 'accepted'
        ORDER BY (sa.allocation_id IS NULL) DESC, o.title
    ");
    $stmt->execute([(int)$activeCycle['id']]);
    $placements = $stmt->fetchAll();
    foreach ($placements as $p) {
        if ($p['professor_name']) { $assignedCount++; } else { $pendingCount++; }
    }
}

// Professor load overview
$profLoad = $db->query("
    SELECT CONCAT(u.first_name, ' ', u.last_name) AS name, p.department,
           p.supervision_capacity, p.current_load, p.is_available
    FROM professors p
    JOIN users u ON u.id = p.user_id
    ORDER BY p.current_load DESC, name
")->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Supervisor assignment</h1>
    <p>Assigns an academic supervisor to every accepted placement using a
       greedy construction followed by swap-improvement local search, minimising
       total domain-distance and load-imbalance cost.</p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$activeCycle): ?>
    <div class="card empty-state">
        <p>No active academic cycle. <a href="<?= BASE_URL ?>/admin/cycles.php">Activate one</a> first.</p>
    </div>
<?php else: ?>

    <div class="card">
        <h3>Cycle "<?= htmlspecialchars($activeCycle['name']) ?>"</h3>
        <div class="field-row"><span>Accepted placements</span><span><?= count($placements) ?></span></div>
        <div class="field-row"><span>Supervisor assigned</span><span><?= $assignedCount ?></span></div>
        <div class="field-row"><span>Awaiting supervisor</span><span><?= $pendingCount ?></span></div>

        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($pendingCount > 0): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="preview">
                    <button type="submit" class="btn btn-secondary" style="width:auto;">Preview (dry run)</button>
                </form>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="optimize">
                    <button type="submit" class="btn btn-primary" style="width:auto;"
                        onclick="return confirm('Assign supervisors to all unassigned accepted placements?');">
                        ▶ Optimise &amp; assign
                    </button>
                </form>
            <?php else: ?>
                <p style="color:var(--slate);margin:0;">
                    <?= count($placements) === 0
                        ? 'No accepted placements yet — students must accept their matches first.'
                        : 'Every accepted placement already has a supervisor.' ?>
                </p>
            <?php endif; ?>
            <?php if ($assignedCount > 0): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-secondary" style="width:auto;"
                        onclick="return confirm('Clear all supervisor assignments for this cycle and reset professor loads?');">
                        ✗ Clear assignments
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($runStats): ?>
        <div class="card">
            <h3>Optimiser result</h3>
            <div class="field-row"><span>Placements considered</span><span><?= (int)$runStats['placements'] ?></span></div>
            <div class="field-row"><span>Professors available</span><span><?= (int)$runStats['professors_available'] ?></span></div>
            <div class="field-row"><span>Total supervision capacity</span><span><?= (int)$runStats['total_capacity'] ?></span></div>
            <div class="field-row"><span>Greedy cost</span><span><?= htmlspecialchars($runStats['greedy_cost']) ?></span></div>
            <div class="field-row"><span>Final cost (after swaps)</span><span><?= htmlspecialchars($runStats['total_cost']) ?></span></div>
            <div class="field-row"><span>Improvement from swaps</span><span><?= htmlspecialchars($runStats['improvement']) ?> over <?= (int)$runStats['swaps_applied'] ?> swap(s)</span></div>
            <div class="field-row"><span>Unassigned (no capacity)</span><span><?= (int)$runStats['unassigned'] ?></span></div>
            <?php if (!empty($runStats['dry_run'])): ?>
                <div class="alert" style="margin-top:12px;background:#fef9c3;color:#854d0e;">
                    This was a dry run — nothing was written. Use “Optimise &amp; assign” to persist.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2 style="margin-top:32px;font-size:18px;">Placements &amp; supervisors</h2>
    <?php if (empty($placements)): ?>
        <div class="card empty-state"><p>No accepted placements in this cycle yet.</p></div>
    <?php else: ?>
        <div class="card">
            <table>
                <thead>
                    <tr><th>Placement</th><th>Student</th><th>Domain</th><th>Supervisor</th><th>Cost</th><th>Document</th></tr>
                </thead>
                <tbody>
                <?php foreach ($placements as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['offer_title']) ?></td>
                        <td><?= htmlspecialchars($p['student_name']) ?> <span class="offer-meta">(<?= htmlspecialchars($p['matricola']) ?>)</span></td>
                        <td><?= htmlspecialchars($p['domain'] ?? '—') ?></td>
                        <td>
                            <?php if ($p['professor_name']): ?>
                                <?= htmlspecialchars($p['professor_name']) ?>
                                <span class="offer-meta"><?= htmlspecialchars($p['department']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-pending">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $p['cost_score'] !== null ? htmlspecialchars(number_format((float)$p['cost_score'], 4)) : '—' ?></td>
                        <td>
                            <?php if ($p['professor_name']): ?>
                                <a href="<?= BASE_URL ?>/admin/generate-document.php?type=agreement&allocation_id=<?= (int)$p['allocation_id'] ?>"
                                   target="_blank" class="offer-meta">Agreement PDF</a>
                            <?php else: ?>
                                <span class="offer-meta">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 style="margin-top:32px;font-size:18px;">Professor load</h2>
    <div class="card">
        <table>
            <thead><tr><th>Professor</th><th>Department</th><th>Load / Capacity</th><th>Available</th></tr></thead>
            <tbody>
            <?php foreach ($profLoad as $pl): ?>
                <tr>
                    <td><?= htmlspecialchars($pl['name']) ?></td>
                    <td><?= htmlspecialchars($pl['department']) ?></td>
                    <td><?= (int)$pl['current_load'] ?> / <?= (int)$pl['supervision_capacity'] ?></td>
                    <td><?= $pl['is_available'] ? '<span class="badge badge-open">Yes</span>' : '<span class="badge badge-closed">No</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?php require '../includes/footer.php'; ?>
