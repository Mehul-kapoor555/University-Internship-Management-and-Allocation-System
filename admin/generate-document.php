<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/PdfGenerator.php';
requireRole(['coordinator']);

$allocationId = (int)($_GET['allocation_id'] ?? 0);
$type = $_GET['type'] ?? 'agreement';

if ($allocationId <= 0) {
    http_response_code(400);
    echo 'Missing allocation_id.';
    exit;
}

if ($type === 'agreement') {
    $result = PdfGenerator::placementAgreement($db, $allocationId);
} else {
    http_response_code(400);
    echo 'Unknown document type.';
    exit;
}

if (empty($result['ok'])) {
    http_response_code(422);
    echo htmlspecialchars($result['error'] ?? 'Could not generate document.');
    exit;
}

$path = $result['path'];
$isPdf = ($result['format'] ?? '') === 'pdf';
$downloadName = basename($path);

// Stream the file inline so the browser opens it (PDF) or renders it (HTML).
header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'text/html; charset=utf-8'));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
