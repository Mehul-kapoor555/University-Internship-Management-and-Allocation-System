<?php
/**
 * Mail / integration configuration.
 *
 * DEMO MODE (default): emails are NOT sent over the network. They are recorded
 * in the sent_emails table with status='demo' so you can show the full flow
 * during the oral exam without a Brevo account or an internet connection.
 *
 * LIVE MODE: set MAIL_DEMO_MODE to false and fill in a real Brevo API key.
 * The Mailer will then POST to Brevo's transactional email endpoint and record
 * the result (status='sent' or 'failed').
 */

// Master switch. Leave true for demos.
define('MAIL_DEMO_MODE', true);

// Brevo (formerly Sendinblue) transactional email API.
define('BREVO_API_KEY', '');                       // paste your key for live mode
define('BREVO_ENDPOINT', 'https://api.brevo.com/v3/smtp/email');

// The "from" identity shown on outgoing mail.
define('MAIL_FROM_EMAIL', 'no-reply@internship.unime.it');
define('MAIL_FROM_NAME',  'UniME Internship Office');
