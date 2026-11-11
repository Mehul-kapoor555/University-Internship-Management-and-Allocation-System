<?php
/**
 * Seed script — populates the database with realistic sample data for demos.
 * Usage:  C:\xampp\php\php.exe scripts\seed.php
 * 
 * Safe to re-run — clears data (keeping the default coordinator) before seeding.
 */

require __DIR__ . '/../includes/db.php';

echo "Seeding sample data...\n";

// Clear existing data (keep the seeded coordinator + skills)
$db->exec("SET FOREIGN_KEY_CHECKS = 0");
$db->exec("DELETE FROM notifications");
$db->exec("DELETE FROM applications");
$db->exec("DELETE FROM offer_skills");
$db->exec("DELETE FROM internship_offers");
$db->exec("DELETE FROM student_skills");
$db->exec("DELETE FROM professor_skills");
$db->exec("DELETE FROM academic_records");
$db->exec("DELETE FROM students");
$db->exec("DELETE FROM professors");
$db->exec("DELETE FROM companies");
$db->exec("DELETE FROM users WHERE role != 'coordinator'");
$db->exec("ALTER TABLE users AUTO_INCREMENT = 100");
$db->exec("ALTER TABLE students AUTO_INCREMENT = 1");
$db->exec("ALTER TABLE professors AUTO_INCREMENT = 1");
$db->exec("ALTER TABLE companies AUTO_INCREMENT = 1");
$db->exec("ALTER TABLE internship_offers AUTO_INCREMENT = 1");
$db->exec("SET FOREIGN_KEY_CHECKS = 1");

$pw = password_hash('123', PASSWORD_DEFAULT);

// Helper to get skill IDs by name
$skillId = function($name) use ($db) {
    $stmt = $db->prepare("SELECT id FROM skills WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    return $stmt->fetchColumn() ?: null;
};

// ============================================================
// PROFESSORS (5)
// ============================================================
$professorsSeed = [
    ['Salvatore', 'Distefano',  'distefano@unime.it',  'MIFT',                   4, ['Software architecture','Design patterns','UML','Software testing']],
    ['Massimo',   'Villari',    'villari@unime.it',    'MIFT',                   3, ['Cloud architecture','Distributed systems','DevSecOps','Kubernetes']],
    ['Francesco', 'La Rosa',    'larosa@unime.it',     'MIFT',                   3, ['Cybersecurity','Network security','SAST / static security testing']],
    ['Giovanni',  'Finocchio',  'finocchio@unime.it',  'MIFT',                   2, ['Machine learning','Neural networks','Reinforcement learning','MATLAB']],
    ['Marta',     'Bianchi',    'bianchi@unime.it',    'Mathematics',            3, ['Optimisation theory','Graph theory','Linear algebra','Algorithms']],
];

$profIds = [];
foreach ($professorsSeed as $p) {
    [$first, $last, $email, $dept, $capacity, $skills] = $p;
    $stmt = $db->prepare(
        "INSERT INTO users (email, password_hash, role, first_name, last_name, is_active)
         VALUES (?, ?, 'professor', ?, ?, 1)"
    );
    $stmt->execute([$email, $pw, $first, $last]);
    $userId = $db->lastInsertId();

    $stmt = $db->prepare(
        "INSERT INTO professors (user_id, department, supervision_capacity, expertise_description)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $dept, $capacity, "$first $last works on " . implode(', ', array_slice($skills, 0, 2)) . " and related topics."]);
    $profId = $db->lastInsertId();
    $profIds[] = ['id' => $profId, 'user_id' => $userId, 'name' => "$first $last"];

    foreach ($skills as $sn) {
        $sid = $skillId($sn);
        if ($sid) {
            $db->prepare("INSERT INTO professor_skills (professor_id, skill_id, proficiency) VALUES (?, ?, 'advanced')")
               ->execute([$profId, $sid]);
        }
    }
}
echo "  " . count($profIds) . " professors\n";

// ============================================================
// COMPANIES (5)
// ============================================================
$companiesSeed = [
    ['Reply',           'reply@example.com',        'Consulting',           'https://www.reply.com',    'Digital consulting group based in Turin with strong AI and cloud practices.'],
    ['Engineering S.p.A.','contact@eng-example.com','IT Services',          'https://www.eng.it',       'Italian IT services company, one of the largest software integrators in Southern Europe.'],
    ['Almaviva',        'hr@almaviva-example.com',  'Digital Services',     'https://www.almaviva.it',  'Italian ICT services group serving public administration and enterprises.'],
    ['NTT DATA Italia', 'it@ntt-example.com',       'IT Consulting',        'https://www.nttdata.it',   'Consulting and system integration, with a Palermo delivery hub.'],
    ['Sysdig',          'jobs@sysdig-example.com',  'Cloud Security',       'https://www.sysdig.com',   'Cloud-native security & observability, founded in Italy.'],
];

$companyIds = [];
foreach ($companiesSeed as $c) {
    [$name, $email, $industry, $site, $descr] = $c;
    $stmt = $db->prepare(
        "INSERT INTO users (email, password_hash, role, first_name, last_name, is_active)
         VALUES (?, ?, 'company', ?, ?, 1)"
    );
    $stmt->execute([$email, $pw, 'HR', $name, ]);
    $userId = $db->lastInsertId();

    $stmt = $db->prepare(
        "INSERT INTO companies (user_id, company_name, industry, website, description)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $name, $industry, $site, $descr]);
    $companyIds[] = ['id' => $db->lastInsertId(), 'user_id' => $userId, 'name' => $name];
}
echo "  " . count($companyIds) . " companies\n";

// ============================================================
// STUDENTS (12) — mix of approved + pending
// ============================================================
$studentsSeed = [
    ['Luca',      'Romano',    'luca.romano@studenti.unime.it',    '563901', 'BSc Computer Science', 2023, 'approved', 138, ['Python' => 'advanced', 'SQL' => 'intermediate', 'JavaScript' => 'intermediate', 'React' => 'beginner', 'Git' => 'advanced']],
    ['Giulia',    'Ferrari',   'giulia.ferrari@studenti.unime.it', '563902', 'BSc Computer Science', 2023, 'approved', 165, ['Machine learning' => 'intermediate', 'Python' => 'advanced', 'pandas' => 'advanced', 'NumPy' => 'advanced', 'scikit-learn' => 'intermediate']],
    ['Marco',     'Ricci',     'marco.ricci@studenti.unime.it',    '563903', 'BSc Computer Science', 2022, 'approved', 174, ['Java' => 'advanced', 'Spring Framework' => 'intermediate', 'MySQL' => 'intermediate', 'REST API development' => 'intermediate', 'Docker' => 'beginner']],
    ['Sofia',     'Marino',    'sofia.marino@studenti.unime.it',   '563904', 'BSc Computer Science', 2023, 'approved', 129, ['PHP' => 'intermediate', 'HTML' => 'advanced', 'CSS' => 'advanced', 'JavaScript' => 'intermediate', 'MySQL' => 'intermediate']],
    ['Andrea',    'Greco',     'andrea.greco@studenti.unime.it',   '563905', 'BSc Computer Science', 2022, 'approved', 182, ['C++' => 'advanced', 'Algorithms' => 'advanced', 'Data structures' => 'advanced', 'Linux system administration' => 'intermediate']],
    ['Mahesh',    'Nair',      'mahesh.nair@studenti.unime.it',    '563906', 'BSc Computer Science', 2023, 'approved', 151, ['Python' => 'advanced', 'PHP' => 'intermediate', 'MySQL' => 'intermediate', 'Machine learning' => 'intermediate', 'Docker' => 'beginner', 'Git' => 'advanced']],
    ['Chiara',    'Costa',     'chiara.costa@studenti.unime.it',   '563907', 'BSc Data Science',     2023, 'approved', 144, ['Python' => 'advanced', 'Statistical analysis' => 'intermediate', 'Data visualisation' => 'advanced', 'Tableau' => 'intermediate']],
    ['Davide',    'Russo',     'davide.russo@studenti.unime.it',   '563908', 'BSc Computer Science', 2023, 'approved', 122, ['Cybersecurity' => 'intermediate', 'Penetration testing' => 'beginner', 'Linux system administration' => 'advanced', 'Python' => 'intermediate']],
    ['Elena',     'Conti',     'elena.conti@studenti.unime.it',    '563909', 'BSc Computer Science', 2022, 'approved', 168, ['React' => 'advanced', 'TypeScript' => 'advanced', 'Node.js' => 'intermediate', 'REST API development' => 'intermediate']],
    ['Francesco', 'Esposito',  'francesco.esposito@studenti.unime.it', '563910', 'BSc Computer Science', 2023, 'approved', 133, ['Java' => 'intermediate', 'Object-oriented programming' => 'advanced', 'SQL' => 'intermediate', 'Agile methodologies' => 'intermediate']],
    // Two pending — for testing the approval flow
    ['Alessia',   'Moretti',   'alessia.moretti@studenti.unime.it', '563911', 'BSc Computer Science', 2024, 'pending',  0,   []],
    ['Nicola',    'Bruno',     'nicola.bruno@studenti.unime.it',    '563912', 'BSc Data Science',     2024, 'pending',  0,   []],
];

$studentIds = [];
foreach ($studentsSeed as $s) {
    [$first, $last, $email, $matricola, $program, $year, $status, $cfu, $skills] = $s;
    $isActive = $status === 'approved' ? 1 : 0;

    $stmt = $db->prepare(
        "INSERT INTO users (email, password_hash, role, first_name, last_name, is_active)
         VALUES (?, ?, 'student', ?, ?, ?)"
    );
    $stmt->execute([$email, $pw, $first, $last, $isActive]);
    $userId = $db->lastInsertId();

    $stmt = $db->prepare(
        "INSERT INTO students (user_id, matricola, program, enrollment_year, total_cfu, competency_description, registration_status, approved_by_user_id, approved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'approved' ? 'NOW()' : 'NULL') . ")"
    );
    $desc = $status === 'approved'
        ? "$first is a $program student interested in " . (empty($skills) ? 'software engineering' : implode(', ', array_slice(array_keys($skills), 0, 2))) . '.'
        : '';
    $stmt->execute([
        $userId, $matricola, $program, $year, $cfu, $desc, $status,
        $status === 'approved' ? 1 : null  // approved by coordinator id 1
    ]);
    $studentId = $db->lastInsertId();
    $studentIds[] = ['id' => $studentId, 'user_id' => $userId, 'name' => "$first $last"];

    foreach ($skills as $sn => $lvl) {
        $sid = $skillId($sn);
        if ($sid) {
            $db->prepare("INSERT INTO student_skills (student_id, skill_id, proficiency) VALUES (?, ?, ?)")
               ->execute([$studentId, $sid, $lvl]);
        }
    }

    // A few sample verified academic records for approved students
    if ($status === 'approved' && $cfu > 0) {
        $sampleExams = [
            ['Programmazione I', 12, '28/30', '2023-06-15'],
            ['Basi di Dati', 9, '27/30', '2023-07-10'],
            ['Ingegneria del Software', 9, '26/30', '2024-02-05'],
            ['Sistemi Operativi', 9, '25/30', '2024-01-20'],
            ['Algoritmi e Strutture Dati', 12, '29/30', '2023-09-18'],
        ];
        foreach ($sampleExams as $ex) {
            if ($cfu <= 0) break;
            $stmt = $db->prepare(
                "INSERT INTO academic_records (student_id, exam_name, cfu, grade, exam_date, verified_by_user_id, verified_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())"
            );
            $stmt->execute([$studentId, $ex[0], $ex[1], $ex[2], $ex[3]]);
            $cfu -= $ex[1];
        }
    }
}
echo "  " . count($studentIds) . " students (2 pending)\n";

// ============================================================
// INTERNSHIP OFFERS (12 — mix of open, draft, from companies + professors)
// ============================================================
$offersSeed = [
    // Company offers (open)
    [
        'poster_user_id' => $companyIds[0]['user_id'], 'type' => 'company',
        'title' => 'Cloud engineering intern',
        'description' => "Join Reply's cloud practice to work on real customer projects using AWS and Kubernetes. You'll design and deploy microservices, build CI/CD pipelines, and pair with senior engineers. This internship targets students strong in backend fundamentals who want to grow into cloud specialists.",
        'domain' => 'Cloud Computing', 'capacity' => 3, 'min_cfu' => 120, 'duration_months' => 6,
        'location' => 'Turin, Italy', 'is_remote' => 0, 'is_paid' => 1, 'stipend_monthly' => 800.00,
        'skills' => [
            ['AWS', 'required', 'intermediate'],
            ['Docker', 'required', 'intermediate'],
            ['Kubernetes', 'preferred', 'beginner'],
            ['CI/CD pipelines', 'required', 'beginner'],
            ['Linux system administration', 'preferred', 'intermediate'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $companyIds[1]['user_id'], 'type' => 'company',
        'title' => 'Java backend developer intern',
        'description' => "Engineering S.p.A. is looking for a backend intern to work on Spring-based microservices for enterprise clients. You will contribute to a large-scale system handling millions of transactions per day. Strong Java fundamentals and interest in distributed systems required.",
        'domain' => 'Software Engineering', 'capacity' => 2, 'min_cfu' => 130, 'duration_months' => 6,
        'location' => 'Rome, Italy', 'is_remote' => 1, 'is_paid' => 1, 'stipend_monthly' => 700.00,
        'skills' => [
            ['Java', 'required', 'advanced'],
            ['Spring Framework', 'required', 'intermediate'],
            ['REST API development', 'required', 'intermediate'],
            ['MySQL', 'preferred', 'intermediate'],
            ['Docker', 'preferred', 'beginner'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $companyIds[2]['user_id'], 'type' => 'company',
        'title' => 'Full-stack web developer',
        'description' => "Build customer-facing dashboards for public administration clients. Modern React frontend, Node.js API, PostgreSQL backend. Good for students who like end-to-end ownership.",
        'domain' => 'Web Development', 'capacity' => 2, 'min_cfu' => 100, 'duration_months' => 4,
        'location' => 'Milan, Italy', 'is_remote' => 1, 'is_paid' => 1, 'stipend_monthly' => 650.00,
        'skills' => [
            ['React', 'required', 'intermediate'],
            ['TypeScript', 'preferred', 'beginner'],
            ['Node.js', 'required', 'intermediate'],
            ['PostgreSQL', 'preferred', 'beginner'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $companyIds[3]['user_id'], 'type' => 'company',
        'title' => 'Data engineer intern (Palermo hub)',
        'description' => "NTT DATA's Palermo hub is expanding. You'll build data pipelines for banking clients, work with Kafka and Spark, and learn from a senior data engineering team.",
        'domain' => 'Data Science & Analytics', 'capacity' => 2, 'min_cfu' => 140, 'duration_months' => 6,
        'location' => 'Palermo, Italy', 'is_remote' => 0, 'is_paid' => 1, 'stipend_monthly' => 750.00,
        'skills' => [
            ['Python', 'required', 'advanced'],
            ['SQL', 'required', 'advanced'],
            ['Apache Spark', 'preferred', 'beginner'],
            ['Apache Kafka', 'preferred', 'beginner'],
            ['ETL processes', 'required', 'intermediate'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $companyIds[4]['user_id'], 'type' => 'company',
        'title' => 'Cloud security research intern',
        'description' => "Sysdig is opening a research-oriented internship focused on container security and runtime threat detection. Ideal for security-minded students who want to understand what production cloud attacks look like.",
        'domain' => 'Cybersecurity', 'capacity' => 1, 'min_cfu' => 140, 'duration_months' => 6,
        'location' => 'Remote', 'is_remote' => 1, 'is_paid' => 1, 'stipend_monthly' => 900.00,
        'skills' => [
            ['Cybersecurity', 'required', 'intermediate'],
            ['Linux system administration', 'required', 'advanced'],
            ['Docker', 'required', 'intermediate'],
            ['Python', 'preferred', 'intermediate'],
        ],
        'status' => 'open',
    ],
    // Research offers (open)
    [
        'poster_user_id' => $profIds[0]['user_id'], 'type' => 'research',
        'title' => 'DevSecOps pipeline research assistant',
        'description' => "Research internship on integrating SAST and DAST tools into GitHub Actions pipelines. You'll work with real vulnerable applications (PyGoat, WebGoat) and contribute to a research paper on false-positive triage in enterprise CI/CD.",
        'domain' => 'DevSecOps', 'capacity' => 1, 'min_cfu' => 130, 'duration_months' => 4,
        'location' => 'MIFT Department, UNIME', 'is_remote' => 0, 'is_paid' => 0, 'stipend_monthly' => null,
        'skills' => [
            ['DevSecOps', 'required', 'beginner'],
            ['SAST / static security testing', 'preferred', 'beginner'],
            ['Python', 'preferred', 'intermediate'],
            ['Git', 'required', 'intermediate'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $profIds[3]['user_id'], 'type' => 'research',
        'title' => 'Machine learning for physical systems',
        'description' => "Work with Prof. Finocchio's group on ML models for Ising machine simulations. You'll build datasets, train neural networks, and analyse convergence behaviour. Strong Python and math skills required.",
        'domain' => 'Artificial Intelligence', 'capacity' => 2, 'min_cfu' => 150, 'duration_months' => 6,
        'location' => 'MIFT Department, UNIME', 'is_remote' => 0, 'is_paid' => 1, 'stipend_monthly' => 400.00,
        'skills' => [
            ['Machine learning', 'required', 'intermediate'],
            ['Python', 'required', 'advanced'],
            ['Neural networks', 'preferred', 'intermediate'],
            ['Linear algebra', 'required', 'intermediate'],
            ['MATLAB', 'preferred', 'beginner'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $profIds[4]['user_id'], 'type' => 'research',
        'title' => 'Combinatorial optimisation for scheduling',
        'description' => "Research internship in the Mathematics department. You'll work on graph-based scheduling problems using ILP solvers and heuristic algorithms. Great for students who like theory + implementation.",
        'domain' => 'Algorithms', 'capacity' => 1, 'min_cfu' => 130, 'duration_months' => 4,
        'location' => 'Mathematics Department, UNIME', 'is_remote' => 0, 'is_paid' => 0, 'stipend_monthly' => null,
        'skills' => [
            ['Optimisation theory', 'required', 'intermediate'],
            ['Graph theory', 'required', 'intermediate'],
            ['Algorithms', 'required', 'advanced'],
            ['Python', 'preferred', 'intermediate'],
        ],
        'status' => 'open',
    ],
    [
        'poster_user_id' => $profIds[1]['user_id'], 'type' => 'research',
        'title' => 'Edge-computing orchestration research',
        'description' => "Extend Prof. Villari's edge-cloud continuum work. You'll help evaluate Kubernetes-based orchestration on constrained edge nodes and contribute to an open-source deployment tool.",
        'domain' => 'Cloud Computing', 'capacity' => 1, 'min_cfu' => 140, 'duration_months' => 6,
        'location' => 'MIFT Department, UNIME', 'is_remote' => 0, 'is_paid' => 0, 'stipend_monthly' => null,
        'skills' => [
            ['Kubernetes', 'required', 'intermediate'],
            ['Docker', 'required', 'advanced'],
            ['Distributed systems', 'required', 'intermediate'],
            ['Linux system administration', 'required', 'advanced'],
        ],
        'status' => 'open',
    ],
    // Drafts (pending admin review)
    [
        'poster_user_id' => $companyIds[0]['user_id'], 'type' => 'company',
        'title' => 'Frontend UX intern',
        'description' => "Design and prototype internal tools UX. Draft — pending coordinator review.",
        'domain' => 'Web Development', 'capacity' => 1, 'min_cfu' => 90, 'duration_months' => 3,
        'location' => 'Turin', 'is_remote' => 1, 'is_paid' => 0, 'stipend_monthly' => null,
        'skills' => [
            ['React', 'required', 'beginner'],
            ['CSS', 'required', 'intermediate'],
            ['HTML', 'required', 'intermediate'],
        ],
        'status' => 'draft',
    ],
    [
        'poster_user_id' => $profIds[2]['user_id'], 'type' => 'research',
        'title' => 'Network intrusion detection with ML',
        'description' => "Draft offer. Research on anomaly detection in network traffic.",
        'domain' => 'Cybersecurity', 'capacity' => 1, 'min_cfu' => 140, 'duration_months' => 5,
        'location' => 'MIFT', 'is_remote' => 0, 'is_paid' => 0, 'stipend_monthly' => null,
        'skills' => [
            ['Network security', 'required', 'intermediate'],
            ['Machine learning', 'required', 'intermediate'],
            ['Python', 'required', 'advanced'],
        ],
        'status' => 'draft',
    ],
];

$offerIds = [];
foreach ($offersSeed as $o) {
    $stmt = $db->prepare(
        "INSERT INTO internship_offers
         (posted_by_user_id, type, title, description, domain, capacity, min_cfu, duration_months,
          location, is_remote, is_paid, stipend_monthly, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $o['poster_user_id'], $o['type'], $o['title'], $o['description'], $o['domain'],
        $o['capacity'], $o['min_cfu'], $o['duration_months'], $o['location'], $o['is_remote'],
        $o['is_paid'], $o['stipend_monthly'], $o['status']
    ]);
    $offerId = $db->lastInsertId();
    $offerIds[] = $offerId;

    foreach ($o['skills'] as $sk) {
        [$name, $importance, $minProf] = $sk;
        $sid = $skillId($name);
        if ($sid) {
            $db->prepare("INSERT INTO offer_skills (offer_id, skill_id, importance, minimum_proficiency) VALUES (?, ?, ?, ?)")
               ->execute([$offerId, $sid, $importance, $minProf]);
        }
    }
}
echo "  " . count($offerIds) . " internship offers (10 open, 2 draft)\n";

// ============================================================
// SAMPLE APPLICATIONS (spread across students & offers)
// ============================================================
$applicationsSeed = [
    // studentId index, offerId index, status
    [0, 0, 'pending'],   // Luca → Reply cloud
    [0, 1, 'pending'],   // Luca → Engineering
    [1, 3, 'accepted'],  // Giulia → NTT data engineer
    [1, 6, 'pending'],   // Giulia → Finocchio ML
    [2, 1, 'pending'],   // Marco → Engineering
    [2, 8, 'pending'],   // Marco → edge computing
    [3, 2, 'pending'],   // Sofia → full-stack
    [5, 5, 'pending'],   // Mahesh → DevSecOps research (Distefano)
    [5, 3, 'pending'],   // Mahesh → NTT data
    [6, 3, 'pending'],   // Chiara → NTT data
    [7, 4, 'pending'],   // Davide → Sysdig
    [7, 5, 'pending'],   // Davide → DevSecOps
    [8, 2, 'accepted'],  // Elena → full-stack
    [9, 1, 'rejected'],  // Francesco → Engineering (rejected)
];

foreach ($applicationsSeed as $a) {
    [$si, $oi, $status] = $a;
    if (!isset($studentIds[$si]) || !isset($offerIds[$oi])) continue;
    $stmt = $db->prepare(
        "INSERT INTO applications (student_id, offer_id, status, cover_letter, applied_at)
         VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 14) DAY))"
    );
    $stmt->execute([
        $studentIds[$si]['id'],
        $offerIds[$oi],
        $status,
        "I'm interested in this position because it aligns with my recent coursework and personal projects."
    ]);
}
echo "  " . count($applicationsSeed) . " sample applications\n";

// ============================================================
// SAMPLE NOTIFICATIONS (a few for the coordinator to test the bell)
// ============================================================
$db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (1, ?, ?, 'action', ?)")
   ->execute(['2 pending student registrations', 'New students are awaiting your approval.', BASE_URL . '/admin/pending-registrations.php']);

$db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (1, ?, ?, 'action', ?)")
   ->execute(['2 draft offers pending review', 'New internship offers need approval before going live.', BASE_URL . '/admin/offers-review.php']);

echo "\n✓ Seed complete.\n";
echo "\nLog in with any of these:\n";
echo "  Coordinator: coordinator@unime.it / coordinator123\n";
echo "  Professor:   distefano@unime.it / 123\n";
echo "  Company:     reply@example.com / 123\n";
echo "  Student:     mahesh.nair@studenti.unime.it / 123\n";
echo "\nAll users share password '123' (except the coordinator).\n";