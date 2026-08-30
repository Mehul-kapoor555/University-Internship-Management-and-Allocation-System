<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['professor']);

$user = currentUser();
$activePage = 'students';

$stmt = $db->prepare(
    "SELECT su.first_name, su.last_name, s.matricola, s.program, sa.assigned_at
     FROM supervisor_assignments sa
     JOIN professors p ON p.id = sa.professor_id
     JOIN allocations al ON al.id = sa.allocation_id
     JOIN students s ON s.id = al.student_id
     JOIN users su ON su.id = s.user_id
     WHERE p.user_id = ?
     ORDER BY sa.assigned_at DESC"
);
$stmt->execute([$user['id']]);
$students = $stmt->fetchAll();

require '../includes/header.php';
?>

<div class="page-header">
    <h1>My students</h1>
    <p>Populated once the matching engine allocates supervisors.</p>
</div>

<?php if (empty($students)): ?>
    <div class="card empty-state"><p>No students assigned yet — this fills in after a matching round runs.</p></div>
<?php else: ?>
    <div class="card">
        <table>
            <thead><tr><th>Student</th><th>Matricola</th><th>Program</th><th>Assigned</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                    <td class="offer-meta"><?= htmlspecialchars($s['matricola']) ?></td>
                    <td><?= htmlspecialchars($s['program']) ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($s['assigned_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>