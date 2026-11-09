<?php
/**
 * FormReceiver — webhook endpoint for Google Forms submissions (report §8.5.6).
 *
 * Google Forms + an Apps Script trigger POST here on every submission. This
 * endpoint turns an external submission into an internal lifecycle transition
 * in four steps:
 *
 *   1. Authenticate — the POST must carry the shared secret (NFR-S4).
 *   2. Correlate    — the opaque token identifies the placement.
 *   3. Route        — the current state decides which transition the form fires.
 *   4. Fire         — the lifecycle engine runs the transition and its actions.
 *
 * Accepts either a JSON body or form-encoded fields, so it works both from
 * Apps Script (JSON) and from the local "simulate submission" control.
 *
 * Responds with JSON and an appropriate HTTP status.
 */

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/webhook_config.php';
require __DIR__ . '/includes/LifecycleStateMachine.php';

header('Content-Type: application/json');

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// ---- Parse input (JSON body or form-encoded) -------------------------------
$raw = file_get_contents('php://input');
$data = [];
if ($raw !== '' && ($json = json_decode($raw, true)) !== null) {
    $data = $json;
} else {
    $data = $_POST;
}

$secret  = $data['secret']  ?? ($_GET['secret']  ?? '');
$token   = $data['token']   ?? ($_GET['token']   ?? '');
$formKind = $data['kind']   ?? ($_GET['kind']    ?? '');
$submittedBy = $data['submitted_by'] ?? null;
$payload = isset($data['answers']) ? json_encode($data['answers']) : null;

// ---- STEP 1: Authenticate --------------------------------------------------
if (!hash_equals(WEBHOOK_SECRET, (string)$secret)) {
    respond(401, ['ok' => false, 'error' => 'Invalid or missing secret.']);
}

// ---- STEP 2: Correlate -----------------------------------------------------
$validKinds = ['signed_agreement', 'revision_resubmit',
               'final_report', 'company_evaluation', 'professor_assessment'];
if (!in_array($formKind, $validKinds, true)) {
    respond(400, ['ok' => false, 'error' => "Unknown form kind: $formKind"]);
}
if ($token === '') {
    respond(400, ['ok' => false, 'error' => 'Missing correlation token.']);
}

$engine = new LifecycleStateMachine($db);
$wf = $engine->loadByToken($token);
if (!$wf) {
    respond(404, ['ok' => false, 'error' => 'No placement matches that token.']);
}

// ---- STEP 3 + 4: Route and fire (handled inside the engine) ---------------
$result = $engine->handleSubmission($wf, $formKind, $submittedBy, $payload);

respond(200, array_merge(['ok' => true], $result));
