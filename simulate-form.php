<?php
/**
 * simulate-form.php — a local stand-in for a Google Form.
 *
 * This is NOT a replacement for the real Google Forms integration; it is a test
 * harness and demo safety net. It shows a tiny form, then POSTs to the SAME
 * form-receiver.php endpoint, carrying the SAME token + secret that Google's
 * Apps Script would send. It exercises the identical webhook path, so it proves
 * the receiver logic works without depending on the venue's internet.
 *
 * The link a student receives (formLink() in the engine) points here when
 * FORM_BASE_URL is left at its local default.
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/webhook_config.php';

$token = $_GET['token'] ?? '';
$kind  = $_GET['kind'] ?? '';

$kindLabels = [
    'signed_agreement'     => 'Signed internship agreement',
    'revision_resubmit'    => 'Revised documents',
    'final_report'         => 'Student final report',
    'company_evaluation'   => 'Company evaluation',
    'professor_assessment' => 'Professor assessment',
];

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST to the real receiver exactly as Apps Script would (JSON + secret).
    $endpoint = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/form-receiver.php';
    $body = json_encode([
        'secret'       => WEBHOOK_SECRET,
        'token'        => $_POST['token'] ?? $token,
        'kind'         => $_POST['kind'] ?? $kind,
        'submitted_by' => $_POST['submitted_by'] ?? 'Simulated submission',
        'answers'      => ['note' => $_POST['note'] ?? ''],
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = ['code' => $code, 'body' => json_decode($resp, true)];
    } else {
        $result = ['code' => 0, 'body' => ['error' => 'cURL not enabled; cannot post to receiver.']];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Form (simulated)</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 520px; margin: 60px auto; color:#111; }
        .card { border:1px solid #e5e7eb; border-radius:8px; padding:24px; }
        h1 { font-size:20px; color:#1e3a5f; }
        label { display:block; margin:14px 0 4px; font-size:13px; }
        input, textarea { width:100%; padding:8px; border:1px solid #ccc; border-radius:5px; box-sizing:border-box; }
        button { margin-top:16px; background:#1e3a5f; color:#fff; border:0; padding:10px 18px; border-radius:6px; cursor:pointer; }
        .muted { color:#666; font-size:12px; }
        .result { margin-top:18px; padding:12px; border-radius:6px; }
        .ok { background:#dcfce7; color:#166534; }
        .bad { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body>
<div class="card">
    <h1><?= htmlspecialchars($kindLabels[$kind] ?? 'Submission') ?></h1>
    <p class="muted">Simulated form. Submitting posts to the real FormReceiver webhook
       with the same token and shared secret Google Forms would send.</p>

    <?php if ($result): ?>
        <?php $ok = ($result['body']['ok'] ?? false); ?>
        <div class="result <?= $ok ? 'ok' : 'bad' ?>">
            HTTP <?= (int)$result['code'] ?> —
            <?php if ($ok): ?>
                <?= htmlspecialchars($result['body']['status'] ?? 'accepted') ?>
                <?php if (!empty($result['body']['new_state'])): ?>
                    → new state: <strong><?= htmlspecialchars($result['body']['new_state']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($result['body']['note'])): ?>
                    <br><?= htmlspecialchars($result['body']['note']) ?>
                <?php endif; ?>
            <?php else: ?>
                <?= htmlspecialchars($result['body']['error'] ?? 'Rejected') ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="kind" value="<?= htmlspecialchars($kind) ?>">
        <label>Your name</label>
        <input type="text" name="submitted_by" value="Simulated submission">
        <label>Note (optional)</label>
        <textarea name="note" rows="3"></textarea>
        <button type="submit">Submit</button>
    </form>
    <p class="muted" style="margin-top:14px;">Token: <?= htmlspecialchars($token) ?></p>
</div>
</body>
</html>
