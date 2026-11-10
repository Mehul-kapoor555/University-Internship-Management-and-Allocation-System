-- ============================================================
-- Smart University Internship Management System
-- Full MySQL Schema — v1.0
-- Author: Mehul Kapoor
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `internship_system`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `internship_system`;

-- ============================================================
-- 1. USERS & ROLES
-- ============================================================

CREATE TABLE `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`         VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('student','professor','company','coordinator') NOT NULL,
  `first_name`    VARCHAR(100) NOT NULL,
  `last_name`     VARCHAR(100) NOT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB;

-- ============================================================
-- 2. ROLE-SPECIFIC PROFILES
-- ============================================================

CREATE TABLE `students` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`               INT UNSIGNED NOT NULL UNIQUE,
  `matricola`             VARCHAR(20) NOT NULL UNIQUE,
  `program`               VARCHAR(150) NOT NULL,
  `enrollment_year`       YEAR NOT NULL,
  `total_cfu`             INT UNSIGNED NOT NULL DEFAULT 0,
  `completed_exams`       TEXT,                     -- comma-separated or JSON list
  `competency_description`TEXT,                     -- free-text for NLP embedding
  `gpa`                   DECIMAL(4,2) DEFAULT NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `professors` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`               INT UNSIGNED NOT NULL UNIQUE,
  `department`            VARCHAR(150) NOT NULL,
  `expertise_description` TEXT,                     -- free-text for NLP embedding
  `supervision_capacity`  INT UNSIGNED NOT NULL DEFAULT 3,
  `current_load`          INT UNSIGNED NOT NULL DEFAULT 0,
  `is_available`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `companies` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`               INT UNSIGNED NOT NULL UNIQUE,
  `company_name`          VARCHAR(200) NOT NULL,
  `industry`              VARCHAR(150) DEFAULT NULL,
  `website`               VARCHAR(255) DEFAULT NULL,
  `address`               TEXT,
  `contact_phone`         VARCHAR(30) DEFAULT NULL,
  `description`           TEXT,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `coordinators` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`               INT UNSIGNED NOT NULL UNIQUE,
  `department`            VARCHAR(150) NOT NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 3. SKILLS & COMPETENCY CATALOG
-- ============================================================

CREATE TABLE `skills` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL UNIQUE,
  `category`    VARCHAR(80) DEFAULT NULL,        -- e.g. "Programming", "Data Science", "Soft Skills"
  `description` TEXT,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `student_skills` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`  INT UNSIGNED NOT NULL,
  `skill_id`    INT UNSIGNED NOT NULL,
  `proficiency` ENUM('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'beginner',
  UNIQUE KEY `uq_student_skill` (`student_id`, `skill_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`)   REFERENCES `skills`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `professor_skills` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `professor_id`  INT UNSIGNED NOT NULL,
  `skill_id`      INT UNSIGNED NOT NULL,
  `proficiency`   ENUM('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'advanced',
  UNIQUE KEY `uq_professor_skill` (`professor_id`, `skill_id`),
  FOREIGN KEY (`professor_id`) REFERENCES `professors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`)     REFERENCES `skills`(`id`)     ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 4. INTERNSHIP OFFERS
-- ============================================================

CREATE TABLE `internship_offers` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `posted_by_user_id`   INT UNSIGNED NOT NULL,         -- company or professor
  `type`                ENUM('company','research') NOT NULL,
  `title`               VARCHAR(255) NOT NULL,
  `description`         TEXT NOT NULL,
  `domain`              VARCHAR(150) DEFAULT NULL,
  `capacity`            INT UNSIGNED NOT NULL DEFAULT 1,
  `filled`              INT UNSIGNED NOT NULL DEFAULT 0,
  `min_cfu`             INT UNSIGNED DEFAULT NULL,
  `required_program`    VARCHAR(150) DEFAULT NULL,
  `required_exams`      TEXT,                           -- JSON list
  `duration_months`     INT UNSIGNED DEFAULT 3,
  `location`            VARCHAR(200) DEFAULT NULL,
  `is_remote`           TINYINT(1) NOT NULL DEFAULT 0,
  `status`              ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
  `application_deadline`DATE DEFAULT NULL,
  `start_date`          DATE DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status`  (`status`),
  INDEX `idx_type`    (`type`),
  FOREIGN KEY (`posted_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `offer_skills` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `offer_id`    INT UNSIGNED NOT NULL,
  `skill_id`    INT UNSIGNED NOT NULL,
  `importance`  ENUM('required','preferred') NOT NULL DEFAULT 'required',
  UNIQUE KEY `uq_offer_skill` (`offer_id`, `skill_id`),
  FOREIGN KEY (`offer_id`) REFERENCES `internship_offers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`)            ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 5. APPLICATIONS
-- ============================================================

CREATE TABLE `applications` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`  INT UNSIGNED NOT NULL,
  `offer_id`    INT UNSIGNED NOT NULL,
  `status`      ENUM('pending','withdrawn','accepted','rejected') NOT NULL DEFAULT 'pending',
  `cover_letter`TEXT,
  `applied_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_student_offer` (`student_id`, `offer_id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 6. PREFERENCES & RANKINGS  (for Gale-Shapley — Week 4)
-- ============================================================

CREATE TABLE `student_preferences` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`  INT UNSIGNED NOT NULL,
  `offer_id`    INT UNSIGNED NOT NULL,
  `rank`        INT UNSIGNED NOT NULL,            -- 1 = first choice
  `cycle_id`    INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY `uq_student_pref` (`student_id`, `offer_id`),
  INDEX `idx_rank` (`student_id`, `rank`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `offer_rankings` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `offer_id`    INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `rank`        INT UNSIGNED NOT NULL,            -- 1 = most preferred
  `cycle_id`    INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY `uq_offer_rank` (`offer_id`, `student_id`),
  INDEX `idx_rank` (`offer_id`, `rank`),
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 7. ALGORITHM RESULT TABLES  (filled by Python engines)
-- ============================================================

-- Semantic similarity & competency gap (Week 3 — Increment 2)
CREATE TABLE `similarity_scores` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`      INT UNSIGNED NOT NULL,
  `offer_id`        INT UNSIGNED NOT NULL,
  `cosine_similarity` DECIMAL(6,5) NOT NULL,      -- 0.00000 to 1.00000
  `coverage`        DECIMAL(5,4) NOT NULL,         -- |matched| / |required|
  `computed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sim` (`student_id`, `offer_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `competency_gaps` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`  INT UNSIGNED NOT NULL,
  `offer_id`    INT UNSIGNED NOT NULL,
  `skill_id`    INT UNSIGNED NOT NULL,
  `gap_type`    ENUM('missing','insufficient') NOT NULL,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`)   REFERENCES `skills`(`id`)            ON DELETE CASCADE
) ENGINE=InnoDB;

-- Eligibility & recommendation scores (Week 3 — Increment 2)
CREATE TABLE `eligibility` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`  INT UNSIGNED NOT NULL,
  `offer_id`    INT UNSIGNED NOT NULL,
  `is_eligible` TINYINT(1) NOT NULL,
  `reason`      VARCHAR(255) DEFAULT NULL,         -- why ineligible, if applicable
  `checked_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_elig` (`student_id`, `offer_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `recommendation_scores` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`      INT UNSIGNED NOT NULL,
  `offer_id`        INT UNSIGNED NOT NULL,
  `score`           DECIMAL(5,4) NOT NULL,         -- 0.0000 to 1.0000
  `explanation`     TEXT,                          -- plain-language why
  `rank_position`   INT UNSIGNED DEFAULT NULL,
  `computed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rec` (`student_id`, `offer_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Allocations (Week 4 — Increment 3, Gale-Shapley output)
CREATE TABLE `academic_cycles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,             -- e.g. "2025-2026 Winter"
  `start_date`  DATE NOT NULL,
  `end_date`    DATE NOT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `matching_rounds` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cycle_id`    INT UNSIGNED NOT NULL,
  `status`      ENUM('pending','running','stable','unstable','published') NOT NULL DEFAULT 'pending',
  `run_at`      DATETIME DEFAULT NULL,
  `blocking_pairs` INT UNSIGNED DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`cycle_id`) REFERENCES `academic_cycles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `allocations` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `round_id`    INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `offer_id`    INT UNSIGNED NOT NULL,
  `status`      ENUM('tentative','confirmed','rejected') NOT NULL DEFAULT 'tentative',
  `allocated_at`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_alloc_student` (`round_id`, `student_id`),
  FOREIGN KEY (`round_id`)   REFERENCES `matching_rounds`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`offer_id`)   REFERENCES `internship_offers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Supervisor assignments (Week 4 — Increment 3, optimization output)
CREATE TABLE `supervisor_assignments` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `allocation_id`   INT UNSIGNED NOT NULL,
  `professor_id`    INT UNSIGNED NOT NULL,
  `cost_score`      DECIMAL(8,4) DEFAULT NULL,
  `assigned_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sup_alloc` (`allocation_id`),
  FOREIGN KEY (`allocation_id`) REFERENCES `allocations`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`professor_id`)  REFERENCES `professors`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 8. WORKFLOW & LIFECYCLE  (Week 5 — Increment 4)
-- ============================================================

CREATE TABLE `workflow_states` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `allocation_id`   INT UNSIGNED NOT NULL UNIQUE,
  `current_state`   ENUM(
    'allocated','doc_pending','prof_approval','coord_approval',
    'active','midterm','final_docs','closed',
    'revising','rejected'
  ) NOT NULL DEFAULT 'allocated',
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`allocation_id`) REFERENCES `allocations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `workflow_transitions` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `workflow_id`     INT UNSIGNED NOT NULL,
  `from_state`      VARCHAR(30) NOT NULL,
  `to_state`        VARCHAR(30) NOT NULL,
  `triggered_by`    INT UNSIGNED NOT NULL,         -- user id
  `comment`         TEXT,
  `transitioned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workflow_id`)  REFERENCES `workflow_states`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`triggered_by`) REFERENCES `users`(`id`)           ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 9. DOCUMENTS, NOTIFICATIONS, AUDIT
-- ============================================================

CREATE TABLE `documents` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `allocation_id`   INT UNSIGNED DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED NOT NULL,
  `doc_type`        ENUM('agreement','evaluation','report','certificate','other') NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_path`       VARCHAR(500) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT NULL,
  `mime_type`       VARCHAR(100) DEFAULT NULL,
  `uploaded_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`allocation_id`) REFERENCES `allocations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`uploaded_by`)   REFERENCES `users`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `notifications` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `message`     TEXT NOT NULL,
  `type`        ENUM('info','success','warning','action') NOT NULL DEFAULT 'info',
  `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
  `link`        VARCHAR(500) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_read` (`user_id`, `is_read`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `audit_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(60) NOT NULL,
  `entity_id`   INT UNSIGNED DEFAULT NULL,
  `details`     TEXT,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_entity` (`entity_type`, `entity_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 10. SEED DATA
-- ============================================================

-- Skill catalog
INSERT INTO `skills` (`name`, `category`) VALUES
  ('Python', 'Programming'),
  ('Java', 'Programming'),
  ('C', 'Programming'),
  ('C++', 'Programming'),
  ('PHP', 'Programming'),
  ('JavaScript', 'Programming'),
  ('HTML/CSS', 'Web'),
  ('SQL', 'Database'),
  ('MySQL', 'Database'),
  ('PostgreSQL', 'Database'),
  ('Machine Learning', 'Data Science'),
  ('Deep Learning', 'Data Science'),
  ('Data Analysis', 'Data Science'),
  ('Natural Language Processing', 'Data Science'),
  ('Computer Vision', 'Data Science'),
  ('Cybersecurity', 'Security'),
  ('Network Security', 'Security'),
  ('Penetration Testing', 'Security'),
  ('DevOps', 'Infrastructure'),
  ('Docker', 'Infrastructure'),
  ('Git', 'Tools'),
  ('Linux', 'Systems'),
  ('REST APIs', 'Web'),
  ('React', 'Web'),
  ('Django', 'Web'),
  ('Software Engineering', 'Methodology'),
  ('Agile/Scrum', 'Methodology'),
  ('Technical Writing', 'Soft Skills'),
  ('Teamwork', 'Soft Skills'),
  ('Problem Solving', 'Soft Skills');

-- Default coordinator account (password: coordinator123 — change in production)
INSERT INTO `users` (`email`, `password_hash`, `role`, `first_name`, `last_name`) VALUES
  ('coordinator@unime.it', '$2y$12$LJ3m4ys3Gz8y1k5K0hYiquGxMQPHjUwROSsmGHuGi3OiG.lHeSMWG', 'coordinator', 'System', 'Coordinator');

INSERT INTO `coordinators` (`user_id`, `department`) VALUES
  (1, 'Computer Science');

-- A default academic cycle
INSERT INTO `academic_cycles` (`name`, `start_date`, `end_date`, `is_active`) VALUES
  ('2025-2026', '2025-10-01', '2026-09-30', 1);

SET FOREIGN_KEY_CHECKS = 1;
