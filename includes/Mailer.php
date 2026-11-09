<?php
require_once __DIR__ . '/mail_config.php';

/**
 * Mailer — Category-2 integration adapter around Brevo's transactional email API.
 *
 * The rest of the system never talks to Brevo directly; it calls Mailer::send()
 * with plain arguments. The adapter decides how to deliver:
 *
 *   - Demo mode (MAIL_DEMO_MODE = true): the message is recorded in sent_emails
 *     with status='demo' and nothing leaves the machine.
 *   - Live mode: the message is POSTed to Brevo via cURL and the outcome
 *     (message id or error) is recorded with status='sent' or 'failed'.
 *
 * Either way there is one row per email in sent_emails, so the email log page
 * shows a complete, inspectable history.
 */
class Mailer
{
    /**
     * Send (or, in demo mode, record) one email.
     *
     * @return array{ok:bool,status:string,message:?string,email_id:?int}
     */
    public static function send(
        PDO $db,
        string $toEmail,
        ?string $toName,
        string $subject,
        string $htmlBody,
        ?string $category = null,
        ?int $relatedUserId = null
    ): array {

        // --- DEMO MODE: record only ------------------------------------------
        if (MAIL_DEMO_MODE) {
            $id = self::record($db, $toEmail, $toName, $subject, $htmlBody,
                               $category, $relatedUserId, 'demo',
                               'Recorded in demo mode (not sent).', null);
            return ['ok' => true, 'status' => 'demo',
                    'message' => 'Recorded in demo mode.', 'email_id' => $id];
        }

        // --- LIVE MODE: POST to Brevo ----------------------------------------
        if (BREVO_API_KEY === '') {
            $id = self::record($db, $toEmail, $toName, $subject, $htmlBody,
                               $category, $relatedUserId, 'failed',
                               'No Brevo API key configured.', null);
            return ['ok' => false, 'status' => 'failed',
                    'message' => 'No Brevo API key configured.', 'email_id' => $id];
        }

        $payload = json_encode([
            'sender' => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
            'to'     => [['email' => $toEmail, 'name' => $toName ?: $toEmail]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
        ]);

        [$httpCode, $respBody, $curlError] = self::httpPost(BREVO_ENDPOINT, $payload);

        if ($curlError) {
            $id = self::record($db, $toEmail, $toName, $subject, $htmlBody,
                               $category, $relatedUserId, 'failed',
                               'cURL error: ' . $curlError, null);
            return ['ok' => false, 'status' => 'failed',
                    'message' => $curlError, 'email_id' => $id];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($respBody, true);
            $msgId = $decoded['messageId'] ?? ('HTTP ' . $httpCode);
            $id = self::record($db, $toEmail, $toName, $subject, $htmlBody,
                               $category, $relatedUserId, 'sent',
                               (string)$msgId, date('Y-m-d H:i:s'));
            return ['ok' => true, 'status' => 'sent',
                    'message' => (string)$msgId, 'email_id' => $id];
        }

        // Non-2xx response.
        $id = self::record($db, $toEmail, $toName, $subject, $htmlBody,
                           $category, $relatedUserId, 'failed',
                           'HTTP ' . $httpCode . ': ' . substr($respBody, 0, 300), null);
        return ['ok' => false, 'status' => 'failed',
                'message' => 'HTTP ' . $httpCode, 'email_id' => $id];
    }

    /**
     * Convenience: look up a user's email by id and send to them.
     * Silently no-ops if the user has no email row.
     */
    public static function sendToUser(
        PDO $db, int $userId, string $subject, string $htmlBody,
        ?string $category = null
    ): array {
        $stmt = $db->prepare(
            "SELECT email, CONCAT(first_name,' ',last_name) AS name FROM users WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            return ['ok' => false, 'status' => 'failed',
                    'message' => 'User not found', 'email_id' => null];
        }
        return self::send($db, $u['email'], $u['name'], $subject, $htmlBody,
                          $category, $userId);
    }

    /**
     * Minimal, safe HTML wrapper so messages look like real emails without a
     * template engine. Body text is passed through as trusted HTML by callers.
     */
    public static function wrap(string $heading, string $bodyHtml): string
    {
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES);
        return
            '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;'
          . 'border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
          . '<div style="background:#1e3a5f;color:#fff;padding:16px 20px;font-size:18px;">'
          . $safeHeading . '</div>'
          . '<div style="padding:20px;color:#111827;font-size:14px;line-height:1.6;">'
          . $bodyHtml
          . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">'
          . '<div style="color:#6b7280;font-size:12px;">'
          . 'This is an automated message from the University Internship Management System.'
          . '</div></div></div>';
    }

    // ---- internals ----------------------------------------------------------

    private static function record(
        PDO $db, string $toEmail, ?string $toName, string $subject, string $body,
        ?string $category, ?int $relatedUserId, string $status,
        ?string $providerMessage, ?string $sentAt
    ): ?int {
        try {
            $stmt = $db->prepare("
                INSERT INTO sent_emails
                  (to_email, to_name, subject, body_html, category, status,
                   provider_message, related_user_id, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$toEmail, $toName, $subject, $body, $category, $status,
                            $providerMessage, $relatedUserId, $sentAt]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            // Never let mail logging break the caller's transaction path.
            return null;
        }
    }

    private static function httpPost(string $url, string $jsonPayload): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'PHP cURL extension is not enabled.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . BREVO_API_KEY,
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch) ? curl_error($ch) : null;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body === false ? '' : $body, $err];
    }
}
