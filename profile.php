<?php
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/engine_runner.php';
requireLogin();

$user = currentUser();
$activePage = 'profile';
$message = '';

if ($user['role'] === 'student') {
    $stmt = $db->prepare("SELECT * FROM students WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
        $stmt = $db->prepare(
            "UPDATE students SET competency_description = ? WHERE user_id = ?"
        );
        $stmt->execute([trim($_POST['competency_description']), $user['id']]);

        $studentId = $profile['id'];
        $db->prepare("DELETE FROM student_skills WHERE student_id = ?")->execute([$studentId]);
        if (isset($_POST['skills']) && is_array($_POST['skills'])) {
            $ins = $db->prepare(
                "INSERT INTO student_skills (student_id, skill_id, proficiency) VALUES (?, ?, ?)"
            );
            foreach ($_POST['skills'] as $skillId => $data) {
                $prof = in_array($data['proficiency'] ?? '', ['beginner','intermediate','advanced'])
                    ? $data['proficiency'] : 'beginner';
                $ins->execute([$studentId, (int)$skillId, $prof]);
            }
        }

        // Auto-refresh recommendations after profile save (silent — errors don't block the user)
        $engineResult = EngineRunner::run('recommendation', ['student-id' => $studentId]);
        if ($engineResult['success']) {
            $message = 'Profile updated. Recommendations refreshed automatically.';
        } else {
            $message = 'Profile updated. (Recommendation refresh failed — you can retry from the Recommendations page.)';
        }

        $stmt = $db->prepare("SELECT * FROM students WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
    }

} elseif ($user['role'] === 'professor') {
    $stmt = $db->prepare("SELECT * FROM professors WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
        $stmt = $db->prepare(
            "UPDATE professors SET department = ?, expertise_description = ?, supervision_capacity = ? WHERE user_id = ?"
        );
        $stmt->execute([trim($_POST['department']), trim($_POST['expertise_description']), (int)$_POST['supervision_capacity'], $user['id']]);

        $profId = $profile['id'];
        $db->prepare("DELETE FROM professor_skills WHERE professor_id = ?")->execute([$profId]);
        if (isset($_POST['skills']) && is_array($_POST['skills'])) {
            $ins = $db->prepare(
                "INSERT INTO professor_skills (professor_id, skill_id, proficiency) VALUES (?, ?, ?)"
            );
            foreach ($_POST['skills'] as $skillId => $data) {
                $prof = in_array($data['proficiency'] ?? '', ['beginner','intermediate','advanced'])
                    ? $data['proficiency'] : 'beginner';
                $ins->execute([$profId, (int)$skillId, $prof]);
            }
        }

        $message = 'Profile updated.';
        $stmt = $db->prepare("SELECT * FROM professors WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
    }

} elseif ($user['role'] === 'company') {
    $stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
        $stmt = $db->prepare(
            "UPDATE companies SET company_name = ?, industry = ?, website = ?, description = ? WHERE user_id = ?"
        );
        $stmt->execute([trim($_POST['company_name']), trim($_POST['industry']), trim($_POST['website']), trim($_POST['description']), $user['id']]);
        $message = 'Profile updated.';
        $stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
    }

} else {
    $stmt = $db->prepare("SELECT * FROM coordinators WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
}

$academicRecords = [];
if ($user['role'] === 'student' && $profile) {
    $stmt = $db->prepare("SELECT * FROM academic_records WHERE student_id = ? ORDER BY exam_date DESC, id DESC");
    $stmt->execute([$profile['id']]);
    $academicRecords = $stmt->fetchAll();
}

require 'includes/header.php';
?>

<div class="page-header">
    <h1>My profile</h1>
    <p><?= htmlspecialchars($user['name']) ?> · <?= htmlspecialchars(ucfirst($user['role'])) ?></p>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<?php if (!$profile): ?>
    <div class="alert alert-error">Your <?= htmlspecialchars($user['role']) ?> profile record is missing — contact the coordinator's office.</div>

<?php elseif ($user['role'] === 'student'): ?>
    <?php
    $existingSkills = [];
    $stmt = $db->prepare(
        "SELECT ss.skill_id AS id, s.name, ss.proficiency
         FROM student_skills ss JOIN skills s ON s.id = ss.skill_id
         WHERE ss.student_id = ?"
    );
    $stmt->execute([$profile['id']]);
    $existingSkills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <div class="card">
        <h3>Identity</h3>
        <div class="field-row"><span>Matricola</span><span><?= htmlspecialchars($profile['matricola']) ?></span></div>
        <div class="field-row"><span>Program</span><span><?= htmlspecialchars($profile['program']) ?></span></div>
        <div class="field-row"><span>Enrollment year</span><span><?= htmlspecialchars($profile['enrollment_year']) ?></span></div>
        <p style="font-size:12px;color:var(--slate-light);margin-top:10px;">
            Identity fields are maintained by the registrar. Contact the coordinator's office for corrections.
        </p>
    </div>

    <div class="card">
        <h3>Academic record</h3>
        <div class="field-row"><span>Total CFU (verified)</span><span><?= (int)$profile['total_cfu'] ?></span></div>
        <?php if (empty($academicRecords)): ?>
            <p style="font-size:13px;color:var(--slate-light);margin-top:12px;">No exam records on file yet.</p>
        <?php else: ?>
            <table style="margin-top:12px;">
                <thead><tr><th>Exam</th><th>CFU</th><th>Grade</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($academicRecords as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['exam_name']) ?></td>
                        <td><?= (int)$r['cfu'] ?></td>
                        <td><?= htmlspecialchars($r['grade'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['exam_date'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p style="font-size:12px;color:var(--slate-light);margin-top:10px;">
            Academic records are entered and verified by the coordinator's office. Contact them if corrections are needed.
        </p>
    </div>

    <form method="POST" class="card form-narrow">
        <h3>Editable fields</h3>

        <label for="competency_description">Competency description</label>
        <textarea id="competency_description" name="competency_description" placeholder="Describe your background and interests. Recommendations use this text for semantic matching."><?= htmlspecialchars($profile['competency_description'] ?? '') ?></textarea>

        <label>My skills</label>
        <div class="skill-picker"
             data-endpoint="<?= BASE_URL ?>/includes/skill-search.php"
             data-input-name="skills"
             data-show-proficiency="true"
             data-show-importance="false"
             data-existing='<?= htmlspecialchars(json_encode($existingSkills)) ?>'>
        </div>

        <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Save (updates recommendations)</button>
    </form>
    <script src="<?= BASE_URL ?>/assets/js/skill-picker.js"></script>

<?php elseif ($user['role'] === 'professor'): ?>
    <?php
    $existingSkills = [];
    $stmt = $db->prepare(
        "SELECT ps.skill_id AS id, s.name, ps.proficiency
         FROM professor_skills ps JOIN skills s ON s.id = ps.skill_id
         WHERE ps.professor_id = ?"
    );
    $stmt->execute([$profile['id']]);
    $existingSkills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <form method="POST" class="card form-narrow">
        <label for="department">Department</label>
        <input type="text" id="department" name="department" value="<?= htmlspecialchars($profile['department']) ?>">

        <label for="expertise_description">Expertise description</label>
        <textarea id="expertise_description" name="expertise_description"><?= htmlspecialchars($profile['expertise_description'] ?? '') ?></textarea>

        <label for="supervision_capacity">Supervision capacity</label>
        <input type="number" id="supervision_capacity" name="supervision_capacity" value="<?= (int)$profile['supervision_capacity'] ?>">

        <label>Areas of expertise</label>
        <div class="skill-picker"
             data-endpoint="<?= BASE_URL ?>/includes/skill-search.php"
             data-input-name="skills"
             data-show-proficiency="true"
             data-show-importance="false"
             data-existing='<?= htmlspecialchars(json_encode($existingSkills)) ?>'>
        </div>

        <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Save</button>
    </form>
    <script src="<?= BASE_URL ?>/assets/js/skill-picker.js"></script>

<?php elseif ($user['role'] === 'company'): ?>
    <form method="POST" class="card form-narrow">
        <label for="company_name">Company name</label>
        <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($profile['company_name']) ?>">

        <label for="industry">Industry</label>
        <input type="text" id="industry" name="industry" value="<?= htmlspecialchars($profile['industry'] ?? '') ?>">

        <label for="website">Website</label>
        <input type="text" id="website" name="website" value="<?= htmlspecialchars($profile['website'] ?? '') ?>">

        <label for="description">Description</label>
        <textarea id="description" name="description"><?= htmlspecialchars($profile['description'] ?? '') ?></textarea>

        <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Save</button>
    </form>

<?php else: ?>
    <div class="card form-narrow">
        <div class="field-row"><span>Department</span><span><?= htmlspecialchars($profile['department']) ?></span></div>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>