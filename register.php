<?php
require 'includes/db.php';
require 'includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $matricola = trim($_POST['matricola']);
    $program = trim($_POST['program']);
    $enrollmentYear = (int)$_POST['enrollment_year'];

    if ($email === '' || $password === '' || $firstName === '' || $lastName === ''
        || $matricola === '' || $program === '' || !$enrollmentYear) {
        $error = 'All fields are required.';
    } else {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('An account with that email already exists.');
            }

            $stmt = $db->prepare("SELECT id FROM students WHERE matricola = ?");
            $stmt->execute([$matricola]);
            if ($stmt->fetch()) {
                throw new Exception('That matricola is already registered.');
            }

            $hash = hashPassword($password);
            $stmt = $db->prepare(
                "INSERT INTO users (email, password_hash, role, first_name, last_name, is_active)
                 VALUES (?, ?, 'student', ?, ?, 0)"
            );
            $stmt->execute([$email, $hash, $firstName, $lastName]);
            $userId = $db->lastInsertId();

            $stmt = $db->prepare(
                "INSERT INTO students (user_id, matricola, program, enrollment_year, registration_status)
                 VALUES (?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([$userId, $matricola, $program, $enrollmentYear]);

            // Notify all coordinators of the pending registration
            $stmt = $db->query("SELECT id FROM users WHERE role = 'coordinator' AND is_active = 1");
            $coordinators = $stmt->fetchAll();
            foreach ($coordinators as $c) {
                notify(
                    $db, $c['id'],
                    'New student registration pending',
                    $firstName . ' ' . $lastName . ' (' . $matricola . ') has requested access.',
                    'action',
                    BASE_URL . '/admin/pending-registrations.php'
                );
            }

            $db->commit();
            header('Location: ' . BASE_URL . '/pending-approval.php');
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register · UNIME Internship System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-box" style="max-width: 420px;">
        <div class="eyebrow">Università di Messina</div>
        <h1>Student registration</h1>

        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <p style="font-size:13px;color:var(--slate);margin-bottom:6px;">
            Only students may self-register. Professors and companies are registered by the coordinator's office.
        </p>

        <form method="POST">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>

            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>

            <label for="matricola">Matricola</label>
            <input type="text" id="matricola" name="matricola" value="<?= htmlspecialchars($_POST['matricola'] ?? '') ?>" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>

            <label for="program">Degree program</label>
            <input type="text" id="program" name="program" value="<?= htmlspecialchars($_POST['program'] ?? '') ?>" placeholder="e.g. BSc Computer Science" required>

            <label for="enrollment_year">Enrollment year</label>
            <input type="number" id="enrollment_year" name="enrollment_year" min="2000" max="2100" value="<?= htmlspecialchars($_POST['enrollment_year'] ?? '') ?>" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn btn-primary">Submit registration</button>
        </form>
        <p class="foot-note">Already registered? <a href="<?= BASE_URL ?>/login.php">Log in</a></p>
    </div>
</div>
</body>
</html>