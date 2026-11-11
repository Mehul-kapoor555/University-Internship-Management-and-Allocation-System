<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$activePage = 'cycles';
$error = '';

/* This page is the record of what has happened. Cycles are started from Cycle
   control, which is the page that runs them, so there is no creation form here
   — one place to start a cycle means one place where the "exactly one active
   cycle" rule has to hold. */

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $db->prepare("SELECT * FROM academic_cycles WHERE name LIKE ? ORDER BY start_date DESC");
    $stmt->execute(['%' . $search . '%']);
    $cycles = $stmt->fetchAll();
} else {
    $cycles = $db->query("SELECT * FROM academic_cycles ORDER BY start_date DESC")->fetchAll();
}

/* The step-by-step record of a cycle lives here rather than on Cycle control,
   which is for running the cycle, not reading back what already happened.
   Defaults to the active cycle; any cycle can be inspected with ?id=. */
$historyCycleId = (int)($_GET['id'] ?? 0);
if (!$historyCycleId) {
    foreach ($cycles as $c) {
        if ($c['is_active']) { $historyCycleId = (int)$c['id']; break; }
    }
}
$history = [];
$historyCycleName = '';
if ($historyCycleId) {
    foreach ($cycles as $c) {
        if ((int)$c['id'] === $historyCycleId) { $historyCycleName = $c['name']; break; }
    }
    $stmt = $db->prepare("
        SELECT t.*, CONCAT(u.first_name,' ',u.last_name) AS triggered_by_name
        FROM cycle_state_transitions t
        LEFT JOIN users u ON u.id = t.triggered_by_user_id
        WHERE t.cycle_id = ?
        ORDER BY t.transitioned_at ASC
    ");
    $stmt->execute([$historyCycleId]);
    $history = $stmt->fetchAll();
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Academic cycles</h1>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        <div style="flex:1 1 260px;">
            <label for="q" style="margin:0 0 6px;">Find a cycle</label>
            <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search by name, e.g. Summer 2026" style="margin:0;">
        </div>
        <button type="submit" class="btn btn-secondary" style="width:auto;">Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-secondary" style="width:auto;">Clear</a>
        <?php endif; ?>
    </form>
    <p style="font-size:12px;color:var(--slate-light);margin:12px 0 0;">
        Cycles are started from <a href="<?= BASE_URL ?>/admin/cycle-control.php">Cycle control</a>.
    </p>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Started</th><th>Stage</th><th></th><th></th></tr></thead>
        <tbody>
        <?php if (empty($cycles)): ?>
            <tr><td colspan="5" style="color:var(--slate-light);">
                <?= $search !== '' ? 'No cycle matches that name.' : 'No cycles yet. Start one from Cycle control.' ?>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($cycles as $c): ?>
            <?php
                /* Two different facts, shown separately. The stage is how far the
                   cycle got; "Running now" marks which one the system is driving.
                   Previously only is_active was shown, so a cancelled cycle still
                   read as Active. */
                $state = $c['current_state'] ?? 'DRAFT';
                $stageLabel = ucwords(strtolower(str_replace('_', ' ', $state)));
                $stageClass = $state === 'CANCELLED' ? 'rejected'
                            : ($state === 'FINAL_PUBLICATION' ? 'accepted' : 'open');
            ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['start_date']) ?></td>
                <td><span class="badge badge-<?= $stageClass ?>"><?= htmlspecialchars($stageLabel) ?></span></td>
                <td><?php if ($c['is_active']): ?><span class="pill">Running now</span><?php endif; ?></td>
                <td><a href="<?= BASE_URL ?>/admin/cycles.php?id=<?= (int)$c['id'] ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">History →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Audit trail. Every step a cycle took, who moved it, and whether the checks
     passed or were overridden. -->
<div class="card">
    <h2 class="section-heading" style="margin-top:0;">
        Cycle history<?= $historyCycleName ? ' — ' . htmlspecialchars($historyCycleName) : '' ?>
    </h2>

    <?php if (empty($history)): ?>
        <p style="font-size:13px;color:var(--slate-light);">
            Nothing recorded for this cycle yet. Steps appear here as the cycle is run
            from <a href="<?= BASE_URL ?>/admin/cycle-control.php">Cycle control</a>.
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>When</th><th>From</th><th>To</th><th>Step</th><th>Checks</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $t): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px;color:var(--slate);">
                        <?= htmlspecialchars(date('d M Y, H:i', strtotime($t['transitioned_at']))) ?>
                    </td>
                    <td class="mono"><?= htmlspecialchars($t['from_state']) ?></td>
                    <td class="mono"><?= htmlspecialchars($t['to_state']) ?></td>
                    <td class="mono"><?= htmlspecialchars($t['transition_key'] ?? '') ?></td>
                    <td>
                        <?php $g = $t['guard_result'] ?? 'passed'; ?>
                        <span class="badge badge-<?= $g === 'passed' ? 'accepted' : ($g === 'overridden' ? 'pending' : 'rejected') ?>">
                            <?= htmlspecialchars(ucfirst($g)) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($t['triggered_by_name'] ?? '—') ?></td>
                </tr>
                <?php if (!empty($t['notes'])): ?>
                    <tr><td colspan="6" style="background:var(--parchment);font-size:12px;color:var(--slate);font-style:italic;padding-left:24px;">
                        <?= htmlspecialchars($t['notes']) ?>
                    </td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.mono { font-family: var(--font-mono); font-size: 12px; }
</style>

<?php require '../includes/footer.php'; ?>