<?php
// Site base path — edit this ONE line if your project folder name changes.
// Example: if you access the app at localhost/Internship/..., this is '/Internship'.
// If the app is ever the domain root (localhost/...), set this to ''.
define('BASE_URL', '/Internship');

$host = 'localhost';
$dbname = 'internship_system';
$user = 'root';
$pass = 'mehul2003'; // MySQL root password

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}