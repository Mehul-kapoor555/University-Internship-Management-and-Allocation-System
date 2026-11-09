-- ============================================================
-- Migration 008 — Category-2 integration support
--   Mailer (Brevo) audit trail.  Document generation (Dompdf) writes files
--   to storage/documents/ and is tracked per-placement in a later migration.
-- Apply AFTER 007_cycle_fsm.sql.
-- ============================================================

-- Every email the system produces is recorded here, whether it was really
-- sent through Brevo (status='sent'/'failed') or captured in demo mode
-- (status='demo'). This gives us an inspectable audit trail and doubles as
-- the evidence that the notification NFR is enforced and testable.
CREATE TABLE IF NOT EXISTS `sent_emails` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `to_email`          VARCHAR(255) NOT NULL,
  `to_name`           VARCHAR(200) DEFAULT NULL,
  `subject`           VARCHAR(255) NOT NULL,
  `body_html`         MEDIUMTEXT NOT NULL,
  `category`          VARCHAR(60)  DEFAULT NULL,   -- e.g. 'match_published', 'document_ready'
  `status`            ENUM('demo','sent','failed') NOT NULL DEFAULT 'demo',
  `provider_message`  VARCHAR(500) DEFAULT NULL,   -- Brevo message id or error text
  `related_user_id`   INT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`           DATETIME DEFAULT NULL,
  INDEX `idx_status`   (`status`),
  INDEX `idx_category` (`category`),
  INDEX `idx_created`  (`created_at`)
) ENGINE=InnoDB;
