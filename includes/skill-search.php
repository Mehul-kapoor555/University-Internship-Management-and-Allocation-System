<?php
require 'db.php';

// Returns JSON array of skills matching the search term
$term = trim($_GET['q'] ?? '');
$excludeGroups = true; // don't return group nodes (parent_skill_id IS NULL means it's a group)

if (strlen($term) < 1) {
    echo json_encode([]);
    exit;
}

$stmt = $db->prepare(
    "SELECT id, name, category, parent_skill_id
     FROM skills
     WHERE name LIKE ? AND parent_skill_id IS NOT NULL
     ORDER BY name ASC
     LIMIT 15"
);
$stmt->execute(['%' . $term . '%']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($results);