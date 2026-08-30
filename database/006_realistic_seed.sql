-- ============================================================================
-- 006_realistic_seed.sql
-- Real, diverse seed data for the Smart University Internship Management System.
-- Matches the exact schema: base + 002 (ESCO) + 003 (registration_status,
-- academic_records) + 004 (applicant_rankings) + 005 (preference source,
-- matching_trace) + 007 (cycle FSM).
--
-- Contents:
--   *  5 professors (5 different research areas)
--   *  8 companies (real Italian tech firms + one local startup)
--   * 18 students with rich competency descriptions and academic records
--   * 20 internship offers (company + research), detailed
--   * Skill assignments (students, offers)
--   * Applications, preferences, rankings — enough data to make the matching
--     engine produce interesting behaviour (bumps, cascades, rejections)
--
-- All seeded users use email suffix @seed.unime.it or @seed.company for
-- easy cleanup. Password for everyone is "password" (bcrypt hash below).
-- ============================================================================

USE `internship_system`;

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_SAFE_UPDATES = 0;

-- ---------- Idempotent cleanup ----------
TRUNCATE TABLE offer_rankings;
TRUNCATE TABLE student_preferences;
TRUNCATE TABLE applications;
TRUNCATE TABLE offer_skills;
TRUNCATE TABLE internship_offers;

TRUNCATE TABLE student_skills;
TRUNCATE TABLE academic_records;

TRUNCATE TABLE students;
TRUNCATE TABLE professors;
TRUNCATE TABLE companies;

TRUNCATE TABLE users;

SET FOREIGN_KEY_CHECKS = 1;


-- Grab the active cycle (created by base schema as id=1)
SET @cycle_id := (SELECT id FROM academic_cycles WHERE is_active = 1 LIMIT 1);
SET @PW := '$2y$10$v2AQjVsR4eGs18FE1iDN6..cRDaMgM0w6AVD1ghNSwaeqqkjNbemm'; -- password("123")

INSERT INTO users (email, password_hash, role, first_name, last_name) VALUES
('kapoormehul528@gmail.com', @PW, 'coordinator', 'Mehul', 'Kapoor');
-- ============================================================================
-- PROFESSORS (5)
-- ============================================================================

INSERT INTO users (email, password_hash, role, first_name, last_name) VALUES
('distefano@seed.unime.it', @PW, 'professor', 'Salvatore', 'Distefano'),
('longo@seed.unime.it',     @PW, 'professor', 'Antonella',  'Longo'),
('manco@seed.unime.it',     @PW, 'professor', 'Giuseppe',   'Manco'),
('marotta@seed.unime.it',   @PW, 'professor', 'Paolo',      'Marotta'),
('villari@seed.unime.it',   @PW, 'professor', 'Massimo',    'Villari');

INSERT INTO professors (user_id, department, expertise_description, supervision_capacity, current_load, is_available) VALUES
((SELECT id FROM users WHERE email='distefano@seed.unime.it'),
 'Dipartimento MIFT — Informatica',
 'Distributed systems, DevSecOps, edge and fog computing, cloud-native architectures. Research focus on the intersection of security engineering and continuous delivery pipelines. Published on static application security testing evaluation, container security posture management, and IoT-to-cloud secure data flows. Regularly supervises theses on CI/CD security automation.',
 4, 0, 1),

((SELECT id FROM users WHERE email='longo@seed.unime.it'),
 'Dipartimento MIFT — Informatica',
 'Statistical machine learning, time-series analysis, applied data science for public sector and healthcare. Recent work on interpretable models for clinical decision support, causal inference for policy evaluation, and Bayesian methods for small-sample scientific data. Supervises theses combining rigorous statistical foundations with applied ML.',
 3, 0, 1),

((SELECT id FROM users WHERE email='manco@seed.unime.it'),
 'Dipartimento MIFT — Informatica',
 'Natural language processing, knowledge representation, information extraction from historical and low-resource corpora. Currently leading a project on transformer-based NER and coreference resolution for digitised Sicilian administrative documents (16th–18th c.). Also interested in retrieval-augmented generation, structured knowledge grounding, and LLM evaluation.',
 3, 0, 1),

((SELECT id FROM users WHERE email='marotta@seed.unime.it'),
 'Dipartimento MIFT — Informatica',
 'Applied cryptography, network security, secure protocol design. Research on lightweight authentication for constrained devices, post-quantum cryptographic transitions, and formal verification of security protocols. Supervises theses in both offensive security (with responsible disclosure discipline) and defensive engineering.',
 2, 0, 1),

((SELECT id FROM users WHERE email='villari@seed.unime.it'),
 'Dipartimento MIFT — Informatica',
 'Cloud computing, IoT architectures, big data infrastructures, and their intersections. Long history of European projects on smart city platforms, sensor-network middleware, and federated cloud environments. Frequently co-supervises theses with the DevSecOps track.',
 3, 0, 1);


-- ============================================================================
-- COMPANIES (8)
-- ============================================================================

INSERT INTO users (email, password_hash, role, first_name, last_name) VALUES
('careers@reply.seed.company',        @PW, 'company', 'Reply',       'HR'),
('talent@engineering.seed.company',   @PW, 'company', 'Engineering', 'Talent'),
('recruiting@nttdata.seed.company',   @PW, 'company', 'NTT Data',    'Recruiting'),
('jobs@sicilytechhub.seed.company',   @PW, 'company', 'Sicily Tech', 'Hub'),
('people@bendingspoons.seed.company', @PW, 'company', 'Bending',     'Spoons'),
('careers@almaviva.seed.company',     @PW, 'company', 'Almaviva',    'HR'),
('talent@unicreditservices.seed.company', @PW, 'company', 'UniCredit', 'Services'),
('careers@musixmatch.seed.company',   @PW, 'company', 'Musixmatch',  'Team');

INSERT INTO companies (user_id, company_name, industry, website, address, contact_phone, description) VALUES
((SELECT id FROM users WHERE email='careers@reply.seed.company'),
 'Reply S.p.A.', 'IT Consulting', 'https://www.reply.com',
 'Corso Francia 110, 10143 Torino, Italy', '+39 011 7711594',
 'Reply is a global consulting, systems integration and digital services company (10,000+ employees) specialising in the design and implementation of solutions based on new communication channels and digital media. Reply operates through a network of specialised companies: Storm Reply (AWS Premier Partner), Communication Valley Reply (cybersecurity), Data Reply (data & AI), Cluster Reply (Microsoft), and others. Strong campus recruiting program with structured mentorship.'),

((SELECT id FROM users WHERE email='talent@engineering.seed.company'),
 'Engineering Ingegneria Informatica', 'IT Services', 'https://www.eng.it',
 'Piazzale dell''Agricoltura 24, 00144 Roma, Italy', '+39 06 87591',
 'Italian leader in software and services (12,000+ professionals across 40 offices). Focus areas: public administration digital transformation, financial services, healthcare informatics, energy & utilities, telecommunications. Runs one of the largest private R&D centres in Italy on cloud, AI, and cybersecurity.'),

((SELECT id FROM users WHERE email='recruiting@nttdata.seed.company'),
 'NTT Data Italia', 'Global IT Services', 'https://www.nttdata.com/it',
 'Viale Cassala 14/A, 20143 Milano, Italy', '+39 02 43441',
 'Global IT innovator (100,000+ globally, 3,000+ in Italy) delivering business, technology, and consulting solutions. Italy operations cover application development, cloud transformation, cybersecurity, and data & AI, serving finance, telco, industrial and public sector clients.'),

((SELECT id FROM users WHERE email='jobs@sicilytechhub.seed.company'),
 'Sicily Tech Hub', 'Startup Studio', 'https://sicilytechhub.io',
 'Via Etnea 45, 95124 Catania, Italy', '+39 095 7212345',
 'A Catania-based startup studio and incubator (~30 people) focused on building B2B SaaS products for Mediterranean logistics, agritech, and tourism. Small, fast-moving teams; interns work directly with founders and ship to real users in weeks. Portfolio includes an agritech farm management SaaS used by ~200 Sicilian cooperatives and a cultural tourism app.'),

((SELECT id FROM users WHERE email='people@bendingspoons.seed.company'),
 'Bending Spoons', 'Mobile Apps', 'https://bendingspoons.com',
 'Corso Como 15, 20154 Milano, Italy', '+39 02 87159395',
 'Milan-based technology company (700+ employees) that builds and operates a portfolio of consumer mobile applications used by hundreds of millions of people worldwide, including Evernote, Remini, and Splice. Known for a highly selective hiring bar (top few percent of applicants) and an engineering-driven, algorithms-heavy culture.'),

((SELECT id FROM users WHERE email='careers@almaviva.seed.company'),
 'Almaviva S.p.A.', 'Digital Transformation', 'https://www.almaviva.it',
 'Via di Casal Boccone 188-190, 00137 Roma, Italy', '+39 06 39931',
 'Italian ICT group (45,000+ employees globally) operating in innovation and digital transformation. Strong presence in transport (Trenitalia, ITA Airways), public administration, defence, and financial services. Delivers custom software, systems integration, and BPO services on large mission-critical programs.'),

((SELECT id FROM users WHERE email='talent@unicreditservices.seed.company'),
 'UniCredit Services', 'Banking IT', 'https://www.unicreditgroup.eu',
 'Piazza Gae Aulenti 3, 20154 Milano, Italy', '+39 02 88621',
 'The technology and operations backbone of UniCredit Group, one of Europe''s largest pan-European commercial banks. Runs core banking platforms, payment infrastructures, risk analytics systems, and the group''s cloud and cybersecurity operations across 13 countries. English is the working language.'),

((SELECT id FROM users WHERE email='careers@musixmatch.seed.company'),
 'Musixmatch', 'Music Tech', 'https://www.musixmatch.com',
 'Via Emilia Est 25, 40139 Bologna, Italy', '+39 051 6494091',
 'Bologna-based leader in music data and lyrics (~300 employees) — powers Spotify, Instagram, Amazon Music and Apple Music with synchronised lyrics across 80+ languages. Engineering culture centred on ML, NLP, large-scale data pipelines, and a proprietary language identification and translation stack.');


-- ============================================================================
-- STUDENTS (18) — rich, diverse profiles with competency descriptions
-- competency_description is the NLP-embedding source for the recommender.
-- ============================================================================

INSERT INTO users (email, password_hash, role, first_name, last_name) VALUES
('a.russo@seed.unime.it',        @PW, 'student', 'Alessandro', 'Russo'),
('g.marino@seed.unime.it',       @PW, 'student', 'Giulia',     'Marino'),
('l.bianchi@seed.unime.it',      @PW, 'student', 'Lorenzo',    'Bianchi'),
('s.ferrari@seed.unime.it',      @PW, 'student', 'Sofia',      'Ferrari'),
('m.costa@seed.unime.it',        @PW, 'student', 'Matteo',     'Costa'),
('c.romano@seed.unime.it',       @PW, 'student', 'Chiara',     'Romano'),
('f.greco@seed.unime.it',        @PW, 'student', 'Francesco',  'Greco'),
('v.bruno@seed.unime.it',        @PW, 'student', 'Valentina',  'Bruno'),
('r.villa@seed.unime.it',        @PW, 'student', 'Riccardo',   'Villa'),
('e.ricci@seed.unime.it',        @PW, 'student', 'Elena',      'Ricci'),
('m.sanna@seed.unime.it',        @PW, 'student', 'Marco',      'Sanna'),
('a.rahman@seed.unime.it',       @PW, 'student', 'Aisha',      'Rahman'),
('d.esposito@seed.unime.it',     @PW, 'student', 'Davide',     'Esposito'),
('g.palmieri@seed.unime.it',     @PW, 'student', 'Giorgia',    'Palmieri'),
('n.papadopoulos@seed.unime.it', @PW, 'student', 'Nikolaos',   'Papadopoulos'),
('t.moretti@seed.unime.it',      @PW, 'student', 'Tommaso',    'Moretti'),
('b.conte@seed.unime.it',        @PW, 'student', 'Beatrice',   'Conte'),
('y.chen@seed.unime.it',         @PW, 'student', 'Yuxi',       'Chen');

INSERT INTO students
    (user_id, registration_status, matricola, program, enrollment_year, total_cfu, gpa, competency_description)
VALUES

((SELECT id FROM users WHERE email='a.russo@seed.unime.it'), 'approved',
 '563901', 'Informatica (L-31)', 2023, 156, 29.4,
 'Final-year CS student passionate about machine learning and its applications in computer vision. Completed a summer research project at MIFT on efficient training of ViT (Vision Transformer) models for medical imaging. Comfortable with PyTorch, JAX, and CUDA basics. Strong mathematical foundation in linear algebra and probability. Fluent English (C1), currently learning German. Open to relocation. Portfolio includes an image-classification project reproducing a 2022 NeurIPS paper, a Kaggle top-15% finish on the RSNA pneumonia detection challenge, and a personal blog documenting paper-reading notes.'),

((SELECT id FROM users WHERE email='g.marino@seed.unime.it'), 'approved',
 '563902', 'Informatica (L-31)', 2023, 150, 28.7,
 'Full-stack developer with three years of self-directed side-project experience. Built and maintains a moderation dashboard for a Discord community with 15,000+ active users, using React on the frontend, Node.js with Express on the backend, PostgreSQL, and deployed to Fly.io. Interned briefly at a Palermo web agency last summer working on a WordPress-to-headless migration. Enjoys clean API design, readable code, and thoughtful database schemas. Strong grasp of REST, learning gRPC. Portfolio at giuliamarino.dev.'),

((SELECT id FROM users WHERE email='l.bianchi@seed.unime.it'), 'approved',
 '563903', 'Informatica (L-31)', 2023, 148, 27.9,
 'Cybersecurity enthusiast active in the community. Top-500 Italy on HackTheBox, plays with the local CTF team "ScyllaSec" (regional finalist 2024). Familiar with the OWASP Top 10, Burp Suite Professional, and modern static analysis tools including Semgrep and CodeQL. Recently completed the Portswigger Web Security Academy end-to-end. Interested in offensive security roles and red-team internships but also curious about DevSecOps as a career direction. Comfortable in Linux, Bash, Python for tooling; picking up Rust.'),

((SELECT id FROM users WHERE email='s.ferrari@seed.unime.it'), 'approved',
 '563904', 'Informatica (L-31)', 2023, 154, 29.8,
 'Statistically minded — strong background in probability, linear algebra, and Bayesian methods. Contributed to a public health data analysis project examining Covid-19 hospitalisation trends in Sicily as an undergraduate research assistant. Comfortable with R, pandas, scikit-learn, and PyMC. Interested in causal inference, applied econometrics, and interpretable machine learning. Thesis planned on interpretable ML for clinical decision support under Prof. Longo.'),

((SELECT id FROM users WHERE email='m.costa@seed.unime.it'), 'approved',
 '563905', 'Informatica (L-31)', 2023, 144, 27.2,
 'Mobile-first developer. Published two Android apps: a study-timer with over 8,000 installs and a Sicilian dialect phrasebook with 2,000 installs. Strong Kotlin (coroutines, Jetpack Compose), comfortable with SwiftUI for iOS side projects. Interested in cross-platform tooling (Flutter, Kotlin Multiplatform) and the intersection with on-device ML (TFLite, Core ML). Would love an internship on a real consumer product with a large user base.'),

((SELECT id FROM users WHERE email='c.romano@seed.unime.it'), 'approved',
 '563906', 'Informatica (L-31)', 2023, 152, 28.5,
 'DevOps and cloud-focused engineer. AWS Cloud Practitioner certified, currently studying for Solutions Architect Associate. Runs a home lab with a three-node k3s Kubernetes cluster hosting a dozen self-hosted services (Nextcloud, Grafana, Prometheus, Vaultwarden). Comfortable with Docker, Terraform, GitHub Actions, and Ansible. Contributed a small Prometheus exporter to an open-source project. Career direction: cloud-native infrastructure, SRE, or platform engineering.'),

((SELECT id FROM users WHERE email='f.greco@seed.unime.it'), 'approved',
 '563907', 'Informatica (L-31)', 2023, 149, 28.1,
 'Systems programmer at heart. Contributed to a Rust-based key-value store as part of a group project on GitHub (300+ stars). Solid C fundamentals, worked through Crafting Interpreters in C and Operating Systems: Three Easy Pieces cover to cover. Interested in databases, distributed systems, or compiler-adjacent work. Reads systems papers for fun; recent reads: Aurora, Spanner, the CRDT survey. Careful about writing well-tested code — has strong opinions on property-based testing.'),

((SELECT id FROM users WHERE email='v.bruno@seed.unime.it'), 'approved',
 '563908', 'Informatica (L-31)', 2023, 141, 26.8,
 'Game development focus. Ships games regularly on itch.io — most recent release is a puzzle platformer with a physics-based rope mechanic that has around 1,200 downloads. Comfortable with Unity, C#, and shader programming in HLSL. Learning Unreal 5 and Blueprints. Also interested in real-time graphics research: has read through the first half of Real-Time Rendering (4th ed.) and is playing with Vulkan basics. Weaker on backend/data topics but eager to learn.'),

((SELECT id FROM users WHERE email='r.villa@seed.unime.it'), 'approved',
 '563909', 'Informatica (L-31)', 2023, 147, 27.5,
 'Enterprise Java developer profile. Interned last summer at a Messina IT services firm working on a Spring Boot microservices platform for a regional healthcare provider — real production code, code reviews, on-call shadowing in month three. Comfortable with JPA/Hibernate, Kafka basics, JUnit 5, and PostgreSQL query tuning. Interested in fintech, healthtech, or any large-scale JVM shop with strong engineering discipline.'),

((SELECT id FROM users WHERE email='e.ricci@seed.unime.it'), 'approved',
 '563910', 'Informatica (L-31)', 2023, 145, 27.8,
 'Frontend engineer with a designer''s eye. Strong React and TypeScript, uses Figma daily, understands accessibility (WCAG 2.1 AA compliance). Built a Chrome extension for restaurant menu translation used by around 4,000 weekly users, monetised via a small Ko-fi. Interested in design systems, developer-experience tooling, and the frontend performance rabbit hole (Core Web Vitals, bundle size budgets, prerendering strategies). Portfolio at elenaricci.design.'),

((SELECT id FROM users WHERE email='m.sanna@seed.unime.it'), 'approved',
 '563911', 'Informatica (L-31)', 2023, 140, 26.5,
 'Interested in blockchain and distributed consensus, but with a critical eye. Wrote a naive Raft implementation in Go as a distributed systems course project — the exercise convinced him consensus is genuinely hard. Has deployed toy Solidity contracts on the Sepolia testnet. Curious about zero-knowledge proofs and MEV, but more interested in the underlying distributed systems and cryptography than in speculative token use cases. Aware the space has serious hype problems.'),

((SELECT id FROM users WHERE email='a.rahman@seed.unime.it'), 'approved',
 '563912', 'Informatica (L-31)', 2023, 155, 29.6,
 'International transfer student from Dhaka. Strong bioinformatics angle — worked on a summer research project applying transformer-based models to protein sequence data at BRAC University before transferring to Messina. Comfortable with the Python scientific stack (numpy, scipy, biopython), scikit-learn, and PyTorch for deep learning. Real appetite for reading ML research papers; keeps a paper-a-week reading routine. Would love an ML research internship, ideally something touching health, biology, or NLP.'),

((SELECT id FROM users WHERE email='d.esposito@seed.unime.it'), 'approved',
 '563913', 'Informatica (L-31)', 2023, 138, 24.7,
 'Generalist student still finding his direction. Enjoyed the databases course most; least confident on mathematics-heavy topics. Reliable, curious, and honest about what he doesn''t know yet — has been building a small technical blog on Astro to force himself to write about what he learns. Open to a broad range of internships and would benefit from a mentor-heavy environment where he can get regular feedback.'),

((SELECT id FROM users WHERE email='g.palmieri@seed.unime.it'), 'approved',
 '563914', 'Informatica (L-31)', 2023, 146, 28.3,
 'Human-computer interaction focus with a strong social-impact motivation. Volunteers with a local Messina NGO redesigning their online donation flow — ran usability testing with eight elderly users and delivered a Figma prototype that reduced task-completion time by 40 percent. Comfortable with React and Tailwind CSS on the implementation side. Curious about voice interfaces, AR/VR interaction paradigms, and accessibility engineering.'),

((SELECT id FROM users WHERE email='n.papadopoulos@seed.unime.it'), 'approved',
 '563915', 'Informatica (L-31)', 2023, 151, 28.9,
 'Erasmus student from Thessaloniki, now finishing his degree in Messina. Robotics and computer vision background — spent a summer at the AUTH robotics lab in Greece working on visual SLAM for indoor drones. Strong C++, comfortable with ROS 2, familiar with OpenCV and PyTorch. Interested in autonomous systems, industrial robotics, or CV-heavy startup roles. Reads robotics papers weekly and follows the RSS conference.'),

((SELECT id FROM users WHERE email='t.moretti@seed.unime.it'), 'approved',
 '563916', 'Informatica (L-31)', 2023, 143, 26.9,
 'Backend developer with a growing interest in data engineering. Interned two summers ago at a small Bologna e-commerce shop, worked on order-fulfilment pipelines in Python. Comfortable with SQL query design, has read Designing Data-Intensive Applications end to end. Wants to move toward proper data engineering roles — Airflow, Spark, columnar storage. Also enjoys writing careful documentation and thinks about API versioning strategies more than most third-year students do.'),

((SELECT id FROM users WHERE email='b.conte@seed.unime.it'), 'approved',
 '563917', 'Informatica (L-31)', 2023, 148, 29.0,
 'Applied ML student with an eye on production concerns. Completed the fast.ai course and Coursera''s ML Ops specialization on her own initiative. Interested in the boring but important parts of ML: monitoring, drift detection, feature stores, reproducibility. Contributed a small documentation improvement to scikit-learn. Currently doing a small side project deploying a movie recommender to a personal VPS with proper logging and A/B test infrastructure.'),

((SELECT id FROM users WHERE email='y.chen@seed.unime.it'), 'approved',
 '563918', 'Informatica (L-31)', 2023, 153, 29.2,
 'International student from Shanghai, transferred in her second year. Strong quantitative background — competed in the Chinese Mathematical Olympiad in high school. Interested in the intersection of algorithms, optimisation, and finance. Comfortable in C++ (competitive-programming style), Python, and has been learning KDB+/q on the side. Would love a quant-adjacent internship or an algorithms-heavy engineering role. Fluent English and Italian.');


-- ============================================================================
-- ACADEMIC RECORDS — realistic Italian curriculum with /30 grades
-- ============================================================================

INSERT INTO academic_records (student_id, exam_name, cfu, grade, exam_date)
SELECT s.id, x.exam_name, x.cfu, x.grade, x.exam_date
FROM students s
JOIN users u ON u.id = s.user_id
JOIN (
    -- Alessandro Russo (ML/CV top student)
    SELECT 'a.russo@seed.unime.it' AS em, 'Programmazione I'          AS exam_name, 9 AS cfu, '30' AS grade, '2024-02-10' AS exam_date UNION ALL
    SELECT 'a.russo@seed.unime.it',       'Algoritmi e Strutture Dati',  12,     '30',   '2024-06-15' UNION ALL
    SELECT 'a.russo@seed.unime.it',       'Analisi Matematica I',         9,     '29',   '2024-02-05' UNION ALL
    SELECT 'a.russo@seed.unime.it',       'Intelligenza Artificiale',    12,     '30L',  '2025-06-20' UNION ALL
    SELECT 'a.russo@seed.unime.it',       'Machine Learning',             9,     '30',   '2025-09-12' UNION ALL
    SELECT 'a.russo@seed.unime.it',       'Basi di Dati',                 9,     '28',   '2024-09-10' UNION ALL

    -- Giulia Marino (full-stack)
    SELECT 'g.marino@seed.unime.it',      'Programmazione I',             9,     '29',   '2024-02-14' UNION ALL
    SELECT 'g.marino@seed.unime.it',      'Programmazione ad Oggetti',    9,     '30',   '2024-06-20' UNION ALL
    SELECT 'g.marino@seed.unime.it',      'Basi di Dati',                 9,     '29',   '2024-09-08' UNION ALL
    SELECT 'g.marino@seed.unime.it',      'Ingegneria del Software',     12,     '28',   '2025-06-25' UNION ALL
    SELECT 'g.marino@seed.unime.it',      'Reti di Calcolatori',          9,     '28',   '2025-02-10' UNION ALL

    -- Lorenzo Bianchi (cybersec)
    SELECT 'l.bianchi@seed.unime.it',     'Programmazione I',             9,     '27',   '2024-02-16' UNION ALL
    SELECT 'l.bianchi@seed.unime.it',     'Sistemi Operativi',           12,     '29',   '2025-02-14' UNION ALL
    SELECT 'l.bianchi@seed.unime.it',     'Reti di Calcolatori',          9,     '30',   '2025-06-10' UNION ALL
    SELECT 'l.bianchi@seed.unime.it',     'Sicurezza Informatica',        9,     '30L',  '2025-09-15' UNION ALL
    SELECT 'l.bianchi@seed.unime.it',     'Crittografia',                 6,     '28',   '2025-06-28' UNION ALL

    -- Sofia Ferrari (data science top student)
    SELECT 's.ferrari@seed.unime.it',     'Analisi Matematica I',         9,     '30L',  '2024-02-08' UNION ALL
    SELECT 's.ferrari@seed.unime.it',     'Analisi Matematica II',        9,     '30',   '2024-06-12' UNION ALL
    SELECT 's.ferrari@seed.unime.it',     'Calcolo delle Probabilità',    9,     '30L',  '2025-02-05' UNION ALL
    SELECT 's.ferrari@seed.unime.it',     'Statistica',                   9,     '30',   '2025-06-15' UNION ALL
    SELECT 's.ferrari@seed.unime.it',     'Machine Learning',             9,     '29',   '2025-09-08' UNION ALL

    -- Matteo Costa (mobile)
    SELECT 'm.costa@seed.unime.it',       'Programmazione I',             9,     '26',   '2024-02-18' UNION ALL
    SELECT 'm.costa@seed.unime.it',       'Programmazione ad Oggetti',    9,     '28',   '2024-06-22' UNION ALL
    SELECT 'm.costa@seed.unime.it',       'Interazione Uomo-Macchina',    6,     '29',   '2025-06-08' UNION ALL
    SELECT 'm.costa@seed.unime.it',       'Ingegneria del Software',     12,     '26',   '2025-06-25' UNION ALL

    -- Chiara Romano (DevOps)
    SELECT 'c.romano@seed.unime.it',      'Sistemi Operativi',           12,     '29',   '2025-02-12' UNION ALL
    SELECT 'c.romano@seed.unime.it',      'Reti di Calcolatori',          9,     '29',   '2025-06-14' UNION ALL
    SELECT 'c.romano@seed.unime.it',      'Sistemi Distribuiti',          9,     '28',   '2025-09-20' UNION ALL
    SELECT 'c.romano@seed.unime.it',      'Ingegneria del Software',     12,     '28',   '2025-06-26' UNION ALL

    -- Francesco Greco (systems)
    SELECT 'f.greco@seed.unime.it',       'Programmazione I',             9,     '30',   '2024-02-11' UNION ALL
    SELECT 'f.greco@seed.unime.it',       'Sistemi Operativi',           12,     '30L',  '2025-02-15' UNION ALL
    SELECT 'f.greco@seed.unime.it',       'Algoritmi e Strutture Dati',  12,     '28',   '2024-06-18' UNION ALL
    SELECT 'f.greco@seed.unime.it',       'Basi di Dati',                 9,     '29',   '2024-09-12' UNION ALL

    -- Valentina Bruno (gamedev)
    SELECT 'v.bruno@seed.unime.it',       'Programmazione ad Oggetti',    9,     '27',   '2024-06-24' UNION ALL
    SELECT 'v.bruno@seed.unime.it',       'Computer Grafica',             9,     '29',   '2025-06-22' UNION ALL
    SELECT 'v.bruno@seed.unime.it',       'Interazione Uomo-Macchina',    6,     '27',   '2025-02-08' UNION ALL

    -- Riccardo Villa (Java/Spring)
    SELECT 'r.villa@seed.unime.it',       'Programmazione ad Oggetti',    9,     '28',   '2024-06-20' UNION ALL
    SELECT 'r.villa@seed.unime.it',       'Basi di Dati',                 9,     '27',   '2024-09-14' UNION ALL
    SELECT 'r.villa@seed.unime.it',       'Ingegneria del Software',     12,     '27',   '2025-06-26' UNION ALL

    -- Elena Ricci (frontend)
    SELECT 'e.ricci@seed.unime.it',       'Interazione Uomo-Macchina',    6,     '30',   '2025-02-06' UNION ALL
    SELECT 'e.ricci@seed.unime.it',       'Programmazione ad Oggetti',    9,     '28',   '2024-06-21' UNION ALL
    SELECT 'e.ricci@seed.unime.it',       'Ingegneria del Software',     12,     '28',   '2025-06-24' UNION ALL

    -- Marco Sanna (blockchain)
    SELECT 'm.sanna@seed.unime.it',       'Sistemi Distribuiti',          9,     '27',   '2025-09-18' UNION ALL
    SELECT 'm.sanna@seed.unime.it',       'Crittografia',                 6,     '26',   '2025-06-30' UNION ALL
    SELECT 'm.sanna@seed.unime.it',       'Algoritmi e Strutture Dati',  12,     '26',   '2024-06-19' UNION ALL

    -- Aisha Rahman (bio ML)
    SELECT 'a.rahman@seed.unime.it',      'Analisi Matematica I',         9,     '30',   '2024-02-09' UNION ALL
    SELECT 'a.rahman@seed.unime.it',      'Machine Learning',             9,     '30',   '2025-09-11' UNION ALL
    SELECT 'a.rahman@seed.unime.it',      'Intelligenza Artificiale',    12,     '30L',  '2025-06-19' UNION ALL
    SELECT 'a.rahman@seed.unime.it',      'Statistica',                   9,     '29',   '2025-06-16' UNION ALL

    -- Davide Esposito (weaker generalist)
    SELECT 'd.esposito@seed.unime.it',    'Programmazione I',             9,     '22',   '2024-06-20' UNION ALL
    SELECT 'd.esposito@seed.unime.it',    'Basi di Dati',                 9,     '26',   '2024-09-16' UNION ALL
    SELECT 'd.esposito@seed.unime.it',    'Analisi Matematica I',         9,     '20',   '2024-09-05' UNION ALL
    SELECT 'd.esposito@seed.unime.it',    'Ingegneria del Software',     12,     '24',   '2025-09-28' UNION ALL

    -- Giorgia Palmieri (HCI)
    SELECT 'g.palmieri@seed.unime.it',    'Interazione Uomo-Macchina',    6,     '30L',  '2025-02-07' UNION ALL
    SELECT 'g.palmieri@seed.unime.it',    'Ingegneria del Software',     12,     '29',   '2025-06-25' UNION ALL
    SELECT 'g.palmieri@seed.unime.it',    'Programmazione ad Oggetti',    9,     '28',   '2024-06-22' UNION ALL

    -- Nikolaos Papadopoulos (robotics/CV)
    SELECT 'n.papadopoulos@seed.unime.it','Computer Grafica',             9,     '29',   '2025-06-21' UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it','Sistemi Operativi',           12,     '29',   '2025-02-13' UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it','Intelligenza Artificiale',    12,     '28',   '2025-06-19' UNION ALL

    -- Tommaso Moretti (backend/data eng)
    SELECT 't.moretti@seed.unime.it',     'Basi di Dati',                 9,     '28',   '2024-09-10' UNION ALL
    SELECT 't.moretti@seed.unime.it',     'Programmazione ad Oggetti',    9,     '26',   '2024-06-23' UNION ALL
    SELECT 't.moretti@seed.unime.it',     'Ingegneria del Software',     12,     '27',   '2025-06-27' UNION ALL

    -- Beatrice Conte (applied ML/MLOps)
    SELECT 'b.conte@seed.unime.it',       'Machine Learning',             9,     '30',   '2025-09-09' UNION ALL
    SELECT 'b.conte@seed.unime.it',       'Statistica',                   9,     '29',   '2025-06-17' UNION ALL
    SELECT 'b.conte@seed.unime.it',       'Ingegneria del Software',     12,     '29',   '2025-06-25' UNION ALL
    SELECT 'b.conte@seed.unime.it',       'Basi di Dati',                 9,     '28',   '2024-09-11' UNION ALL

    -- Yuxi Chen (algorithms/quant)
    SELECT 'y.chen@seed.unime.it',        'Algoritmi e Strutture Dati',  12,     '30L',  '2024-06-16' UNION ALL
    SELECT 'y.chen@seed.unime.it',        'Analisi Matematica I',         9,     '30',   '2024-02-07' UNION ALL
    SELECT 'y.chen@seed.unime.it',        'Analisi Matematica II',        9,     '30',   '2024-06-13' UNION ALL
    SELECT 'y.chen@seed.unime.it',        'Calcolo delle Probabilità',    9,     '30L',  '2025-02-06' UNION ALL
    SELECT 'y.chen@seed.unime.it',        'Ricerca Operativa',            9,     '30',   '2025-06-11'
) x ON u.email = x.em;


-- ============================================================================
-- STUDENT SKILLS — proficiency per student
-- Skill names EXACTLY match the 002_esco.sql catalog names.
-- ============================================================================

INSERT INTO student_skills (student_id, skill_id, proficiency)
SELECT s.id, sk.id, x.proficiency
FROM students s
JOIN users u ON u.id = s.user_id
JOIN (
    -- Alessandro Russo — ML/CV
    SELECT 'a.russo@seed.unime.it' AS em, 'Python' AS sname, 'advanced' AS proficiency UNION ALL
    SELECT 'a.russo@seed.unime.it', 'PyTorch',              'advanced' UNION ALL
    SELECT 'a.russo@seed.unime.it', 'Machine learning',     'advanced' UNION ALL
    SELECT 'a.russo@seed.unime.it', 'Deep learning',        'advanced' UNION ALL
    SELECT 'a.russo@seed.unime.it', 'Computer vision',      'intermediate' UNION ALL
    SELECT 'a.russo@seed.unime.it', 'Transformer models',   'intermediate' UNION ALL
    SELECT 'a.russo@seed.unime.it', 'Linear algebra',       'advanced' UNION ALL
    SELECT 'a.russo@seed.unime.it', 'Git',                  'intermediate' UNION ALL

    -- Giulia Marino — full-stack
    SELECT 'g.marino@seed.unime.it', 'JavaScript',          'advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'TypeScript',          'intermediate' UNION ALL
    SELECT 'g.marino@seed.unime.it', 'React',               'advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'Node.js',             'advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'PostgreSQL',          'intermediate' UNION ALL
    SELECT 'g.marino@seed.unime.it', 'REST API development','advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'HTML',                'advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'CSS',                 'advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'Git',                 'advanced'     UNION ALL
    SELECT 'g.marino@seed.unime.it', 'Docker',              'beginner'     UNION ALL

    -- Lorenzo Bianchi — cybersec
    SELECT 'l.bianchi@seed.unime.it', 'Python',                       'advanced'     UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Linux system administration',  'advanced'     UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Network security',             'advanced'     UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Penetration testing',          'intermediate' UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'DevSecOps',                    'intermediate' UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Cryptography',                 'intermediate' UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'OWASP',                        'intermediate' UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'SAST / static security testing','intermediate' UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Bash / Shell scripting',       'advanced'     UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Git',                          'intermediate' UNION ALL

    -- Sofia Ferrari — data science
    SELECT 's.ferrari@seed.unime.it', 'Python',              'advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'R',                   'advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Statistical analysis','advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Machine learning',    'intermediate' UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'SQL',                 'intermediate' UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Data visualisation',  'advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'pandas',              'advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'NumPy',               'advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Linear algebra',      'advanced'     UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Probability theory',  'advanced'     UNION ALL

    -- Matteo Costa — mobile
    SELECT 'm.costa@seed.unime.it', 'Kotlin',                'advanced'     UNION ALL
    SELECT 'm.costa@seed.unime.it', 'Android development',   'advanced'     UNION ALL
    SELECT 'm.costa@seed.unime.it', 'Swift',                 'intermediate' UNION ALL
    SELECT 'm.costa@seed.unime.it', 'iOS development',       'intermediate' UNION ALL
    SELECT 'm.costa@seed.unime.it', 'Java',                  'intermediate' UNION ALL
    SELECT 'm.costa@seed.unime.it', 'Git',                   'intermediate' UNION ALL
    SELECT 'm.costa@seed.unime.it', 'REST API development',  'intermediate' UNION ALL

    -- Chiara Romano — DevOps
    SELECT 'c.romano@seed.unime.it', 'AWS',                          'intermediate' UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Docker',                       'advanced'     UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Kubernetes',                   'intermediate' UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Terraform',                    'intermediate' UNION ALL
    SELECT 'c.romano@seed.unime.it', 'GitHub Actions',               'advanced'     UNION ALL
    SELECT 'c.romano@seed.unime.it', 'CI/CD pipelines',              'advanced'     UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Linux system administration',  'advanced'     UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Bash / Shell scripting',       'advanced'     UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Python',                       'intermediate' UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Infrastructure as code',       'intermediate' UNION ALL

    -- Francesco Greco — systems
    SELECT 'f.greco@seed.unime.it', 'C',                             'advanced'     UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Rust',                          'intermediate' UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Linux system administration',   'advanced'     UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Operating systems',             'advanced'     UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Data structures',               'advanced'     UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Algorithms',                    'advanced'     UNION ALL
    SELECT 'f.greco@seed.unime.it', 'SQL',                           'intermediate' UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Distributed systems',           'intermediate' UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Git',                           'advanced'     UNION ALL

    -- Valentina Bruno — gamedev
    SELECT 'v.bruno@seed.unime.it', 'C#',                            'advanced'     UNION ALL
    SELECT 'v.bruno@seed.unime.it', 'Object-oriented programming',   'intermediate' UNION ALL
    SELECT 'v.bruno@seed.unime.it', 'Git',                           'intermediate' UNION ALL

    -- Riccardo Villa — Java/Spring
    SELECT 'r.villa@seed.unime.it', 'Java',                  'advanced'     UNION ALL
    SELECT 'r.villa@seed.unime.it', 'Spring Framework',      'intermediate' UNION ALL
    SELECT 'r.villa@seed.unime.it', 'SQL',                   'advanced'     UNION ALL
    SELECT 'r.villa@seed.unime.it', 'PostgreSQL',            'intermediate' UNION ALL
    SELECT 'r.villa@seed.unime.it', 'REST API development',  'advanced'     UNION ALL
    SELECT 'r.villa@seed.unime.it', 'Unit testing',          'intermediate' UNION ALL
    SELECT 'r.villa@seed.unime.it', 'Git',                   'intermediate' UNION ALL
    SELECT 'r.villa@seed.unime.it', 'Docker',                'beginner'     UNION ALL

    -- Elena Ricci — frontend
    SELECT 'e.ricci@seed.unime.it', 'JavaScript',   'advanced'     UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'TypeScript',   'intermediate' UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'React',        'advanced'     UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'HTML',         'advanced'     UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'CSS',         'advanced'     UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'Tailwind CSS', 'advanced'     UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'Web accessibility',      'intermediate' UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'Responsive web design',  'advanced'     UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'Git',                    'intermediate' UNION ALL

    -- Marco Sanna — blockchain
    SELECT 'm.sanna@seed.unime.it', 'Go',                    'intermediate' UNION ALL
    SELECT 'm.sanna@seed.unime.it', 'Cryptography',          'intermediate' UNION ALL
    SELECT 'm.sanna@seed.unime.it', 'Distributed systems',   'intermediate' UNION ALL
    SELECT 'm.sanna@seed.unime.it', 'Git',                   'intermediate' UNION ALL

    -- Aisha Rahman — ML research
    SELECT 'a.rahman@seed.unime.it', 'Python',           'advanced'     UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'PyTorch',          'intermediate' UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Machine learning', 'advanced'     UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Deep learning',    'intermediate' UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Natural language processing', 'intermediate' UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Transformer models','intermediate' UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Statistical analysis','advanced'   UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Linear algebra',   'advanced'     UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'pandas',           'intermediate' UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Linux system administration', 'intermediate' UNION ALL

    -- Davide Esposito — generalist weak
    SELECT 'd.esposito@seed.unime.it', 'Python',     'beginner'     UNION ALL
    SELECT 'd.esposito@seed.unime.it', 'SQL',        'intermediate' UNION ALL
    SELECT 'd.esposito@seed.unime.it', 'HTML',       'intermediate' UNION ALL
    SELECT 'd.esposito@seed.unime.it', 'CSS',       'intermediate' UNION ALL
    SELECT 'd.esposito@seed.unime.it', 'Git',        'beginner'     UNION ALL

    -- Giorgia Palmieri — HCI
    SELECT 'g.palmieri@seed.unime.it', 'React',            'intermediate' UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'HTML',             'advanced'     UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'CSS',             'advanced'     UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'JavaScript',       'intermediate' UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'Web accessibility','advanced'     UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'Responsive web design', 'intermediate' UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'Git',              'intermediate' UNION ALL

    -- Nikolaos Papadopoulos — robotics/CV
    SELECT 'n.papadopoulos@seed.unime.it', 'C++',                    'advanced'     UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'Python',                 'intermediate' UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'Computer vision',        'advanced'     UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'PyTorch',                'intermediate' UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'Robotics programming',   'intermediate' UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'Linux system administration', 'advanced' UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'Git',                    'intermediate' UNION ALL

    -- Tommaso Moretti — backend/data eng
    SELECT 't.moretti@seed.unime.it', 'Python',              'advanced'     UNION ALL
    SELECT 't.moretti@seed.unime.it', 'SQL',                 'advanced'     UNION ALL
    SELECT 't.moretti@seed.unime.it', 'PostgreSQL',          'intermediate' UNION ALL
    SELECT 't.moretti@seed.unime.it', 'REST API development','intermediate' UNION ALL
    SELECT 't.moretti@seed.unime.it', 'Docker',              'intermediate' UNION ALL
    SELECT 't.moretti@seed.unime.it', 'ETL processes',       'intermediate' UNION ALL
    SELECT 't.moretti@seed.unime.it', 'Git',                 'intermediate' UNION ALL

    -- Beatrice Conte — applied ML/MLOps
    SELECT 'b.conte@seed.unime.it', 'Python',              'advanced'     UNION ALL
    SELECT 'b.conte@seed.unime.it', 'Machine learning',    'advanced'     UNION ALL
    SELECT 'b.conte@seed.unime.it', 'scikit-learn',        'advanced'     UNION ALL
    SELECT 'b.conte@seed.unime.it', 'MLOps',               'intermediate' UNION ALL
    SELECT 'b.conte@seed.unime.it', 'Docker',              'intermediate' UNION ALL
    SELECT 'b.conte@seed.unime.it', 'Statistical analysis','intermediate' UNION ALL
    SELECT 'b.conte@seed.unime.it', 'SQL',                 'intermediate' UNION ALL
    SELECT 'b.conte@seed.unime.it', 'Git',                 'intermediate' UNION ALL

    -- Yuxi Chen — algorithms/quant
    SELECT 'y.chen@seed.unime.it', 'C++',                   'advanced'     UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Python',                'advanced'     UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Algorithms',            'advanced'     UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Data structures',       'advanced'     UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Optimisation theory',   'intermediate' UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Linear algebra',        'advanced'     UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Probability theory',    'advanced'     UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Git',                   'intermediate'
) x ON u.email = x.em
JOIN skills sk ON sk.name = x.sname;


-- ============================================================================
-- INTERNSHIP OFFERS (20)
-- type='company' for corporate postings, 'research' for professor postings
-- ============================================================================

INSERT INTO internship_offers
    (posted_by_user_id, type, title, description, domain, capacity, filled, min_cfu,
     required_program, duration_months, location, is_remote, status,
     application_deadline, start_date, is_paid, stipend_monthly, contact_email)
VALUES

((SELECT id FROM users WHERE email='careers@reply.seed.company'), 'company',
 'AI/ML Engineer Intern — Reply AI Lab',
 'Join Reply''s AI research and innovation practice in Milan. You will work alongside senior ML engineers on production applications of large language models: retrieval-augmented generation systems for enterprise knowledge bases, fine-tuning pipelines for domain-specific tasks, and evaluation frameworks for LLM outputs. Typical tech stack: Python, PyTorch, HuggingFace, LangChain, AWS SageMaker. You will pair with a mentor for the first month, then own a scoped feature within a client project. Prior exposure to transformers and modern NLP is important; industrial ML experience is not required.',
 'Artificial Intelligence', 2, 0, 130, 'Informatica (L-31)', 6, 'Milano', 0, 'open',
 '2026-03-15', '2026-04-01', 1, 900.00, 'careers@reply.seed.company'),

((SELECT id FROM users WHERE email='careers@reply.seed.company'), 'company',
 'Cloud Native Engineer Intern — Storm Reply',
 'Storm Reply is our AWS Premier Partner practice. Interns join engineering squads building cloud-native platforms for finance and telco clients — event-driven architectures with EKS, Terraform, and step functions. You will contribute to real client codebases (with mentorship and paired reviews), write infrastructure-as-code modules, and participate in on-call shadowing after month two. Interest in reliability engineering is welcomed.',
 'Cloud Computing', 2, 0, 130, NULL, 6, 'Torino', 0, 'open',
 '2026-03-15', '2026-04-01', 1, 850.00, 'careers@reply.seed.company'),

((SELECT id FROM users WHERE email='careers@reply.seed.company'), 'company',
 'Cybersecurity Analyst Intern — Communication Valley Reply',
 'Communication Valley Reply is Reply''s cybersecurity practice, running one of Italy''s largest 24/7 SOCs. Interns rotate across three tracks over six months: SOC monitoring (detection engineering with Splunk and Sentinel), vulnerability management (Tenable, custom Python scripts), and DevSecOps (SAST/DAST pipelines with SonarQube, Semgrep, and OWASP ZAP). You will work on real client alerts under supervision. Strong Linux fundamentals expected.',
 'Cybersecurity', 1, 0, 135, NULL, 6, 'Milano', 0, 'open',
 '2026-03-15', '2026-04-01', 1, 950.00, 'careers@reply.seed.company'),

((SELECT id FROM users WHERE email='talent@engineering.seed.company'), 'company',
 'Full-Stack Developer Intern — Public Sector Practice',
 'Engineering''s public sector division builds citizen-facing digital services for Italian regional and national agencies. This internship focuses on frontend development in React and TypeScript against a Java Spring Boot backend for a modernization program serving 2M+ citizens annually. You will work on a real feature end-to-end: from ticket refinement through implementation, code review, and acceptance testing. Emphasis on accessibility (WCAG 2.1 AA compliance is mandatory for public services in Italy).',
 'Web Development', 3, 0, 120, NULL, 6, 'Roma', 0, 'open',
 '2026-03-20', '2026-04-15', 1, 800.00, 'talent@engineering.seed.company'),

((SELECT id FROM users WHERE email='talent@engineering.seed.company'), 'company',
 'Data Engineer Intern — Healthcare Analytics',
 'The healthcare informatics division processes clinical and administrative data for regional health authorities. Interns join an existing data platform team working with Spark, Airflow, and Databricks on data pipelines that feed reporting and analytics products. You will implement and test one end-to-end pipeline (source → transformation → mart) over the placement. Interest in data quality and reproducibility is more important than prior big-data experience.',
 'Data Science', 2, 0, 130, NULL, 6, 'Bologna', 1, 'open',
 '2026-03-20', '2026-04-15', 1, 850.00, 'talent@engineering.seed.company'),

((SELECT id FROM users WHERE email='recruiting@nttdata.seed.company'), 'company',
 'Java Backend Engineer Intern — Financial Services',
 'Join a delivery team building high-throughput backend services for a top-three Italian bank. Tech: Java 21, Spring Boot 3, Kafka, PostgreSQL, running on OpenShift. The internship pairs you with a senior engineer for the full six months. You will own small production features (behind feature flags), participate in incident post-mortems, and receive structured mentoring in software design, testing, and observability practices.',
 'Software Engineering', 3, 0, 135, NULL, 6, 'Milano', 0, 'open',
 '2026-03-25', '2026-04-15', 1, 900.00, 'recruiting@nttdata.seed.company'),

((SELECT id FROM users WHERE email='recruiting@nttdata.seed.company'), 'company',
 'Data Science Intern — Advanced Analytics',
 'NTT Data''s advanced analytics practice serves finance, retail and telecommunications clients. Interns are embedded in one client engagement for the placement — typical work spans exploratory data analysis, feature engineering, model development (mostly classical ML: gradient boosting, time-series methods), and translating results for non-technical stakeholders. Strong statistics foundation expected; MLOps exposure is a plus but not required.',
 'Data Science', 2, 0, 140, NULL, 6, 'Milano', 1, 'open',
 '2026-03-25', '2026-04-15', 1, 900.00, 'recruiting@nttdata.seed.company'),

((SELECT id FROM users WHERE email='jobs@sicilytechhub.seed.company'), 'company',
 'Full-Stack Developer Intern — AgriTech Startup',
 'Small, fast-moving Catania-based startup building a farm management SaaS used by ~200 Sicilian agricultural cooperatives. You will work directly with the two founders (both ex-Big-Tech engineers) on the core product: React frontend, Node.js/Express backend, Postgres, deployed on Fly.io. Expect a mix of feature work, bug fixes, and infrastructure improvements. High autonomy, high learning rate, direct impact on real users. Suits people who want breadth over depth in their first industry experience.',
 'Web Development', 1, 0, 120, NULL, 6, 'Catania', 0, 'open',
 '2026-04-01', '2026-05-01', 1, 700.00, 'jobs@sicilytechhub.seed.company'),

((SELECT id FROM users WHERE email='jobs@sicilytechhub.seed.company'), 'company',
 'Mobile Developer Intern — Tourism App',
 'Portfolio startup building a native mobile app for cultural tourism in Sicily (guided walking tours with AR overlays, offline maps, audio guides in 5 languages). The intern will own one significant module — likely the offline map cache layer or the audio playback subsystem — end-to-end in Kotlin (Android). iOS parity handled by a contractor. Small team, direct product ownership.',
 'Mobile Development', 1, 0, 120, NULL, 6, 'Catania', 0, 'open',
 '2026-04-01', '2026-05-01', 1, 700.00, 'jobs@sicilytechhub.seed.company'),

((SELECT id FROM users WHERE email='people@bendingspoons.seed.company'), 'company',
 'Software Engineer Intern — Consumer Apps',
 'Bending Spoons hires extremely selectively; we expect the top few percent of the technical population. Interns join a product team owning a consumer app used by tens of millions and immediately contribute to production code. Six-month structured mentorship, weekly 1:1s, monthly technical rotations. You should be comfortable with algorithms and data structures at competitive-programming level, and be able to reason precisely about tradeoffs.',
 'Software Engineering', 2, 0, 145, NULL, 6, 'Milano', 0, 'open',
 '2026-02-28', '2026-04-01', 1, 1400.00, 'people@bendingspoons.seed.company'),

((SELECT id FROM users WHERE email='people@bendingspoons.seed.company'), 'company',
 'Machine Learning Engineer Intern — Applied AI',
 'The Applied AI team ships ML features into consumer products at scale. Recent work: on-device inference for image editing pipelines, learned super-resolution, and generative image models integrated into iOS and Android apps. Interns work on a scoped ML research-to-production project over the six months. Deep understanding of modern deep learning (transformers, diffusion models, or classical CV) is essential; production ML experience is not.',
 'Artificial Intelligence', 1, 0, 145, NULL, 6, 'Milano', 0, 'open',
 '2026-02-28', '2026-04-01', 1, 1500.00, 'people@bendingspoons.seed.company'),

((SELECT id FROM users WHERE email='careers@almaviva.seed.company'), 'company',
 'DevOps Engineer Intern — Transport Systems',
 'Almaviva runs mission-critical systems for Trenitalia and Italian railway infrastructure. This DevOps internship sits within the platform team maintaining the CI/CD infrastructure (Jenkins, GitLab CI, ArgoCD) for hundreds of microservices. Interns build tooling — pipeline linters, deployment dashboards, security scan integrations — that the rest of engineering uses daily. Strong Linux and shell scripting expected.',
 'DevOps', 2, 0, 130, NULL, 6, 'Roma', 0, 'open',
 '2026-03-30', '2026-04-30', 1, 800.00, 'careers@almaviva.seed.company'),

((SELECT id FROM users WHERE email='careers@almaviva.seed.company'), 'company',
 'Java/Angular Developer Intern — E-Government',
 'Feature development on a large digital service used by public administration users across Italy. Backend: Java 17, Spring Boot, Oracle DB. Frontend: Angular 17 with a strict internal design system. Emphasis on defect-free, well-tested delivery — you will write unit and integration tests as a first-class activity.',
 'Web Development', 3, 0, 120, NULL, 6, 'Roma', 0, 'open',
 '2026-03-30', '2026-04-30', 1, 750.00, 'careers@almaviva.seed.company'),

((SELECT id FROM users WHERE email='talent@unicreditservices.seed.company'), 'company',
 'Cybersecurity Engineer Intern — Threat Intelligence',
 'UniCredit''s Cybersecurity Center of Excellence in Milan runs threat intelligence, incident response, and red team operations across the group''s 13-country footprint. Interns join the Threat Intelligence unit — work includes malware triage, IOC extraction, and building automation around MISP and TheHive. You will pair with senior analysts on live investigations under strict supervision. Excellent English required.',
 'Cybersecurity', 1, 0, 140, NULL, 6, 'Milano', 0, 'open',
 '2026-03-01', '2026-04-01', 1, 1000.00, 'talent@unicreditservices.seed.company'),

((SELECT id FROM users WHERE email='careers@musixmatch.seed.company'), 'company',
 'NLP Engineer Intern — Language ID and Translation',
 'Musixmatch operates one of the largest music lyrics datasets in the world, in 80+ languages. The NLP team owns the language identification pipeline (used for auto-tagging incoming submissions) and the translation-alignment stack (used to sync translated lyrics to audio timestamps). Interns pick one improvement: benchmarking newer LID models against production, improving alignment for low-resource languages, or experimenting with LLM-assisted translation quality scoring.',
 'Artificial Intelligence', 1, 0, 140, NULL, 6, 'Bologna', 1, 'open',
 '2026-03-10', '2026-04-15', 1, 1100.00, 'careers@musixmatch.seed.company'),

((SELECT id FROM users WHERE email='careers@musixmatch.seed.company'), 'company',
 'Backend Engineer Intern — Data Infrastructure',
 'The data infrastructure team runs the pipelines that power lyrics search, catalog matching, and analytics for tier-1 partners (Spotify, Amazon Music). Tech: Python, Go, Kafka, Elasticsearch, BigQuery. Interns own a scoped improvement — likely a new microservice or a significant enhancement to an existing pipeline — with senior mentorship. Bologna office, hybrid schedule (2 days remote).',
 'Software Engineering', 1, 0, 130, NULL, 6, 'Bologna', 1, 'open',
 '2026-03-10', '2026-04-15', 1, 1000.00, 'careers@musixmatch.seed.company'),

-- ---------- Research offers (professor-posted) ----------
((SELECT id FROM users WHERE email='distefano@seed.unime.it'), 'research',
 'Research Internship — DevSecOps Pipeline Evaluation',
 'A university-hosted research internship as part of an ongoing project on static application security testing (SAST) evaluation. The intern will help build a reproducible benchmark harness comparing CodeQL, Semgrep, and SonarQube on a corpus of deliberately vulnerable applications (PyGoat, WebGoat, Juliet Test Suite subset). Deliverables include the harness, an evaluation report, and possibly co-authorship on a workshop paper. Suited to a student considering a thesis in this area.',
 'Cybersecurity', 1, 0, 130, NULL, 6, 'Messina', 0, 'open',
 '2026-03-31', '2026-04-15', 0, NULL, 'distefano@seed.unime.it'),

((SELECT id FROM users WHERE email='manco@seed.unime.it'), 'research',
 'Research Internship — NLP for Historical Documents',
 'Research internship on a project applying transformer-based NLP to a corpus of digitised historical Sicilian administrative documents (16th-18th century). The intern will work on named-entity recognition and coreference resolution on this challenging low-resource domain. Some familiarity with modern NLP is required.',
 'Artificial Intelligence', 1, 0, 130, NULL, 6, 'Messina', 0, 'open',
 '2026-03-31', '2026-04-15', 0, NULL, 'manco@seed.unime.it'),

((SELECT id FROM users WHERE email='longo@seed.unime.it'), 'research',
 'Research Internship — Interpretable ML for Clinical Decision Support',
 'Applied ML research on a dataset of anonymised electronic health records provided by a partner hospital. The intern will work on interpretable models (rule-based ensembles, calibrated logistic regression, SHAP explanations) for a specific clinical use case to be scoped on start. Strong statistical foundations are more important than deep-learning expertise.',
 'Data Science', 1, 0, 135, NULL, 6, 'Messina', 0, 'open',
 '2026-03-31', '2026-04-15', 0, NULL, 'longo@seed.unime.it'),

((SELECT id FROM users WHERE email='villari@seed.unime.it'), 'research',
 'Research Internship — Edge-to-Cloud IoT Data Flows',
 'Research on secure, low-latency data-flow architectures spanning edge sensors to cloud analytics platforms. The intern joins an ongoing European project and contributes to the middleware layer (Python and Go), plus benchmarks under simulated network conditions. Interest in both systems and security angles is helpful.',
 'Cloud Computing', 1, 0, 130, NULL, 6, 'Messina', 1, 'open',
 '2026-03-31', '2026-04-15', 0, NULL, 'villari@seed.unime.it');


-- ============================================================================
-- OFFER SKILLS — requirements per offer (importance + minimum_proficiency)
-- ============================================================================

INSERT INTO offer_skills (offer_id, skill_id, importance, minimum_proficiency)
SELECT o.id, sk.id, x.importance, x.min_prof
FROM internship_offers o
JOIN (
    -- Reply AI/ML
    SELECT 'AI/ML Engineer Intern — Reply AI Lab' AS otitle, 'Python' AS sname, 'required'  AS importance, 'advanced'     AS min_prof UNION ALL
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', 'Machine learning',    'required',  'advanced'     UNION ALL
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', 'PyTorch',             'required',  'intermediate' UNION ALL
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', 'Deep learning',       'required',  'intermediate' UNION ALL
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', 'Transformer models',  'preferred', 'intermediate' UNION ALL
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', 'AWS',                 'preferred', 'beginner'     UNION ALL

    -- Reply Cloud
    SELECT 'Cloud Native Engineer Intern — Storm Reply', 'AWS',                          'required',  'intermediate' UNION ALL
    SELECT 'Cloud Native Engineer Intern — Storm Reply', 'Docker',                       'required',  'intermediate' UNION ALL
    SELECT 'Cloud Native Engineer Intern — Storm Reply', 'Kubernetes',                   'required',  'beginner'     UNION ALL
    SELECT 'Cloud Native Engineer Intern — Storm Reply', 'Terraform',                    'required',  'beginner'     UNION ALL
    SELECT 'Cloud Native Engineer Intern — Storm Reply', 'Linux system administration',  'required',  'intermediate' UNION ALL
    SELECT 'Cloud Native Engineer Intern — Storm Reply', 'Python',                       'preferred', 'intermediate' UNION ALL

    -- Reply Cybersec
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', 'Linux system administration',   'required',  'advanced'     UNION ALL
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', 'Network security',              'required',  'advanced'     UNION ALL
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', 'Penetration testing',           'required',  'intermediate' UNION ALL
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', 'DevSecOps',                     'required',  'intermediate' UNION ALL
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', 'SAST / static security testing','preferred', 'intermediate' UNION ALL
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', 'Python',                        'preferred', 'intermediate' UNION ALL

    -- Engineering Full-Stack
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'React',       'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'TypeScript',  'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'Java',        'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'Spring Framework','required','beginner'   UNION ALL
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'HTML',        'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'CSS',        'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', 'Web accessibility','preferred','beginner' UNION ALL

    -- Engineering Data Eng
    SELECT 'Data Engineer Intern — Healthcare Analytics', 'Python',              'required',  'intermediate' UNION ALL
    SELECT 'Data Engineer Intern — Healthcare Analytics', 'SQL',                 'required',  'intermediate' UNION ALL
    SELECT 'Data Engineer Intern — Healthcare Analytics', 'ETL processes',       'preferred', 'beginner'     UNION ALL
    SELECT 'Data Engineer Intern — Healthcare Analytics', 'Statistical analysis','preferred', 'beginner'     UNION ALL

    -- NTT Java
    SELECT 'Java Backend Engineer Intern — Financial Services', 'Java',              'required',  'advanced'     UNION ALL
    SELECT 'Java Backend Engineer Intern — Financial Services', 'Spring Framework',  'required',  'intermediate' UNION ALL
    SELECT 'Java Backend Engineer Intern — Financial Services', 'SQL',               'required',  'intermediate' UNION ALL
    SELECT 'Java Backend Engineer Intern — Financial Services', 'REST API development','required','intermediate' UNION ALL
    SELECT 'Java Backend Engineer Intern — Financial Services', 'PostgreSQL',        'preferred', 'beginner'     UNION ALL
    SELECT 'Java Backend Engineer Intern — Financial Services', 'Docker',            'preferred', 'beginner'     UNION ALL

    -- NTT Data Science
    SELECT 'Data Science Intern — Advanced Analytics', 'Python',              'required',  'advanced'     UNION ALL
    SELECT 'Data Science Intern — Advanced Analytics', 'Statistical analysis','required',  'advanced'     UNION ALL
    SELECT 'Data Science Intern — Advanced Analytics', 'Machine learning',    'required',  'intermediate' UNION ALL
    SELECT 'Data Science Intern — Advanced Analytics', 'SQL',                 'required',  'intermediate' UNION ALL
    SELECT 'Data Science Intern — Advanced Analytics', 'R',                   'preferred', 'intermediate' UNION ALL

    -- Sicily AgriTech
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', 'JavaScript',    'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', 'React',         'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', 'Node.js',       'required',  'intermediate' UNION ALL
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', 'PostgreSQL',    'required',  'beginner'     UNION ALL
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', 'Git',           'preferred', 'intermediate' UNION ALL

    -- Sicily Mobile
    SELECT 'Mobile Developer Intern — Tourism App', 'Kotlin',              'required',  'intermediate' UNION ALL
    SELECT 'Mobile Developer Intern — Tourism App', 'Android development', 'required',  'intermediate' UNION ALL
    SELECT 'Mobile Developer Intern — Tourism App', 'Git',                 'preferred', 'intermediate' UNION ALL

    -- Bending Spoons SWE
    SELECT 'Software Engineer Intern — Consumer Apps', 'Data structures',           'required',  'advanced'     UNION ALL
    SELECT 'Software Engineer Intern — Consumer Apps', 'Algorithms',                'required',  'advanced'     UNION ALL
    SELECT 'Software Engineer Intern — Consumer Apps', 'Object-oriented programming','required', 'intermediate' UNION ALL
    SELECT 'Software Engineer Intern — Consumer Apps', 'Python',                    'preferred', 'intermediate' UNION ALL
    SELECT 'Software Engineer Intern — Consumer Apps', 'C++',                       'preferred', 'intermediate' UNION ALL
    SELECT 'Software Engineer Intern — Consumer Apps', 'Git',                       'required',  'intermediate' UNION ALL

    -- Bending Spoons ML
    SELECT 'Machine Learning Engineer Intern — Applied AI', 'Python',           'required',  'advanced' UNION ALL
    SELECT 'Machine Learning Engineer Intern — Applied AI', 'PyTorch',          'required',  'advanced' UNION ALL
    SELECT 'Machine Learning Engineer Intern — Applied AI', 'Deep learning',    'required',  'advanced' UNION ALL
    SELECT 'Machine Learning Engineer Intern — Applied AI', 'Machine learning', 'required',  'advanced' UNION ALL
    SELECT 'Machine Learning Engineer Intern — Applied AI', 'Computer vision',  'preferred', 'intermediate' UNION ALL

    -- Almaviva DevOps
    SELECT 'DevOps Engineer Intern — Transport Systems', 'Linux system administration', 'required',  'advanced'     UNION ALL
    SELECT 'DevOps Engineer Intern — Transport Systems', 'Bash / Shell scripting',      'required',  'intermediate' UNION ALL
    SELECT 'DevOps Engineer Intern — Transport Systems', 'Docker',                      'required',  'intermediate' UNION ALL
    SELECT 'DevOps Engineer Intern — Transport Systems', 'CI/CD pipelines',             'required',  'beginner'     UNION ALL
    SELECT 'DevOps Engineer Intern — Transport Systems', 'GitHub Actions',              'preferred', 'beginner'     UNION ALL
    SELECT 'DevOps Engineer Intern — Transport Systems', 'Python',                      'preferred', 'beginner'     UNION ALL

    -- Almaviva Java/Angular
    SELECT 'Java/Angular Developer Intern — E-Government', 'Java',             'required',  'intermediate' UNION ALL
    SELECT 'Java/Angular Developer Intern — E-Government', 'Spring Framework', 'required',  'beginner'     UNION ALL
    SELECT 'Java/Angular Developer Intern — E-Government', 'SQL',              'required',  'intermediate' UNION ALL
    SELECT 'Java/Angular Developer Intern — E-Government', 'Angular',          'preferred', 'beginner'     UNION ALL
    SELECT 'Java/Angular Developer Intern — E-Government', 'Unit testing',     'preferred', 'beginner'     UNION ALL

    -- UniCredit Cybersec
    SELECT 'Cybersecurity Engineer Intern — Threat Intelligence', 'Python',           'required',  'intermediate' UNION ALL
    SELECT 'Cybersecurity Engineer Intern — Threat Intelligence', 'Linux system administration','required','advanced' UNION ALL
    SELECT 'Cybersecurity Engineer Intern — Threat Intelligence', 'Network security', 'required',  'intermediate' UNION ALL
    SELECT 'Cybersecurity Engineer Intern — Threat Intelligence', 'Cryptography',     'preferred', 'beginner'     UNION ALL

    -- Musixmatch NLP
    SELECT 'NLP Engineer Intern — Language ID and Translation', 'Python',           'required',  'advanced'     UNION ALL
    SELECT 'NLP Engineer Intern — Language ID and Translation', 'Machine learning', 'required',  'intermediate' UNION ALL
    SELECT 'NLP Engineer Intern — Language ID and Translation', 'Natural language processing','required','intermediate' UNION ALL
    SELECT 'NLP Engineer Intern — Language ID and Translation', 'Deep learning',    'preferred', 'intermediate' UNION ALL
    SELECT 'NLP Engineer Intern — Language ID and Translation', 'PyTorch',          'preferred', 'intermediate' UNION ALL

    -- Musixmatch Backend
    SELECT 'Backend Engineer Intern — Data Infrastructure', 'Python',              'required',  'intermediate' UNION ALL
    SELECT 'Backend Engineer Intern — Data Infrastructure', 'Go',                  'preferred', 'beginner'     UNION ALL
    SELECT 'Backend Engineer Intern — Data Infrastructure', 'SQL',                 'required',  'intermediate' UNION ALL
    SELECT 'Backend Engineer Intern — Data Infrastructure', 'REST API development','required',  'intermediate' UNION ALL
    SELECT 'Backend Engineer Intern — Data Infrastructure', 'Docker',              'preferred', 'beginner'     UNION ALL

    -- Distefano DevSecOps
    SELECT 'Research Internship — DevSecOps Pipeline Evaluation', 'Python',    'required',  'intermediate' UNION ALL
    SELECT 'Research Internship — DevSecOps Pipeline Evaluation', 'Linux system administration','required','intermediate' UNION ALL
    SELECT 'Research Internship — DevSecOps Pipeline Evaluation', 'DevSecOps', 'required',  'beginner'     UNION ALL
    SELECT 'Research Internship — DevSecOps Pipeline Evaluation', 'SAST / static security testing','required','beginner' UNION ALL
    SELECT 'Research Internship — DevSecOps Pipeline Evaluation', 'Git',       'required',  'intermediate' UNION ALL

    -- Manco NLP
    SELECT 'Research Internship — NLP for Historical Documents', 'Python',           'required',  'advanced'     UNION ALL
    SELECT 'Research Internship — NLP for Historical Documents', 'Machine learning', 'required',  'intermediate' UNION ALL
    SELECT 'Research Internship — NLP for Historical Documents', 'Natural language processing','required','intermediate' UNION ALL
    SELECT 'Research Internship — NLP for Historical Documents', 'Deep learning',    'preferred', 'intermediate' UNION ALL

    -- Longo ML Clinical
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', 'Python',              'required',  'advanced'     UNION ALL
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', 'Statistical analysis','required',  'advanced'     UNION ALL
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', 'Machine learning',    'required',  'intermediate' UNION ALL
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', 'scikit-learn',        'preferred', 'intermediate' UNION ALL
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', 'R',                   'preferred', 'intermediate' UNION ALL

    -- Villari IoT
    SELECT 'Research Internship — Edge-to-Cloud IoT Data Flows', 'Python', 'required',  'intermediate' UNION ALL
    SELECT 'Research Internship — Edge-to-Cloud IoT Data Flows', 'Go',     'preferred', 'beginner'     UNION ALL
    SELECT 'Research Internship — Edge-to-Cloud IoT Data Flows', 'Linux system administration','required','intermediate' UNION ALL
    SELECT 'Research Internship — Edge-to-Cloud IoT Data Flows', 'IoT',    'required',  'beginner'
) x ON o.title = x.otitle
JOIN skills sk ON sk.name = x.sname;


-- ============================================================================
-- APPLICATIONS — realistic pattern (each student applies to 2-4 offers)
-- applications.status defaults to 'pending' which is what we want
-- ============================================================================

INSERT INTO applications (student_id, offer_id, status, cover_letter, applied_at)
SELECT s.id, o.id, 'pending',
       CONCAT('I am very interested in this internship because it aligns with my background in ',
              LEFT(s.competency_description, 120), '...'),
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND()*30) DAY)
FROM students s
JOIN users u ON u.id = s.user_id
JOIN internship_offers o ON o.title IN (
    SELECT x.otitle FROM (
        SELECT 'a.russo@seed.unime.it' AS em, 'AI/ML Engineer Intern — Reply AI Lab' AS otitle UNION ALL
        SELECT 'a.russo@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI' UNION ALL
        SELECT 'a.russo@seed.unime.it', 'Data Science Intern — Advanced Analytics' UNION ALL
        SELECT 'a.russo@seed.unime.it', 'NLP Engineer Intern — Language ID and Translation' UNION ALL

        SELECT 'g.marino@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice' UNION ALL
        SELECT 'g.marino@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup' UNION ALL
        SELECT 'g.marino@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure' UNION ALL
        SELECT 'g.marino@seed.unime.it', 'Software Engineer Intern — Consumer Apps' UNION ALL

        SELECT 'l.bianchi@seed.unime.it', 'Cybersecurity Analyst Intern — Communication Valley Reply' UNION ALL
        SELECT 'l.bianchi@seed.unime.it', 'Cybersecurity Engineer Intern — Threat Intelligence' UNION ALL
        SELECT 'l.bianchi@seed.unime.it', 'Research Internship — DevSecOps Pipeline Evaluation' UNION ALL

        SELECT 's.ferrari@seed.unime.it', 'Data Science Intern — Advanced Analytics' UNION ALL
        SELECT 's.ferrari@seed.unime.it', 'Data Engineer Intern — Healthcare Analytics' UNION ALL
        SELECT 's.ferrari@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab' UNION ALL
        SELECT 's.ferrari@seed.unime.it', 'Research Internship — Interpretable ML for Clinical Decision Support' UNION ALL

        SELECT 'm.costa@seed.unime.it', 'Mobile Developer Intern — Tourism App' UNION ALL
        SELECT 'm.costa@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup' UNION ALL
        SELECT 'm.costa@seed.unime.it', 'Software Engineer Intern — Consumer Apps' UNION ALL

        SELECT 'c.romano@seed.unime.it', 'Cloud Native Engineer Intern — Storm Reply' UNION ALL
        SELECT 'c.romano@seed.unime.it', 'DevOps Engineer Intern — Transport Systems' UNION ALL
        SELECT 'c.romano@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure' UNION ALL
        SELECT 'c.romano@seed.unime.it', 'Research Internship — Edge-to-Cloud IoT Data Flows' UNION ALL

        SELECT 'f.greco@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure' UNION ALL
        SELECT 'f.greco@seed.unime.it', 'Java Backend Engineer Intern — Financial Services' UNION ALL
        SELECT 'f.greco@seed.unime.it', 'Software Engineer Intern — Consumer Apps' UNION ALL

        SELECT 'v.bruno@seed.unime.it', 'Mobile Developer Intern — Tourism App' UNION ALL
        SELECT 'v.bruno@seed.unime.it', 'Software Engineer Intern — Consumer Apps' UNION ALL

        SELECT 'r.villa@seed.unime.it', 'Java Backend Engineer Intern — Financial Services' UNION ALL
        SELECT 'r.villa@seed.unime.it', 'Java/Angular Developer Intern — E-Government' UNION ALL
        SELECT 'r.villa@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice' UNION ALL

        SELECT 'e.ricci@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice' UNION ALL
        SELECT 'e.ricci@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup' UNION ALL
        SELECT 'e.ricci@seed.unime.it', 'Java/Angular Developer Intern — E-Government' UNION ALL

        SELECT 'm.sanna@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure' UNION ALL
        SELECT 'm.sanna@seed.unime.it', 'Cybersecurity Engineer Intern — Threat Intelligence' UNION ALL

        SELECT 'a.rahman@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab' UNION ALL
        SELECT 'a.rahman@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI' UNION ALL
        SELECT 'a.rahman@seed.unime.it', 'NLP Engineer Intern — Language ID and Translation' UNION ALL
        SELECT 'a.rahman@seed.unime.it', 'Research Internship — NLP for Historical Documents' UNION ALL

        SELECT 'd.esposito@seed.unime.it', 'Java/Angular Developer Intern — E-Government' UNION ALL
        SELECT 'd.esposito@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup' UNION ALL

        SELECT 'g.palmieri@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice' UNION ALL
        SELECT 'g.palmieri@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup' UNION ALL
        SELECT 'g.palmieri@seed.unime.it', 'Java/Angular Developer Intern — E-Government' UNION ALL

        SELECT 'n.papadopoulos@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI' UNION ALL
        SELECT 'n.papadopoulos@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab' UNION ALL
        SELECT 'n.papadopoulos@seed.unime.it', 'Research Internship — NLP for Historical Documents' UNION ALL

        SELECT 't.moretti@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure' UNION ALL
        SELECT 't.moretti@seed.unime.it', 'Data Engineer Intern — Healthcare Analytics' UNION ALL
        SELECT 't.moretti@seed.unime.it', 'Java Backend Engineer Intern — Financial Services' UNION ALL

        SELECT 'b.conte@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab' UNION ALL
        SELECT 'b.conte@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI' UNION ALL
        SELECT 'b.conte@seed.unime.it', 'Data Science Intern — Advanced Analytics' UNION ALL
        SELECT 'b.conte@seed.unime.it', 'Research Internship — Interpretable ML for Clinical Decision Support' UNION ALL

        SELECT 'y.chen@seed.unime.it', 'Software Engineer Intern — Consumer Apps' UNION ALL
        SELECT 'y.chen@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI' UNION ALL
        SELECT 'y.chen@seed.unime.it', 'Java Backend Engineer Intern — Financial Services'
    ) x WHERE x.em = u.email
);


-- ============================================================================
-- STUDENT PREFERENCES — ranked wishlists for matching
-- Not every student submits — that's realistic and produces unmatched pool
-- ============================================================================

INSERT INTO student_preferences (student_id, offer_id, `rank`, cycle_id, source)
SELECT s.id, o.id, x.rnk, @cycle_id, 'manual'
FROM students s JOIN users u ON u.id = s.user_id
JOIN (
    SELECT 'a.russo@seed.unime.it' AS em, 'Machine Learning Engineer Intern — Applied AI' AS otitle, 1 AS rnk UNION ALL
    SELECT 'a.russo@seed.unime.it',      'AI/ML Engineer Intern — Reply AI Lab',                   2 UNION ALL
    SELECT 'a.russo@seed.unime.it',      'NLP Engineer Intern — Language ID and Translation',      3 UNION ALL
    SELECT 'a.russo@seed.unime.it',      'Data Science Intern — Advanced Analytics',               4 UNION ALL

    SELECT 'g.marino@seed.unime.it', 'Software Engineer Intern — Consumer Apps',              1 UNION ALL
    SELECT 'g.marino@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup',        2 UNION ALL
    SELECT 'g.marino@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure',         3 UNION ALL
    SELECT 'g.marino@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice',  4 UNION ALL

    SELECT 'l.bianchi@seed.unime.it', 'Cybersecurity Engineer Intern — Threat Intelligence',           1 UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Cybersecurity Analyst Intern — Communication Valley Reply',    2 UNION ALL
    SELECT 'l.bianchi@seed.unime.it', 'Research Internship — DevSecOps Pipeline Evaluation',          3 UNION ALL

    SELECT 's.ferrari@seed.unime.it', 'Research Internship — Interpretable ML for Clinical Decision Support', 1 UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Data Science Intern — Advanced Analytics',                             2 UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab',                                 3 UNION ALL
    SELECT 's.ferrari@seed.unime.it', 'Data Engineer Intern — Healthcare Analytics',                          4 UNION ALL

    SELECT 'm.costa@seed.unime.it', 'Software Engineer Intern — Consumer Apps',        1 UNION ALL
    SELECT 'm.costa@seed.unime.it', 'Mobile Developer Intern — Tourism App',           2 UNION ALL
    SELECT 'm.costa@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup',  3 UNION ALL

    SELECT 'c.romano@seed.unime.it', 'Cloud Native Engineer Intern — Storm Reply',          1 UNION ALL
    SELECT 'c.romano@seed.unime.it', 'DevOps Engineer Intern — Transport Systems',          2 UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Research Internship — Edge-to-Cloud IoT Data Flows',  3 UNION ALL
    SELECT 'c.romano@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure',       4 UNION ALL

    SELECT 'f.greco@seed.unime.it', 'Software Engineer Intern — Consumer Apps',           1 UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure',      2 UNION ALL
    SELECT 'f.greco@seed.unime.it', 'Java Backend Engineer Intern — Financial Services',  3 UNION ALL

    SELECT 'r.villa@seed.unime.it', 'Java Backend Engineer Intern — Financial Services',      1 UNION ALL
    SELECT 'r.villa@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice',   2 UNION ALL
    SELECT 'r.villa@seed.unime.it', 'Java/Angular Developer Intern — E-Government',           3 UNION ALL

    SELECT 'e.ricci@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice',  1 UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup',        2 UNION ALL
    SELECT 'e.ricci@seed.unime.it', 'Java/Angular Developer Intern — E-Government',          3 UNION ALL

    SELECT 'a.rahman@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI',            1 UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab',                     2 UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'NLP Engineer Intern — Language ID and Translation',        3 UNION ALL
    SELECT 'a.rahman@seed.unime.it', 'Research Internship — NLP for Historical Documents',       4 UNION ALL

    SELECT 'g.palmieri@seed.unime.it', 'Full-Stack Developer Intern — Public Sector Practice',   1 UNION ALL
    SELECT 'g.palmieri@seed.unime.it', 'Full-Stack Developer Intern — AgriTech Startup',         2 UNION ALL

    SELECT 'n.papadopoulos@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI',       1 UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab',                2 UNION ALL
    SELECT 'n.papadopoulos@seed.unime.it', 'Research Internship — NLP for Historical Documents',  3 UNION ALL

    SELECT 't.moretti@seed.unime.it', 'Data Engineer Intern — Healthcare Analytics',        1 UNION ALL
    SELECT 't.moretti@seed.unime.it', 'Backend Engineer Intern — Data Infrastructure',      2 UNION ALL
    SELECT 't.moretti@seed.unime.it', 'Java Backend Engineer Intern — Financial Services',  3 UNION ALL

    SELECT 'b.conte@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI',           1 UNION ALL
    SELECT 'b.conte@seed.unime.it', 'AI/ML Engineer Intern — Reply AI Lab',                    2 UNION ALL
    SELECT 'b.conte@seed.unime.it', 'Data Science Intern — Advanced Analytics',                3 UNION ALL
    SELECT 'b.conte@seed.unime.it', 'Research Internship — Interpretable ML for Clinical Decision Support', 4 UNION ALL

    SELECT 'y.chen@seed.unime.it', 'Software Engineer Intern — Consumer Apps',       1 UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Machine Learning Engineer Intern — Applied AI',  2 UNION ALL
    SELECT 'y.chen@seed.unime.it', 'Java Backend Engineer Intern — Financial Services', 3
) x ON u.email = x.em
JOIN internship_offers o ON o.title = x.otitle;


-- ============================================================================
-- OFFER RANKINGS — how companies/professors rank their applicants
-- Note: an applicant NOT ranked by an offer will be treated as rejected_unranked
-- ============================================================================

INSERT INTO offer_rankings (offer_id, student_id, `rank`, cycle_id, source)
SELECT o.id, s.id, x.rnk, @cycle_id, 'manual'
FROM internship_offers o
JOIN (
    -- Reply AI/ML Lab
    SELECT 'AI/ML Engineer Intern — Reply AI Lab' AS otitle, '563912' AS matr, 1 AS rnk UNION ALL   -- Aisha
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', '563901', 2 UNION ALL                             -- Alessandro
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', '563915', 3 UNION ALL                             -- Nikolaos
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', '563917', 4 UNION ALL                             -- Beatrice
    SELECT 'AI/ML Engineer Intern — Reply AI Lab', '563904', 5 UNION ALL                             -- Sofia

    -- Reply Cloud
    SELECT 'Cloud Native Engineer Intern — Storm Reply', '563906', 1 UNION ALL                       -- Chiara

    -- Reply Cybersec
    SELECT 'Cybersecurity Analyst Intern — Communication Valley Reply', '563903', 1 UNION ALL        -- Lorenzo

    -- Engineering Public Sector
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', '563910', 1 UNION ALL             -- Elena
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', '563914', 2 UNION ALL             -- Giorgia
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', '563902', 3 UNION ALL             -- Giulia
    SELECT 'Full-Stack Developer Intern — Public Sector Practice', '563909', 4 UNION ALL             -- Riccardo

    -- Engineering Data Engineer
    SELECT 'Data Engineer Intern — Healthcare Analytics', '563904', 1 UNION ALL                      -- Sofia
    SELECT 'Data Engineer Intern — Healthcare Analytics', '563916', 2 UNION ALL                      -- Tommaso

    -- NTT Java
    SELECT 'Java Backend Engineer Intern — Financial Services', '563909', 1 UNION ALL                -- Riccardo
    SELECT 'Java Backend Engineer Intern — Financial Services', '563907', 2 UNION ALL                -- Francesco
    SELECT 'Java Backend Engineer Intern — Financial Services', '563918', 3 UNION ALL                -- Yuxi
    SELECT 'Java Backend Engineer Intern — Financial Services', '563916', 4 UNION ALL                -- Tommaso

    -- NTT Data Science
    SELECT 'Data Science Intern — Advanced Analytics', '563904', 1 UNION ALL                         -- Sofia
    SELECT 'Data Science Intern — Advanced Analytics', '563901', 2 UNION ALL                         -- Alessandro
    SELECT 'Data Science Intern — Advanced Analytics', '563917', 3 UNION ALL                         -- Beatrice

    -- Sicily AgriTech
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', '563902', 1 UNION ALL                   -- Giulia
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', '563910', 2 UNION ALL                   -- Elena
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', '563914', 3 UNION ALL                   -- Giorgia
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', '563905', 4 UNION ALL                   -- Matteo
    SELECT 'Full-Stack Developer Intern — AgriTech Startup', '563913', 5 UNION ALL                   -- Davide

    -- Sicily Tourism Mobile
    SELECT 'Mobile Developer Intern — Tourism App', '563905', 1 UNION ALL                            -- Matteo
    SELECT 'Mobile Developer Intern — Tourism App', '563908', 2 UNION ALL                            -- Valentina

    -- Bending Spoons SWE  (Yuxi and Francesco top, others below)
    SELECT 'Software Engineer Intern — Consumer Apps', '563918', 1 UNION ALL                         -- Yuxi
    SELECT 'Software Engineer Intern — Consumer Apps', '563907', 2 UNION ALL                         -- Francesco
    SELECT 'Software Engineer Intern — Consumer Apps', '563901', 3 UNION ALL                         -- Alessandro
    SELECT 'Software Engineer Intern — Consumer Apps', '563902', 4 UNION ALL                         -- Giulia
    SELECT 'Software Engineer Intern — Consumer Apps', '563905', 5 UNION ALL                         -- Matteo

    -- Bending Spoons ML
    SELECT 'Machine Learning Engineer Intern — Applied AI', '563901', 1 UNION ALL                    -- Alessandro
    SELECT 'Machine Learning Engineer Intern — Applied AI', '563912', 2 UNION ALL                    -- Aisha
    SELECT 'Machine Learning Engineer Intern — Applied AI', '563917', 3 UNION ALL                    -- Beatrice
    SELECT 'Machine Learning Engineer Intern — Applied AI', '563915', 4 UNION ALL                    -- Nikolaos
    SELECT 'Machine Learning Engineer Intern — Applied AI', '563918', 5 UNION ALL                    -- Yuxi

    -- Almaviva Java/Angular
    SELECT 'Java/Angular Developer Intern — E-Government', '563909', 1 UNION ALL                     -- Riccardo
    SELECT 'Java/Angular Developer Intern — E-Government', '563910', 2 UNION ALL                     -- Elena
    SELECT 'Java/Angular Developer Intern — E-Government', '563914', 3 UNION ALL                     -- Giorgia
    SELECT 'Java/Angular Developer Intern — E-Government', '563913', 4 UNION ALL                     -- Davide

    -- Almaviva DevOps  (Chiara applied elsewhere — no applicants ranked for this offer)
    -- (intentional: check_all_offers_ranked will flag this if Chiara later applies)

    -- UniCredit Cybersec
    SELECT 'Cybersecurity Engineer Intern — Threat Intelligence', '563903', 1 UNION ALL              -- Lorenzo
    SELECT 'Cybersecurity Engineer Intern — Threat Intelligence', '563911', 2 UNION ALL              -- Marco

    -- Musixmatch NLP
    SELECT 'NLP Engineer Intern — Language ID and Translation', '563912', 1 UNION ALL                -- Aisha
    SELECT 'NLP Engineer Intern — Language ID and Translation', '563901', 2 UNION ALL                -- Alessandro

    -- Musixmatch Backend
    SELECT 'Backend Engineer Intern — Data Infrastructure', '563907', 1 UNION ALL                    -- Francesco
    SELECT 'Backend Engineer Intern — Data Infrastructure', '563906', 2 UNION ALL                    -- Chiara
    SELECT 'Backend Engineer Intern — Data Infrastructure', '563902', 3 UNION ALL                    -- Giulia
    SELECT 'Backend Engineer Intern — Data Infrastructure', '563916', 4 UNION ALL                    -- Tommaso
    SELECT 'Backend Engineer Intern — Data Infrastructure', '563911', 5 UNION ALL                    -- Marco

    -- Distefano DevSecOps research
    SELECT 'Research Internship — DevSecOps Pipeline Evaluation', '563903', 1 UNION ALL              -- Lorenzo

    -- Manco NLP research
    SELECT 'Research Internship — NLP for Historical Documents', '563912', 1 UNION ALL               -- Aisha
    SELECT 'Research Internship — NLP for Historical Documents', '563915', 2 UNION ALL               -- Nikolaos

    -- Longo Clinical ML research
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', '563904', 1 UNION ALL  -- Sofia
    SELECT 'Research Internship — Interpretable ML for Clinical Decision Support', '563917', 2 UNION ALL  -- Beatrice

    -- Villari IoT research
    SELECT 'Research Internship — Edge-to-Cloud IoT Data Flows', '563906', 1                          -- Chiara
) x ON o.title = x.otitle
JOIN students s ON s.matricola = x.matr;


-- ============================================================================
-- Verification — run these after seeding to confirm counts
-- ============================================================================
-- SELECT COUNT(*) FROM users        WHERE email LIKE '%@seed.%';                     -- expect 31
-- SELECT COUNT(*) FROM students     WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@seed.unime.it');  -- 18
-- SELECT COUNT(*) FROM professors   WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@seed.unime.it');  -- 5
-- SELECT COUNT(*) FROM companies    WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@seed.company');   -- 8
-- SELECT COUNT(*) FROM internship_offers WHERE contact_email LIKE '%@seed.%';        -- 20
-- SELECT COUNT(*) FROM applications WHERE offer_id IN (SELECT id FROM internship_offers WHERE contact_email LIKE '%@seed.%');
-- SELECT COUNT(*) FROM student_preferences;
-- SELECT COUNT(*) FROM offer_rankings;