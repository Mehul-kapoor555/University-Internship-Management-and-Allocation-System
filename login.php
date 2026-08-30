<?php
require 'includes/db.php';
require 'includes/auth.php';

$error = '';
$justRegistered = isset($_GET['registered']);
$justApproved = isset($_GET['approved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($password, $user['password_hash'])) {
        $error = 'Invalid email or password.';
    } elseif (!$user['is_active']) {
        // Check whether it's a pending student vs a disabled account
        if ($user['role'] === 'student') {
            $stmt = $db->prepare("SELECT registration_status FROM students WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $stu = $stmt->fetch();
            if ($stu && $stu['registration_status'] === 'pending') {
                $error = 'Your account is pending administrator approval.';
            } elseif ($stu && $stu['registration_status'] === 'rejected') {
                $error = 'Your registration was rejected. Please contact the coordinator\'s office.';
            } else {
                $error = 'Your account has been disabled. Contact the coordinator\'s office.';
            }
        } else {
            $error = 'Your account has been disabled. Contact the coordinator\'s office.';
        }
    } else {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · UNIME Internship System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-box">
        <div class="eyebrow">Università di Messina</div>
        <h1>Sistema Tirocini</h1>

        <?php if ($justApproved): ?><div class="alert alert-success">Your registration has been approved — you may now log in.</div><?php endif; ?>
        <?php if ($justRegistered): ?><div class="alert alert-success">Account created — log in below.</div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn btn-primary">Log in</button>
        </form>
        <p class="foot-note">Student? <a href="<?= BASE_URL ?>/register.php">Register here</a></p>
    </div>
</div>
</body>
</html>