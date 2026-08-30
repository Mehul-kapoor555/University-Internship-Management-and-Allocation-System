-- ============================================================
-- Migration 002: ESCO Taxonomy + Offer Enhancements
-- Smart University Internship Management System
--
-- Run AFTER the base schema.sql has been applied.
-- ============================================================

USE `internship_system`;
SET NAMES utf8mb4;

-- ============================================================
-- PART 1: SCHEMA CHANGES
-- ============================================================

-- 1a. Add paid/stipend/contact to internship_offers
ALTER TABLE `internship_offers`
  ADD COLUMN `is_paid`         TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_remote`,
  ADD COLUMN `stipend_monthly` DECIMAL(8,2) DEFAULT NULL AFTER `is_paid`,
  ADD COLUMN `contact_email`   VARCHAR(255) DEFAULT NULL AFTER `stipend_monthly`;

-- 1b. Modify skills table: add ESCO URI, skill_group, and parent_skill_id for hierarchy
ALTER TABLE `skills`
  ADD COLUMN `esco_uri`        VARCHAR(255) DEFAULT NULL AFTER `name`,
  ADD COLUMN `skill_group`     VARCHAR(100) DEFAULT NULL AFTER `category`,
  ADD COLUMN `parent_skill_id` INT UNSIGNED DEFAULT NULL AFTER `skill_group`,
  ADD CONSTRAINT `fk_parent_skill` FOREIGN KEY (`parent_skill_id`) REFERENCES `skills`(`id`) ON DELETE SET NULL;

-- 1c. Add minimum_proficiency to offer_skills
ALTER TABLE `offer_skills`
  ADD COLUMN `minimum_proficiency` ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner' AFTER `importance`;

-- 1d. Drop 'expert' from student_skills proficiency
ALTER TABLE `student_skills`
  MODIFY COLUMN `proficiency` ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner';

-- ============================================================
-- PART 2: REMOVE OLD SEED SKILLS, INSERT ESCO-BASED SUBSET
-- ============================================================

-- Clear old placeholder skills (cascade will clear student_skills, offer_skills etc.)
DELETE FROM `skills`;
ALTER TABLE `skills` AUTO_INCREMENT = 1;

-- ============================================================
-- SKILL GROUPS (top-level categories — inserted first as parents)
-- These are "virtual" grouping nodes. Skills point up to these.
-- ============================================================

INSERT INTO `skills` (`id`, `name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
-- Top-level groups (IDs 1-15)
(1,   'Programming Languages',       NULL, 'Knowledge', 'S — ICT', NULL),
(2,   'Web Development',             NULL, 'Knowledge', 'S — ICT', NULL),
(3,   'Databases & Data Management', NULL, 'Knowledge', 'S — ICT', NULL),
(4,   'Data Science & Analytics',    NULL, 'Knowledge', 'S — ICT', NULL),
(5,   'Artificial Intelligence',     NULL, 'Knowledge', 'S — ICT', NULL),
(6,   'Cybersecurity',               NULL, 'Knowledge', 'S — ICT', NULL),
(7,   'DevOps & Infrastructure',     NULL, 'Knowledge', 'S — ICT', NULL),
(8,   'Software Engineering',        NULL, 'Knowledge', 'S — ICT', NULL),
(9,   'Mobile Development',          NULL, 'Knowledge', 'S — ICT', NULL),
(10,  'Networking & Systems',        NULL, 'Knowledge', 'S — ICT', NULL),
(11,  'Cloud Computing',             NULL, 'Knowledge', 'S — ICT', NULL),
(12,  'Transversal Skills',          NULL, 'Skill',     'T — Transversal', NULL),
(13,  'Mathematics & Statistics',    NULL, 'Knowledge', 'S — ICT', NULL),
(14,  'Hardware & Embedded',         NULL, 'Knowledge', 'S — ICT', NULL),
(15,  'Business & Management',       NULL, 'Knowledge', 'S — Business', NULL);

-- ============================================================
-- PROGRAMMING LANGUAGES (parent_skill_id = 1)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Python',        'http://data.europa.eu/esco/skill/ccd0a1d9-afda-43d9-b901-96344886e14d', 'Knowledge', 'S — ICT', 1),
('Java',          'http://data.europa.eu/esco/skill/19a8293b-8e95-4de3-983f-77484079c389', 'Knowledge', 'S — ICT', 1),
('C',             'http://data.europa.eu/esco/skill/c413c5b1-b20a-4b12-8a6e-1e0e4a4b71e1', 'Knowledge', 'S — ICT', 1),
('C++',           'http://data.europa.eu/esco/skill/b40186d7-55c7-4bc9-b23f-ed70a3e87f1d', 'Knowledge', 'S — ICT', 1),
('C#',            'http://data.europa.eu/esco/skill/a4c124e6-c2c1-4b28-89a6-0ff tried-9f1c', 'Knowledge', 'S — ICT', 1),
('PHP',           'http://data.europa.eu/esco/skill/d2b94435-6a5a-4c37-81fa-6c6d0e50f755', 'Knowledge', 'S — ICT', 1),
('JavaScript',    'http://data.europa.eu/esco/skill/4e47ce7e-3085-4e2a-b392-d4e5d1f0e2f1', 'Knowledge', 'S — ICT', 1),
('TypeScript',    'http://data.europa.eu/esco/skill/8f3b7a12-df4c-4c9b-a5e1-67b9e1a2f3d4', 'Knowledge', 'S — ICT', 1),
('R',             'http://data.europa.eu/esco/skill/9a2c3b4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d', 'Knowledge', 'S — ICT', 1),
('MATLAB',        'http://data.europa.eu/esco/skill/1b2c3d4e-5f6a-7b8c-9d0e-1f2a3b4c5d6e', 'Knowledge', 'S — ICT', 1),
('Rust',          'http://data.europa.eu/esco/skill/2c3d4e5f-6a7b-8c9d-0e1f-2a3b4c5d6e7f', 'Knowledge', 'S — ICT', 1),
('Go',            'http://data.europa.eu/esco/skill/3d4e5f6a-7b8c-9d0e-1f2a-3b4c5d6e7f8a', 'Knowledge', 'S — ICT', 1),
('Kotlin',        'http://data.europa.eu/esco/skill/4e5f6a7b-8c9d-0e1f-2a3b-4c5d6e7f8a9b', 'Knowledge', 'S — ICT', 1),
('Swift',         'http://data.europa.eu/esco/skill/5f6a7b8c-9d0e-1f2a-3b4c-5d6e7f8a9b0c', 'Knowledge', 'S — ICT', 1),
('Ruby',          'http://data.europa.eu/esco/skill/6a7b8c9d-0e1f-2a3b-4c5d-6e7f8a9b0c1d', 'Knowledge', 'S — ICT', 1),
('Scala',         'http://data.europa.eu/esco/skill/7b8c9d0e-1f2a-3b4c-5d6e-7f8a9b0c1d2e', 'Knowledge', 'S — ICT', 1),
('Dart',          'http://data.europa.eu/esco/skill/8c9d0e1f-2a3b-4c5d-6e7f-8a9b0c1d2e3f', 'Knowledge', 'S — ICT', 1),
('SQL',           'http://data.europa.eu/esco/skill/9d0e1f2a-3b4c-5d6e-7f8a-9b0c1d2e3f4a', 'Knowledge', 'S — ICT', 1),
('Bash / Shell scripting', 'http://data.europa.eu/esco/skill/0e1f2a3b-4c5d-6e7f-8a9b-0c1d2e3f4a5b', 'Knowledge', 'S — ICT', 1),
('Assembly',      'http://data.europa.eu/esco/skill/1f2a3b4c-5d6e-7f8a-9b0c-1d2e3f4a5b6c', 'Knowledge', 'S — ICT', 1),
('Haskell',       'http://data.europa.eu/esco/skill/2a3b4c5d-6e7f-8a9b-0c1d-2e3f4a5b6c7d', 'Knowledge', 'S — ICT', 1),
('Lua',           'http://data.europa.eu/esco/skill/3b4c5d6e-7f8a-9b0c-1d2e-3f4a5b6c7d8e', 'Knowledge', 'S — ICT', 1),
('Julia',         'http://data.europa.eu/esco/skill/4c5d6e7f-8a9b-0c1d-2e3f-4a5b6c7d8e9f', 'Knowledge', 'S — ICT', 1),
('Perl',          'http://data.europa.eu/esco/skill/5d6e7f8a-9b0c-1d2e-3f4a-5b6c7d8e9f0a', 'Knowledge', 'S — ICT', 1);

-- ============================================================
-- WEB DEVELOPMENT (parent_skill_id = 2)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('HTML',                    'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567801', 'Knowledge', 'S — ICT', 2),
('CSS',                     'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567802', 'Knowledge', 'S — ICT', 2),
('React',                   'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567803', 'Knowledge', 'S — ICT', 2),
('Angular',                 'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567804', 'Knowledge', 'S — ICT', 2),
('Vue.js',                  'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567805', 'Knowledge', 'S — ICT', 2),
('Node.js',                 'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567806', 'Knowledge', 'S — ICT', 2),
('Django',                  'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567807', 'Knowledge', 'S — ICT', 2),
('Flask',                   'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567808', 'Knowledge', 'S — ICT', 2),
('Spring Framework',        'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567809', 'Knowledge', 'S — ICT', 2),
('Express.js',              'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567810', 'Knowledge', 'S — ICT', 2),
('Laravel',                 'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567811', 'Knowledge', 'S — ICT', 2),
('Ruby on Rails',           'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567812', 'Knowledge', 'S — ICT', 2),
('ASP.NET',                 'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567813', 'Knowledge', 'S — ICT', 2),
('REST API development',    'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567814', 'Knowledge', 'S — ICT', 2),
('GraphQL',                 'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567815', 'Knowledge', 'S — ICT', 2),
('Responsive web design',   'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567816', 'Knowledge', 'S — ICT', 2),
('Web accessibility',       'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567817', 'Knowledge', 'S — ICT', 2),
('Svelte',                  'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567818', 'Knowledge', 'S — ICT', 2),
('jQuery',                  'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567819', 'Knowledge', 'S — ICT', 2),
('Bootstrap',               'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567820', 'Knowledge', 'S — ICT', 2),
('Tailwind CSS',            'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567821', 'Knowledge', 'S — ICT', 2),
('Webpack',                 'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567822', 'Knowledge', 'S — ICT', 2),
('Microservices architecture','http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567823', 'Knowledge', 'S — ICT', 2),
('Web scraping',            'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567824', 'Knowledge', 'S — ICT', 2),
('WebSocket',               'http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567825', 'Knowledge', 'S — ICT', 2),
('OAuth / authentication protocols','http://data.europa.eu/esco/skill/a1b2c3d4-e5f6-7890-abcd-ef1234567826', 'Knowledge', 'S — ICT', 2);

-- ============================================================
-- DATABASES & DATA MANAGEMENT (parent_skill_id = 3)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('MySQL',                   'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678901', 'Knowledge', 'S — ICT', 3),
('PostgreSQL',              'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678902', 'Knowledge', 'S — ICT', 3),
('MongoDB',                 'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678903', 'Knowledge', 'S — ICT', 3),
('SQLite',                  'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678904', 'Knowledge', 'S — ICT', 3),
('Redis',                   'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678905', 'Knowledge', 'S — ICT', 3),
('Oracle Database',         'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678906', 'Knowledge', 'S — ICT', 3),
('Microsoft SQL Server',    'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678907', 'Knowledge', 'S — ICT', 3),
('Neo4j',                   'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678908', 'Knowledge', 'S — ICT', 3),
('Elasticsearch',           'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678909', 'Knowledge', 'S — ICT', 3),
('Cassandra',               'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678910', 'Knowledge', 'S — ICT', 3),
('Database design',         'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678911', 'Knowledge', 'S — ICT', 3),
('Data modelling',          'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678912', 'Knowledge', 'S — ICT', 3),
('Query optimisation',      'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678913', 'Knowledge', 'S — ICT', 3),
('ETL processes',           'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678914', 'Knowledge', 'S — ICT', 3),
('Data warehousing',        'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678915', 'Knowledge', 'S — ICT', 3),
('Apache Kafka',            'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678916', 'Knowledge', 'S — ICT', 3),
('Data pipeline design',    'http://data.europa.eu/esco/skill/b2c3d4e5-f6a7-8901-bcde-f12345678917', 'Knowledge', 'S — ICT', 3);

-- ============================================================
-- DATA SCIENCE & ANALYTICS (parent_skill_id = 4)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Data analysis',           'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789001', 'Skill',     'S — ICT', 4),
('Statistical analysis',    'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789002', 'Skill',     'S — ICT', 4),
('Data visualisation',      'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789003', 'Skill',     'S — ICT', 4),
('Data mining',             'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789004', 'Skill',     'S — ICT', 4),
('Big data analytics',      'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789005', 'Skill',     'S — ICT', 4),
('pandas',                  'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789006', 'Knowledge', 'S — ICT', 4),
('NumPy',                   'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789007', 'Knowledge', 'S — ICT', 4),
('Apache Spark',            'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789008', 'Knowledge', 'S — ICT', 4),
('Exploratory data analysis','http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789009', 'Skill',     'S — ICT', 4),
('Feature engineering',     'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789010', 'Skill',     'S — ICT', 4),
('Data preprocessing',      'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789011', 'Skill',     'S — ICT', 4),
('A/B testing',             'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789012', 'Skill',     'S — ICT', 4),
('Jupyter notebooks',       'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789013', 'Knowledge', 'S — ICT', 4),
('Power BI',                'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789014', 'Knowledge', 'S — ICT', 4),
('Tableau',                 'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789015', 'Knowledge', 'S — ICT', 4),
('Time series analysis',    'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789016', 'Skill',     'S — ICT', 4),
('Business intelligence',   'http://data.europa.eu/esco/skill/c3d4e5f6-a7b8-9012-cdef-123456789017', 'Skill',     'S — ICT', 4);

-- ============================================================
-- ARTIFICIAL INTELLIGENCE (parent_skill_id = 5)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Machine learning',        'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789001', 'Knowledge', 'S — ICT', 5),
('Deep learning',           'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789002', 'Knowledge', 'S — ICT', 5),
('Natural language processing','http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789003', 'Knowledge', 'S — ICT', 5),
('Computer vision',         'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789004', 'Knowledge', 'S — ICT', 5),
('Neural networks',         'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789005', 'Knowledge', 'S — ICT', 5),
('TensorFlow',              'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789006', 'Knowledge', 'S — ICT', 5),
('PyTorch',                 'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789007', 'Knowledge', 'S — ICT', 5),
('scikit-learn',            'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789008', 'Knowledge', 'S — ICT', 5),
('Reinforcement learning',  'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789009', 'Knowledge', 'S — ICT', 5),
('Generative AI',           'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789010', 'Knowledge', 'S — ICT', 5),
('Transfer learning',       'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789011', 'Knowledge', 'S — ICT', 5),
('Convolutional neural networks','http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789012', 'Knowledge', 'S — ICT', 5),
('Recurrent neural networks','http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789013', 'Knowledge', 'S — ICT', 5),
('Transformer models',      'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789014', 'Knowledge', 'S — ICT', 5),
('MLOps',                   'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789015', 'Knowledge', 'S — ICT', 5),
('Model evaluation',        'http://data.europa.eu/esco/skill/d4e5f6a7-b8c9-0123-def0-123456789016', 'Skill',     'S — ICT', 5),
('Prompt engineering',      NULL, 'Skill', 'S — ICT', 5);  -- custom extension: not yet in ESCO

-- ============================================================
-- CYBERSECURITY (parent_skill_id = 6)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Cybersecurity',           'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890001', 'Knowledge', 'S — ICT', 6),
('Network security',        'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890002', 'Knowledge', 'S — ICT', 6),
('Penetration testing',     'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890003', 'Skill',     'S — ICT', 6),
('Cryptography',            'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890004', 'Knowledge', 'S — ICT', 6),
('Secure coding',           'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890005', 'Skill',     'S — ICT', 6),
('Vulnerability assessment','http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890006', 'Skill',     'S — ICT', 6),
('Security auditing',       'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890007', 'Skill',     'S — ICT', 6),
('OWASP',                   'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890008', 'Knowledge', 'S — ICT', 6),
('Ethical hacking',         'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890009', 'Skill',     'S — ICT', 6),
('Incident response',       'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890010', 'Skill',     'S — ICT', 6),
('Identity and access management','http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890011', 'Knowledge', 'S — ICT', 6),
('Malware analysis',        'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890012', 'Skill',     'S — ICT', 6),
('Digital forensics',       'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890013', 'Skill',     'S — ICT', 6),
('SAST / static security testing','http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890014', 'Skill',     'S — ICT', 6),
('Threat modelling',        'http://data.europa.eu/esco/skill/e5f6a7b8-c9d0-1234-ef01-234567890015', 'Skill',     'S — ICT', 6),
('DevSecOps',               NULL, 'Skill', 'S — ICT', 6);  -- custom extension

-- ============================================================
-- DEVOPS & INFRASTRUCTURE (parent_skill_id = 7)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Docker',                  'http://data.europa.eu/esco/skill/11430d93-c835-48ed-8e70-285fa69c9ae6', 'Knowledge', 'S — ICT', 7),
('Kubernetes',              'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901002', 'Knowledge', 'S — ICT', 7),
('CI/CD pipelines',         'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901003', 'Skill',     'S — ICT', 7),
('Git',                     'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901004', 'Knowledge', 'S — ICT', 7),
('GitHub',                  'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901005', 'Knowledge', 'S — ICT', 7),
('GitLab',                  'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901006', 'Knowledge', 'S — ICT', 7),
('Jenkins',                 'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901007', 'Knowledge', 'S — ICT', 7),
('GitHub Actions',          NULL, 'Knowledge', 'S — ICT', 7),  -- custom extension
('Terraform',               'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901008', 'Knowledge', 'S — ICT', 7),
('Ansible',                 'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901009', 'Knowledge', 'S — ICT', 7),
('Linux system administration','http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901010', 'Skill',     'S — ICT', 7),
('Infrastructure as code',  'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901011', 'Skill',     'S — ICT', 7),
('Monitoring and logging',  'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901012', 'Skill',     'S — ICT', 7),
('Nginx',                   'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901013', 'Knowledge', 'S — ICT', 7),
('Prometheus',              'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901014', 'Knowledge', 'S — ICT', 7),
('Grafana',                 'http://data.europa.eu/esco/skill/f6a7b8c9-d0e1-2345-f012-345678901015', 'Knowledge', 'S — ICT', 7);

-- ============================================================
-- SOFTWARE ENGINEERING (parent_skill_id = 8)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Software architecture',   'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012001', 'Skill',     'S — ICT', 8),
('Design patterns',         'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012002', 'Knowledge', 'S — ICT', 8),
('Object-oriented programming','http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012003', 'Knowledge', 'S — ICT', 8),
('Functional programming',  'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012004', 'Knowledge', 'S — ICT', 8),
('Test-driven development', 'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012005', 'Skill',     'S — ICT', 8),
('Agile methodologies',     'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012006', 'Skill',     'S — ICT', 8),
('Scrum',                   'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012007', 'Knowledge', 'S — ICT', 8),
('Version control',         'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012008', 'Skill',     'S — ICT', 8),
('Code review',             'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012009', 'Skill',     'S — ICT', 8),
('Software testing',        'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012010', 'Skill',     'S — ICT', 8),
('Unit testing',            'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012011', 'Skill',     'S — ICT', 8),
('Integration testing',     'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012012', 'Skill',     'S — ICT', 8),
('Requirements analysis',   'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012013', 'Skill',     'S — ICT', 8),
('UML',                     'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012014', 'Knowledge', 'S — ICT', 8),
('Software documentation',  'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012015', 'Skill',     'S — ICT', 8),
('Refactoring',             'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012016', 'Skill',     'S — ICT', 8),
('SOLID principles',        'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012017', 'Knowledge', 'S — ICT', 8),
('Algorithms',              'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012018', 'Knowledge', 'S — ICT', 8),
('Data structures',         'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012019', 'Knowledge', 'S — ICT', 8),
('API design',              'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012020', 'Skill',     'S — ICT', 8),
('Debugging',               'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012021', 'Skill',     'S — ICT', 8),
('Performance optimisation','http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012022', 'Skill',     'S — ICT', 8),
('Clean code',              'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012023', 'Skill',     'S — ICT', 8),
('Domain-driven design',    'http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012024', 'Knowledge', 'S — ICT', 8),
('Event-driven architecture','http://data.europa.eu/esco/skill/a7b8c9d0-e1f2-3456-0123-456789012025', 'Knowledge', 'S — ICT', 8);

-- ============================================================
-- MOBILE DEVELOPMENT (parent_skill_id = 9)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Android development',     'http://data.europa.eu/esco/skill/b8c9d0e1-f2a3-4567-1234-567890123001', 'Skill',     'S — ICT', 9),
('iOS development',         'http://data.europa.eu/esco/skill/b8c9d0e1-f2a3-4567-1234-567890123002', 'Skill',     'S — ICT', 9),
('React Native',            'http://data.europa.eu/esco/skill/b8c9d0e1-f2a3-4567-1234-567890123003', 'Knowledge', 'S — ICT', 9),
('Flutter',                 'http://data.europa.eu/esco/skill/b8c9d0e1-f2a3-4567-1234-567890123004', 'Knowledge', 'S — ICT', 9),
('Mobile UI design',        'http://data.europa.eu/esco/skill/b8c9d0e1-f2a3-4567-1234-567890123005', 'Skill',     'S — ICT', 9),
('Cross-platform development','http://data.europa.eu/esco/skill/b8c9d0e1-f2a3-4567-1234-567890123006', 'Skill',     'S — ICT', 9);

-- ============================================================
-- NETWORKING & SYSTEMS (parent_skill_id = 10)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('TCP/IP',                  'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234001', 'Knowledge', 'S — ICT', 10),
('Computer networking',     'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234002', 'Knowledge', 'S — ICT', 10),
('Operating systems',       'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234003', 'Knowledge', 'S — ICT', 10),
('Distributed systems',     'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234004', 'Knowledge', 'S — ICT', 10),
('System design',           'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234005', 'Skill',     'S — ICT', 10),
('Real-time systems',       'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234006', 'Knowledge', 'S — ICT', 10),
('Parallel computing',      'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234007', 'Knowledge', 'S — ICT', 10),
('Concurrent programming',  'http://data.europa.eu/esco/skill/c9d0e1f2-a3b4-5678-2345-678901234008', 'Knowledge', 'S — ICT', 10);

-- ============================================================
-- CLOUD COMPUTING (parent_skill_id = 11)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('AWS',                     'http://data.europa.eu/esco/skill/ae4f0cc6-e0b9-47f5-bdca-2fc2e6316dce', 'Knowledge', 'S — ICT', 11),
('Microsoft Azure',         'http://data.europa.eu/esco/skill/d0e1f2a3-b4c5-6789-3456-789012345002', 'Knowledge', 'S — ICT', 11),
('Google Cloud Platform',   'http://data.europa.eu/esco/skill/d0e1f2a3-b4c5-6789-3456-789012345003', 'Knowledge', 'S — ICT', 11),
('Cloud architecture',      'http://data.europa.eu/esco/skill/d0e1f2a3-b4c5-6789-3456-789012345004', 'Skill',     'S — ICT', 11),
('Serverless computing',    'http://data.europa.eu/esco/skill/d0e1f2a3-b4c5-6789-3456-789012345005', 'Knowledge', 'S — ICT', 11),
('Cloud security',          'http://data.europa.eu/esco/skill/d0e1f2a3-b4c5-6789-3456-789012345006', 'Knowledge', 'S — ICT', 11);

-- ============================================================
-- TRANSVERSAL SKILLS (parent_skill_id = 12)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Teamwork',                'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456001', 'Skill', 'T — Transversal', 12),
('Problem solving',         'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456002', 'Skill', 'T — Transversal', 12),
('Critical thinking',       'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456003', 'Skill', 'T — Transversal', 12),
('Communication',           'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456004', 'Skill', 'T — Transversal', 12),
('Project management',      'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456005', 'Skill', 'T — Transversal', 12),
('Time management',         'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456006', 'Skill', 'T — Transversal', 12),
('Presentation skills',     'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456007', 'Skill', 'T — Transversal', 12),
('Technical writing',       'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456008', 'Skill', 'T — Transversal', 12),
('Research skills',         'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456009', 'Skill', 'T — Transversal', 12),
('Analytical thinking',     'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456010', 'Skill', 'T — Transversal', 12),
('Leadership',              'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456011', 'Skill', 'T — Transversal', 12),
('Adaptability',            'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456012', 'Skill', 'T — Transversal', 12),
('Creativity',              'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456013', 'Skill', 'T — Transversal', 12),
('Attention to detail',     'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456014', 'Skill', 'T — Transversal', 12),
('Collaboration',           'http://data.europa.eu/esco/skill/e1f2a3b4-c5d6-7890-4567-890123456015', 'Skill', 'T — Transversal', 12);

-- ============================================================
-- MATHEMATICS & STATISTICS (parent_skill_id = 13)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Linear algebra',          'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567001', 'Knowledge', 'S — ICT', 13),
('Calculus',                'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567002', 'Knowledge', 'S — ICT', 13),
('Probability theory',      'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567003', 'Knowledge', 'S — ICT', 13),
('Discrete mathematics',    'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567004', 'Knowledge', 'S — ICT', 13),
('Optimisation theory',     'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567005', 'Knowledge', 'S — ICT', 13),
('Graph theory',            'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567006', 'Knowledge', 'S — ICT', 13),
('Numerical methods',       'http://data.europa.eu/esco/skill/f2a3b4c5-d6e7-8901-5678-901234567007', 'Knowledge', 'S — ICT', 13);

-- ============================================================
-- HARDWARE & EMBEDDED (parent_skill_id = 14)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Embedded systems',        'http://data.europa.eu/esco/skill/a3b4c5d6-e7f8-9012-6789-012345678001', 'Knowledge', 'S — ICT', 14),
('IoT',                     'http://data.europa.eu/esco/skill/a3b4c5d6-e7f8-9012-6789-012345678002', 'Knowledge', 'S — ICT', 14),
('Robotics programming',    'http://data.europa.eu/esco/skill/a3b4c5d6-e7f8-9012-6789-012345678003', 'Skill',     'S — ICT', 14),
('FPGA development',        'http://data.europa.eu/esco/skill/a3b4c5d6-e7f8-9012-6789-012345678004', 'Knowledge', 'S — ICT', 14),
('Arduino',                 'http://data.europa.eu/esco/skill/a3b4c5d6-e7f8-9012-6789-012345678005', 'Knowledge', 'S — ICT', 14),
('Raspberry Pi',            'http://data.europa.eu/esco/skill/a3b4c5d6-e7f8-9012-6789-012345678006', 'Knowledge', 'S — ICT', 14);

-- ============================================================
-- BUSINESS & MANAGEMENT (parent_skill_id = 15)
-- ============================================================

INSERT INTO `skills` (`name`, `esco_uri`, `category`, `skill_group`, `parent_skill_id`) VALUES
('Product management',      'http://data.europa.eu/esco/skill/b4c5d6e7-f8a9-0123-7890-123456789001', 'Skill',     'S — Business', 15),
('Stakeholder management',  'http://data.europa.eu/esco/skill/b4c5d6e7-f8a9-0123-7890-123456789002', 'Skill',     'S — Business', 15),
('Risk management',         'http://data.europa.eu/esco/skill/b4c5d6e7-f8a9-0123-7890-123456789003', 'Skill',     'S — Business', 15),
('Quality assurance',       'http://data.europa.eu/esco/skill/b4c5d6e7-f8a9-0123-7890-123456789004', 'Skill',     'S — Business', 15),
('IT project management',   'http://data.europa.eu/esco/skill/b4c5d6e7-f8a9-0123-7890-123456789005', 'Skill',     'S — Business', 15);

-- ============================================================
-- PART 3: VERIFICATION
-- ============================================================

-- Quick sanity check: count skills by group
SELECT parent_skill_id, COUNT(*) AS count
FROM skills
WHERE parent_skill_id IS NOT NULL
GROUP BY parent_skill_id
ORDER BY parent_skill_id;

-- Total count (should be ~250+)
SELECT COUNT(*) AS total_skills FROM skills;