<?php
require 'includes/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registration pending · UNIME Internship System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-box">
        <div class="eyebrow">Università di Messina</div>
        <h1>Registration submitted</h1>
        <p style="font-size:14px;color:var(--slate);line-height:1.6;">
            Your registration is awaiting approval from the coordinator's office.
            You'll receive a notification once your account has been reviewed.
        </p>
        <p style="font-size:13px;color:var(--slate-light);margin-top:20px;">
            Registrations are typically reviewed within 1-2 working days.
        </p>
        <a href="<?= BASE_URL ?>/login.php" class="btn btn-secondary" style="width:auto;margin-top:14px;">Back to login</a>
    </div>
</div>
</body>
</html>