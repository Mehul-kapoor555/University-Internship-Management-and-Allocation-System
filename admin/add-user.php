<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$me = currentUser();
$activePage = 'users';
$error = '';
$success = '';
$newUserId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role      = $_POST['role'] ?? '';
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');

    if (!in_array($role, ['student','professor','company','coordinator'], true)) {
        $error = 'Invalid role.';
    } elseif ($email === '' || $password === '' || $firstName === '' || $lastName === '') {
        $error = 'Name, email, and password are required.';
    } else {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) throw new Exception('That email is already in use.');

            $hash = hashPassword($password);
            $db->prepare(
                "INSERT INTO users (email, password_hash, role, first_name, last_name, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)"
            )->execute([$email, $hash, $role, $firstName, $lastName]);
            $newUserId = (int)$db->lastInsertId();

            if ($role === 'student') {
                $matricola = trim($_POST['matricola'] ?? '');
                $program   = trim($_POST['program'] ?? '');
                $enrollYr  = (int)($_POST['enrollment_year'] ?? date('Y'));
                if ($matricola === '' || $program === '') {
                    throw new Exception('Matricola and program are required for students.');
                }
                // Admin-created students are approved immediately (the admin is
                // vouching for them). Exams/CFU are filled in on the profile page.
                $db->prepare(
                    "INSERT INTO students
                        (user_id, matricola, program, enrollment_year, total_cfu,
                         registration_status, approved_by_user_id, approved_at)
                     VALUES (?, ?, ?, ?, 0, 'approved', ?, NOW())"
                )->execute([$newUserId, $matricola, $program, $enrollYr, $me['id']]);

            } elseif ($role === 'professor') {
                $dept = trim($_POST['department'] ?? '');
                if ($dept === '') throw new Exception('Department is required for professors.');
                $db->prepare("INSERT INTO professors (user_id, department) VALUES (?, ?)")
                   ->execute([$newUserId, $dept]);

            } elseif ($role === 'company') {
                $companyName = trim($_POST['company_name'] ?? '');
                if ($companyName === '') throw new Exception('Company name is required.');
                $db->prepare("INSERT INTO companies (user_id, company_name) VALUES (?, ?)")
                   ->execute([$newUserId, $companyName]);

            } elseif ($role === 'coordinator') {
                $dept = trim($_POST['department'] ?? '') ?: 'Computer Science';
                $db->prepare("INSERT INTO coordinators (user_id, department) VALUES (?, ?)")
                   ->execute([$newUserId, $dept]);
            }

            notify($db, $newUserId, 'Welcome',
                   'Your account has been created by the coordinator.', 'info', BASE_URL . '/profile.php');

            $db->commit();
            $success = 'Account created for ' . htmlspecialchars($firstName . ' ' . $lastName) . '.';

        } catch (Throwable $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Add new user</h1>
    <p>Create an account for any role. Admin-created students are approved immediately;
       fill in their exams afterwards on the profile page.</p>
</div>

<p><a href="<?= BASE_URL ?>/admin/users.php">← Back to users</a></p>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success">
        <?= $success ?>
        <?php if ($newUserId): ?>
            <a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= $newUserId ?>">Open profile →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" class="card form-narrow">
    <label for="role">Account type</label>
    <select id="role" name="role" required onchange="toggleFields()">
        <option value="student">Student</option>
        <option value="professor">Professor</option>
        <option value="company">Company</option>
        <option value="coordinator">Coordinator</option>
    </select>

    <label>First name / Contact first name</label>
    <input type="text" name="first_name" required>
    <label>Last name / Contact last name</label>
    <input type="text" name="last_name" required>
    <label>Email</label>
    <input type="email" name="email" required>
    <label>Temporary password</label>
    <input type="text" name="password" required>

    <div id="fields-student" class="role-fields">
        <label>Matricola</label>
        <input type="text" name="matricola" placeholder="e.g. 563906">
        <label>Program</label>
        <input type="text" name="program" placeholder="e.g. Computer Science">
        <label>Enrollment year</label>
        <input type="number" name="enrollment_year" value="<?= date('Y') ?>" min="2000" max="2100">
    </div>

    <div id="fields-professor" class="role-fields">
        <label>Department</label>
        <input type="text" name="department" placeholder="e.g. MIFT">
    </div>

    <div id="fields-company" class="role-fields">
        <label>Company name</label>
        <input type="text" name="company_name">
    </div>

    <div id="fields-coordinator" class="role-fields">
        <label>Department</label>
        <input type="text" name="department" placeholder="e.g. Computer Science">
    </div>

    <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Create account</button>
</form>

<script>
function toggleFields() {
    var role = document.getElementById('role').value;
    document.querySelectorAll('.role-fields').forEach(function(el){ el.classList.remove('active'); });
    var target = document.getElementById('fields-' + role);
    if (target) target.classList.add('active');
}
toggleFields();
</script>

<?php require '../includes/footer.php'; ?>
