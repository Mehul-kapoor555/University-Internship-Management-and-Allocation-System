-- ============================================================
-- Migration 009 — Document-Driven Internship Lifecycle Engine (Cat-3 #5)
--   Brings the lifecycle schema in line with the report's state model,
--   adds the webhook correlation token, the form-submission tracking used by
--   the FINAL_DOCS join, a generated-documents record, and a transition audit
--   trail that mirrors cycle_state_transitions.
-- Apply AFTER 008_cat2_integrations.sql.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Re-align workflow_states to the report's 10-state model.
--    The base schema shipped an approximate enum ('prof_approval',
--    'midterm', ... and no 'awaiting_final_approval'). Replace it with the
--    exact set the lifecycle definition uses, and add the correlation token
--    plus a spawned_at timestamp.
-- ------------------------------------------------------------
ALTER TABLE `workflow_states`
    MODIFY COLUMN `current_state` ENUM(
        'allocated',
        'doc_pending',
        'awaiting_prof_approval',
        'awaiting_coord_approval',
        'active',
        'final_docs',
        'awaiting_final_approval',
        'revising',
        'rejected',
        'closed'
    ) NOT NULL DEFAULT 'allocated';

-- Opaque token that Google Forms carries back so the webhook can correlate a
-- submission to its placement. Unique, unguessable, one per placement instance.
ALTER TABLE `workflow_states`
    ADD COLUMN `correlation_token` CHAR(32) DEFAULT NULL AFTER `allocation_id`,
    ADD COLUMN `spawned_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`,
    ADD UNIQUE KEY `uq_token` (`correlation_token`);

-- ------------------------------------------------------------
-- 2. Lifecycle transition audit (mirror of cycle_state_transitions).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lifecycle_transitions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `workflow_id`     INT UNSIGNED NOT NULL,
    `from_state`      VARCHAR(40) NOT NULL,
    `to_state`        VARCHAR(40) NOT NULL,
    `transition`      VARCHAR(60) NOT NULL,
    `triggered_by`    INT UNSIGNED DEFAULT NULL,   -- user id, or NULL for webhook
    `trigger_source`  ENUM('user','webhook','system') NOT NULL DEFAULT 'user',
    `comment`         TEXT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_workflow` (`workflow_id`),
    FOREIGN KEY (`workflow_id`) REFERENCES `workflow_states`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. Form submissions arriving through the webhook.
--    form_kind identifies which form it is. For the FINAL_DOCS join we need to
--    record the three independent submissions (final_report, company_evaluation,
--    professor_assessment) and detect when the set is complete.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `form_submissions` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `workflow_id`    INT UNSIGNED NOT NULL,
    `form_kind`      ENUM(
        'signed_agreement',
        'revision_resubmit',
        'final_report',
        'company_evaluation',
        'professor_assessment'
    ) NOT NULL,
    `submitted_by`   VARCHAR(200) DEFAULT NULL,   -- free-text identity from the form
    `payload`        TEXT DEFAULT NULL,           -- raw form answers (JSON)
    `received_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wf_kind` (`workflow_id`, `form_kind`),
    FOREIGN KEY (`workflow_id`) REFERENCES `workflow_states`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. Generated documents produced by the lifecycle (Dompdf output).
--    Distinct from the base `documents` table (which models uploads); this one
--    records engine-generated PDFs tied to a placement and the state that made
--    them.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `generated_documents` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `workflow_id`    INT UNSIGNED NOT NULL,
    `doc_type`       ENUM('agreement','revision_notice','rejection_notice','completion_certificate') NOT NULL,
    `file_path`      VARCHAR(500) NOT NULL,
    `format`         ENUM('pdf','html') NOT NULL DEFAULT 'pdf',
    `generated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wf` (`workflow_id`),
    FOREIGN KEY (`workflow_id`) REFERENCES `workflow_states`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
