<?php
session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireRole($allowedRoles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        die("Access denied — this page is not available for your role.");
    }
}

function hashPassword($plainPassword) {
    return password_hash($plainPassword, PASSWORD_DEFAULT);
}

function verifyPassword($plainPassword, $hash) {
    return password_verify($plainPassword, $hash);
}

function currentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'name' => $_SESSION['name'] ?? null,
    ];
}

/**
 * Emit an in-app notification. This is the primary event sink for the
 * workflow engine — the same interface will later have SMTP/webhook adapters.
 */
function notify($db, $userId, $title, $message, $type = 'info', $link = null) {
    $stmt = $db->prepare(
        "INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $title, $message, $type, $link]);
}