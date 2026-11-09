<?php
/**
 * Webhook configuration for the Google Forms -> FormReceiver integration.
 *
 * WEBHOOK_SECRET is the shared secret that must match in both the Apps Script
 * trigger and this application. Change it to a long random string in real use.
 *
 * FORM_BASE_URL is where students/parties are sent to fill a form. In real use
 * this is your Google Form's prefilled URL. For local testing / demo fallback
 * it points at simulate-form.php, which posts to form-receiver.php with the
 * same token + secret exactly as Google would.
 */
define('WEBHOOK_SECRET', 'change-me-to-a-long-random-secret-2026');

// Point this at the Google Form base URL in production. Leave as the local
// simulator for offline testing.
if (!defined('FORM_BASE_URL')) {
    define('FORM_BASE_URL', BASE_URL . '/simulate-form.php');
}
