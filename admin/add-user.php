<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$me = currentUser();
$activePage = 'users';
$error = '';
$success = '';
$newUserId = null;

// Keeps whatever was typed when validation fails, so a rejected submission
// does not throw away the whole form.
$old = $_POST;

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
                $dupe = $db->prepare("SELECT id FROM students WHERE matricola = ?");
                $dupe->execute([$matricola]);
                if ($dupe->fetch()) {
                    throw new Exception('That matricola is already registered.');
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
                // Reads `department`, which now belongs to the professor block
                // ALONE. It used to collide with an identically named coordinator
                // field: both were present in the DOM at once, hidden blocks are
                // still submitted, and PHP keeps the LAST value for a repeated
                // name — so the empty coordinator box silently overwrote whatever
                // department was typed here, and every professor was rejected for
                // having no department. The coordinator field is now
                // `coord_department`, and the JS below disables inactive blocks
                // so nothing from another role is posted at all.
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
                $dept = trim($_POST['coord_department'] ?? '') ?: 'Computer Science';
                $db->prepare("INSERT INTO coordinators (user_id, department) VALUES (?, ?)")
                   ->execute([$newUserId, $dept]);
            }

            notify($db, $newUserId, 'Welcome',
                   'Your account has been created by the coordinator.', 'info', BASE_URL . '/profile.php');

            $db->commit();
            $success = 'Account created for ' . htmlspecialchars($firstName . ' ' . $lastName) . '.';
            $old = [];   // clear the form after a successful create

        } catch (Throwable $e) {
            $db->rollBack();
            $error = $e->getMessage();
            $newUserId = null;
        }
    }
}

/** Short helper so the form can repopulate itself after a failed submit. */
function keep(array $old, string $field, string $fallback = ''): string {
    return htmlspecialchars($old[$field] ?? $fallback);
}

$selectedRole = $old['role'] ?? 'student';

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

<form method="POST" class="card form-narrow" id="add-user-form">
    <label for="role">Account type</label>
    <select id="role" name="role" required onchange="toggleFields()">
        <option value="student"     <?= $selectedRole === 'student'     ? 'selected' : '' ?>>Student</option>
        <option value="professor"   <?= $selectedRole === 'professor'   ? 'selected' : '' ?>>Professor</option>
        <option value="company"     <?= $selectedRole === 'company'     ? 'selected' : '' ?>>Company</option>
        <option value="coordinator" <?= $selectedRole === 'coordinator' ? 'selected' : '' ?>>Coordinator</option>
    </select>

    <!--
      The role-specific block sits ABOVE the account fields on purpose. For a
      company the organisation is the thing being registered and the contact
      person is secondary, so "Company name" is the first question asked after
      choosing the role. The same ordering reads naturally for the other roles:
      identify the subject first, then set up its login.
    -->

    <div id="fields-student" class="role-fields">
        <label for="matricola">Matricola</label>
        <input type="text" id="matricola" name="matricola" value="<?= keep($old, 'matricola') ?>" placeholder="e.g. 563906">
        <label for="program">Program</label>
        <input type="text" id="program" name="program" value="<?= keep($old, 'program') ?>" placeholder="e.g. BSc Computer Science">
        <label for="enrollment_year">Enrollment year</label>
        <input type="number" id="enrollment_year" name="enrollment_year"
               value="<?= keep($old, 'enrollment_year', date('Y')) ?>" min="2000" max="2100">
    </div>

    <div id="fields-professor" class="role-fields">
        <label for="department">Department</label>
        <input type="text" id="department" name="department" value="<?= keep($old, 'department') ?>" placeholder="e.g. MIFT">
    </div>

    <div id="fields-company" class="role-fields">
        <label for="company_name">Company name</label>
        <input type="text" id="company_name" name="company_name" value="<?= keep($old, 'company_name') ?>">
    </div>

    <div id="fields-coordinator" class="role-fields">
        <label for="coord_department">Department</label>
        <input type="text" id="coord_department" name="coord_department" value="<?= keep($old, 'coord_department') ?>" placeholder="e.g. Computer Science">
    </div>

    <label for="first_name" id="label-first">First name</label>
    <input type="text" id="first_name" name="first_name" value="<?= keep($old, 'first_name') ?>" required>

    <label for="last_name" id="label-last">Last name</label>
    <input type="text" id="last_name" name="last_name" value="<?= keep($old, 'last_name') ?>" required>

    <label for="email" id="label-email">Email</label>
    <input type="email" id="email" name="email" value="<?= keep($old, 'email') ?>" required>

    <label for="password">Temporary password</label>
    <input type="text" id="password" name="password" required>

    <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Create account</button>
</form>

<script>
function toggleFields() {
    var role = document.getElementById('role').value;

    document.querySelectorAll('.role-fields').forEach(function (el) {
        var isActive = (el.id === 'fields-' + role);
        el.classList.toggle('active', isActive);

        // Hiding a block with CSS does NOT stop the browser submitting its
        // inputs. Disabling them does. Without this, fields belonging to other
        // roles still arrive in $_POST, which is how the department collision
        // bug was able to happen in the first place.
        el.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.disabled = !isActive;
        });
    });

    // A company registers an organisation with a contact person attached; the
    // other roles register an individual. Relabel so the form reads correctly.
    var isCompany = (role === 'company');
    document.getElementById('label-first').textContent = isCompany ? 'Contact first name' : 'First name';
    document.getElementById('label-last').textContent  = isCompany ? 'Contact last name'  : 'Last name';
    document.getElementById('label-email').textContent = isCompany ? 'Contact email'      : 'Email';
}
toggleFields();
</script>

<?php require '../includes/footer.php'; ?>