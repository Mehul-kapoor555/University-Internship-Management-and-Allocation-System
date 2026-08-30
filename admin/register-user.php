<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$user = currentUser();
$activePage = 'register-user';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'];
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);

    if (!in_array($role, ['professor', 'company', 'coordinator'])) {
        $error = 'Invalid role.';
    } elseif ($email === '' || $password === '' || $firstName === '' || $lastName === '') {
        $error = 'Name, email, and password are required.';
    } else {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('That email is already in use.');
            }

            $hash = hashPassword($password);
            $stmt = $db->prepare(
                "INSERT INTO users (email, password_hash, role, first_name, last_name, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );
            $stmt->execute([$email, $hash, $role, $firstName, $lastName]);
            $newUserId = $db->lastInsertId();

            if ($role === 'professor') {
                $dept = trim($_POST['department']);
                if ($dept === '') throw new Exception('Department is required for professors.');
                $stmt = $db->prepare("INSERT INTO professors (user_id, department) VALUES (?, ?)");
                $stmt->execute([$newUserId, $dept]);

            } elseif ($role === 'company') {
                $companyName = trim($_POST['company_name']);
                if ($companyName === '') throw new Exception('Company name is required.');
                $stmt = $db->prepare("INSERT INTO companies (user_id, company_name) VALUES (?, ?)");
                $stmt->execute([$newUserId, $companyName]);

            } elseif ($role === 'coordinator') {
                $dept = trim($_POST['department']) ?: 'Computer Science';
                $stmt = $db->prepare("INSERT INTO coordinators (user_id, department) VALUES (?, ?)");
                $stmt->execute([$newUserId, $dept]);
            }

            notify(
                $db, $newUserId,
                'Welcome to Sistema Tirocini',
                'Your account has been created by the coordinator. Please complete your profile.',
                'info',
                BASE_URL . '/profile.php'
            );

            $db->commit();
            $success = 'Account created for ' . htmlspecialchars($firstName . ' ' . $lastName) . '.';

        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Register a new user</h1>
    <p>Create accounts for professors, companies, or other coordinators. Students self-register through the public page.</p>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

<form method="POST" class="card form-narrow">
    <label for="role">Account type</label>
    <select id="role" name="role" required onchange="toggleFields()">
        <option value="professor">Professor</option>
        <option value="company">Company</option>
        <option value="coordinator">Coordinator</option>
    </select>

    <label for="first_name">First name / Contact first name</label>
    <input type="text" id="first_name" name="first_name" required>

    <label for="last_name">Last name / Contact last name</label>
    <input type="text" id="last_name" name="last_name" required>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required>

    <label for="password">Temporary password</label>
    <input type="text" id="password" name="password" required>

    <div id="fields-professor" class="role-fields active">
        <label for="department">Department</label>
        <input type="text" id="department" name="department" placeholder="e.g. MIFT">
    </div>

    <div id="fields-company" class="role-fields">
        <label for="company_name">Company name</label>
        <input type="text" id="company_name" name="company_name">
    </div>

    <div id="fields-coordinator" class="role-fields">
        <label for="coord_department">Department</label>
        <input type="text" id="coord_department" name="department" placeholder="e.g. Computer Science">
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