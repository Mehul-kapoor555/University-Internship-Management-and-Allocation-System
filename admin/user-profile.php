<?php
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/engine_runner.php';
requireRole(['coordinator']);

$me = currentUser();
$activePage = 'users';
$message = '';
$error = '';

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($userId <= 0) { header('Location: ' . BASE_URL . '/admin/users.php'); exit; }

// ---- Load the target user ---------------------------------------------------
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$u = $stmt->fetch();
if (!$u) { die('User not found.'); }

// Load the role-specific profile row.
$roleProfile = null;
if ($u['role'] === 'student') {
    $stmt = $db->prepare("SELECT * FROM students WHERE user_id = ?");
} elseif ($u['role'] === 'professor') {
    $stmt = $db->prepare("SELECT * FROM professors WHERE user_id = ?");
} elseif ($u['role'] === 'company') {
    $stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
} else {
    $stmt = $db->prepare("SELECT * FROM coordinators WHERE user_id = ?");
}
$stmt->execute([$userId]);
$roleProfile = $stmt->fetch();

// ============================================================================
// ACTIONS
// ============================================================================
$action = $_POST['action'] ?? '';

// ---- DISABLE / ENABLE -------------------------------------------------------
if ($action === 'toggle_active') {
    $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$userId]);
    header('Location: ' . BASE_URL . '/admin/user-profile.php?id=' . $userId
           . '&msg=' . ($u['is_active'] ? 'disabled' : 'enabled'));
    exit;
}

// ---- APPROVE a pending student ---------------------------------------------
if ($action === 'approve' && $u['role'] === 'student') {
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$userId]);
        $db->prepare("UPDATE students SET registration_status = 'approved',
                      approved_by_user_id = ?, approved_at = NOW() WHERE user_id = ?")
           ->execute([$me['id'], $userId]);
        notify($db, $userId, 'Your registration has been approved',
               'You may now log in and use the system.', 'success', BASE_URL . '/profile.php');
        $db->commit();
        header('Location: ' . BASE_URL . '/admin/user-profile.php?id=' . $userId . '&msg=approved');
        exit;
    } catch (Throwable $e) {
        $db->rollBack();
        $error = 'Could not approve: ' . $e->getMessage();
    }
}

// ---- REJECT a pending student ----------------------------------------------
if ($action === 'reject' && $u['role'] === 'student') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    $db->prepare("UPDATE students SET registration_status = 'rejected', rejection_reason = ?,
                  approved_by_user_id = ?, approved_at = NOW() WHERE user_id = ?")
       ->execute([$reason, $me['id'], $userId]);
    header('Location: ' . BASE_URL . '/admin/user-profile.php?id=' . $userId . '&msg=rejected');
    exit;
}

// ---- SAVE profile edits -----------------------------------------------------
if ($action === 'save') {
    $db->beginTransaction();
    try {
        // Common user fields
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new Exception('Name and email cannot be empty.');
        }
        // email uniqueness (excluding this user)
        $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $chk->execute([$email, $userId]);
        if ($chk->fetch()) throw new Exception('That email is already used by another account.');

        $db->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?")
           ->execute([$firstName, $lastName, $email, $userId]);

        $reRunNlpForStudentId = null;

        if ($u['role'] === 'student' && $roleProfile) {
            $program    = trim($_POST['program'] ?? '');
            $enrollYear = (int)($_POST['enrollment_year'] ?? 0);
            $gpa        = $_POST['gpa'] !== '' ? (float)$_POST['gpa'] : null;
            $competency = trim($_POST['competency_description'] ?? '');

            // --- Rebuild academic_records from the submitted exam rows -------
            // The exam rows arrive as parallel arrays. total_cfu is the SERVER's
            // authoritative sum of the submitted CFU values (the JS figure in the
            // form is only for live display and is never trusted).
            $examNames  = $_POST['exam_name']  ?? [];
            $examCfu    = $_POST['exam_cfu']   ?? [];
            $examGrade  = $_POST['exam_grade'] ?? [];
            $examDate   = $_POST['exam_date']  ?? [];

            $studentId = (int)$roleProfile['id'];
            $db->prepare("DELETE FROM academic_records WHERE student_id = ?")->execute([$studentId]);

            $insExam = $db->prepare(
                "INSERT INTO academic_records
                   (student_id, exam_name, cfu, grade, exam_date, verified_by_user_id, verified_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $totalCfu = 0;
            for ($i = 0; $i < count($examNames); $i++) {
                $name = trim($examNames[$i] ?? '');
                if ($name === '') continue;                 // skip blank rows
                $cfu   = max(0, (int)($examCfu[$i] ?? 0));
                $grade = trim($examGrade[$i] ?? '') ?: null;
                $date  = trim($examDate[$i] ?? '') ?: null;
                $insExam->execute([$studentId, $name, $cfu, $grade, $date, $me['id']]);
                $totalCfu += $cfu;
            }

            $db->prepare(
                "UPDATE students SET program = ?, enrollment_year = ?, total_cfu = ?,
                        gpa = ?, competency_description = ? WHERE id = ?"
            )->execute([$program, $enrollYear, $totalCfu, $gpa, $competency, $studentId]);

            $reRunNlpForStudentId = $studentId;

        } elseif ($u['role'] === 'professor' && $roleProfile) {
            $db->prepare(
                "UPDATE professors SET department = ?, expertise_description = ?,
                        supervision_capacity = ?, is_available = ? WHERE user_id = ?"
            )->execute([
                trim($_POST['department'] ?? ''),
                trim($_POST['expertise_description'] ?? ''),
                max(0, (int)($_POST['supervision_capacity'] ?? 0)),
                isset($_POST['is_available']) ? 1 : 0,
                $userId,
            ]);

        } elseif ($u['role'] === 'company' && $roleProfile) {
            $db->prepare(
                "UPDATE companies SET company_name = ?, industry = ?, website = ?,
                        address = ?, contact_phone = ? WHERE user_id = ?"
            )->execute([
                trim($_POST['company_name'] ?? ''),
                trim($_POST['industry'] ?? '') ?: null,
                trim($_POST['website'] ?? '') ?: null,
                trim($_POST['address'] ?? '') ?: null,
                trim($_POST['contact_phone'] ?? '') ?: null,
                $userId,
            ]);

        } elseif ($u['role'] === 'coordinator' && $roleProfile) {
            $db->prepare("UPDATE coordinators SET department = ? WHERE user_id = ?")
               ->execute([trim($_POST['department'] ?? ''), $userId]);
        }

        $db->commit();

        // --- Re-run the recommendation engine (NLP) for a saved student ------
        // Done AFTER commit so the engine reads the updated CFU / competency text.
        $nlpNote = '';
        if ($reRunNlpForStudentId !== null) {
            $res = EngineRunner::run('recommendation', ['student-id' => $reRunNlpForStudentId]);
            $nlpNote = $res['success']
                ? ' Recommendations refreshed (' . ($res['data']['recommendations_written'] ?? 0) . ' eligible offers).'
                : ' Note: recommendation refresh failed — ' . $res['error'];
        }

        header('Location: ' . BASE_URL . '/admin/user-profile.php?id=' . $userId
               . '&msg=saved' . ($nlpNote ? '&nlp=' . urlencode(trim($nlpNote)) : ''));
        exit;

    } catch (Throwable $e) {
        $db->rollBack();
        $error = 'Could not save: ' . $e->getMessage();
    }
}

// ---- Flash messages from redirects -----------------------------------------
$flash = [
    'saved'    => 'Profile saved.',
    'approved' => 'Registration approved.',
    'rejected' => 'Registration rejected.',
    'disabled' => 'Account disabled.',
    'enabled'  => 'Account enabled.',
][$_GET['msg'] ?? ''] ?? '';
if ($flash && isset($_GET['nlp'])) $flash .= ' ' . $_GET['nlp'];

// ---- Reload fresh copies after any change ----------------------------------
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$u = $stmt->fetch();

if ($u['role'] === 'student') {
    $stmt = $db->prepare("SELECT * FROM students WHERE user_id = ?");
    $stmt->execute([$userId]);
    $roleProfile = $stmt->fetch();
    $academicRecords = [];
    if ($roleProfile) {
        $stmt = $db->prepare("SELECT * FROM academic_records WHERE student_id = ? ORDER BY exam_date DESC, id DESC");
        $stmt->execute([$roleProfile['id']]);
        $academicRecords = $stmt->fetchAll();
    }
}

$isPending = ($u['role'] === 'student' && ($roleProfile['registration_status'] ?? '') === 'pending');
$editMode  = isset($_GET['edit']);

require '../includes/header.php';
?>

<div class="page-header">
    <h1><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></h1>
    <p>
        <?= htmlspecialchars(ucfirst($u['role'])) ?> ·
        <span class="badge badge-<?= $u['is_active'] ? 'open' : 'closed' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span>
        <?php if ($isPending): ?><span class="badge badge-pending">Pending approval</span><?php endif; ?>
    </p>
</div>

<p><a href="<?= BASE_URL ?>/admin/users.php">← Back to users</a></p>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$editMode): ?>
    <!-- ===================== READ-ONLY VIEW ===================== -->
    <div class="card">
        <h3>Account</h3>
        <div class="field-row"><span>Name</span><span><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></span></div>
        <div class="field-row"><span>Email</span><span><?= htmlspecialchars($u['email']) ?></span></div>
        <div class="field-row"><span>Role</span><span><?= htmlspecialchars(ucfirst($u['role'])) ?></span></div>
    </div>

    <?php if ($u['role'] === 'student' && $roleProfile): ?>
        <div class="card">
            <h3>Identity</h3>
            <div class="field-row"><span>Matricola</span><span><?= htmlspecialchars($roleProfile['matricola']) ?></span></div>
            <div class="field-row"><span>Program</span><span><?= htmlspecialchars($roleProfile['program']) ?></span></div>
            <div class="field-row"><span>Enrollment year</span><span><?= htmlspecialchars($roleProfile['enrollment_year']) ?></span></div>
            <div class="field-row"><span>GPA</span><span><?= $roleProfile['gpa'] !== null ? htmlspecialchars($roleProfile['gpa']) : '—' ?></span></div>
        </div>
        <div class="card">
            <h3>Academic record</h3>
            <div class="field-row"><span>Total CFU</span><span><strong><?= (int)$roleProfile['total_cfu'] ?></strong></span></div>
            <?php if (empty($academicRecords)): ?>
                <p style="font-size:13px;color:var(--slate-light);margin-top:12px;">No exams on file.</p>
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
        </div>
        <?php if (!empty($roleProfile['competency_description'])): ?>
            <div class="card">
                <h3>Competency description</h3>
                <p><?= nl2br(htmlspecialchars($roleProfile['competency_description'])) ?></p>
            </div>
        <?php endif; ?>

    <?php elseif ($u['role'] === 'professor' && $roleProfile): ?>
        <div class="card">
            <h3>Professor</h3>
            <div class="field-row"><span>Department</span><span><?= htmlspecialchars($roleProfile['department']) ?></span></div>
            <div class="field-row"><span>Supervision capacity</span><span><?= (int)$roleProfile['supervision_capacity'] ?></span></div>
            <div class="field-row"><span>Current load</span><span><?= (int)$roleProfile['current_load'] ?></span></div>
            <div class="field-row"><span>Available</span><span><?= $roleProfile['is_available'] ? 'Yes' : 'No' ?></span></div>
            <?php if (!empty($roleProfile['expertise_description'])): ?>
                <p style="margin-top:12px;"><strong>Expertise:</strong> <?= htmlspecialchars($roleProfile['expertise_description']) ?></p>
            <?php endif; ?>
        </div>

    <?php elseif ($u['role'] === 'company' && $roleProfile): ?>
        <div class="card">
            <h3>Company</h3>
            <div class="field-row"><span>Company name</span><span><?= htmlspecialchars($roleProfile['company_name']) ?></span></div>
            <div class="field-row"><span>Industry</span><span><?= htmlspecialchars($roleProfile['industry'] ?? '—') ?></span></div>
            <div class="field-row"><span>Website</span><span><?= htmlspecialchars($roleProfile['website'] ?? '—') ?></span></div>
            <div class="field-row"><span>Contact phone</span><span><?= htmlspecialchars($roleProfile['contact_phone'] ?? '—') ?></span></div>
        </div>

    <?php elseif ($u['role'] === 'coordinator' && $roleProfile): ?>
        <div class="card">
            <h3>Coordinator</h3>
            <div class="field-row"><span>Department</span><span><?= htmlspecialchars($roleProfile['department']) ?></span></div>
        </div>
    <?php endif; ?>

    <!-- Action buttons -->
    <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= $userId ?>&edit=1" class="btn btn-primary" style="width:auto;">Edit</a>

        <?php if ($isPending): ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-primary" style="width:auto;background:#16a34a;"
                        onclick="return confirm('Approve <?= htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])) ?>?');">
                    Approve
                </button>
            </form>
            <form method="post" style="margin:0;"
                  onsubmit="return confirm('Reject this registration?');">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-secondary" style="width:auto;">Reject</button>
            </form>
        <?php else: ?>
            <form method="post" style="margin:0;"
                  onsubmit="return confirm('<?= $u['is_active'] ? 'Disable' : 'Enable' ?> <?= htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])) ?>?');">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="action" value="toggle_active">
                <button type="submit" class="btn btn-secondary" style="width:auto;">
                    <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ===================== EDIT MODE ===================== -->
    <form method="post" id="editForm">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <input type="hidden" name="action" value="save">

        <div class="card">
            <h3>Account</h3>
            <label>First name</label>
            <input type="text" name="first_name" value="<?= htmlspecialchars($u['first_name']) ?>" required>
            <label>Last name</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($u['last_name']) ?>" required>
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>
        </div>

        <?php if ($u['role'] === 'student' && $roleProfile): ?>
            <div class="card">
                <h3>Identity</h3>
                <div class="field-row"><span>Matricola</span><span><?= htmlspecialchars($roleProfile['matricola']) ?> <em style="color:var(--slate-light);">(fixed)</em></span></div>
                <label>Program</label>
                <input type="text" name="program" value="<?= htmlspecialchars($roleProfile['program']) ?>">
                <label>Enrollment year</label>
                <input type="number" name="enrollment_year" value="<?= htmlspecialchars($roleProfile['enrollment_year']) ?>" min="2000" max="2100">
                <label>GPA (optional)</label>
                <input type="number" step="0.01" name="gpa" value="<?= $roleProfile['gpa'] !== null ? htmlspecialchars($roleProfile['gpa']) : '' ?>">
            </div>

            <div class="card">
                <h3>Exams</h3>
                <p style="font-size:13px;color:var(--slate-light);">
                    Add each passed exam. Total CFU is calculated automatically.
                </p>
                <div class="field-row" style="font-weight:bold;">
                    <span>Total CFU</span>
                    <span><input type="text" id="totalCfu" value="<?= (int)$roleProfile['total_cfu'] ?>" readonly
                                 style="width:80px;text-align:right;background:#f3f4f6;"></span>
                </div>

                <table style="margin-top:12px;" id="examTable">
                    <thead><tr><th>Exam name</th><th style="width:90px;">CFU</th><th style="width:90px;">Grade</th><th style="width:150px;">Date</th><th style="width:40px;"></th></tr></thead>
                    <tbody id="examBody">
                    <?php
                    $rowsToShow = !empty($academicRecords) ? $academicRecords : [];
                    foreach ($rowsToShow as $r): ?>
                        <tr>
                            <td><input type="text" name="exam_name[]" value="<?= htmlspecialchars($r['exam_name']) ?>"></td>
                            <td><input type="number" name="exam_cfu[]" class="cfuInput" value="<?= (int)$r['cfu'] ?>" min="0"></td>
                            <td><input type="text" name="exam_grade[]" value="<?= htmlspecialchars($r['grade'] ?? '') ?>"></td>
                            <td><input type="date" name="exam_date[]" value="<?= htmlspecialchars($r['exam_date'] ?? '') ?>"></td>
                            <td><button type="button" class="btn btn-secondary removeRow" style="padding:2px 8px;">✕</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" id="addRow" class="btn btn-secondary" style="width:auto;margin-top:10px;">+ Add exam</button>
            </div>

            <div class="card">
                <h3>Competency description</h3>
                <p style="font-size:13px;color:var(--slate-light);">Free-text used by the recommendation engine's semantic matching.</p>
                <textarea name="competency_description" rows="4"><?= htmlspecialchars($roleProfile['competency_description'] ?? '') ?></textarea>
            </div>

        <?php elseif ($u['role'] === 'professor' && $roleProfile): ?>
            <div class="card">
                <h3>Professor</h3>
                <label>Department</label>
                <input type="text" name="department" value="<?= htmlspecialchars($roleProfile['department']) ?>">
                <label>Expertise description</label>
                <textarea name="expertise_description" rows="4"><?= htmlspecialchars($roleProfile['expertise_description'] ?? '') ?></textarea>
                <label>Supervision capacity</label>
                <input type="number" name="supervision_capacity" value="<?= (int)$roleProfile['supervision_capacity'] ?>" min="0">
                <label style="display:flex;align-items:center;gap:8px;margin-top:10px;">
                    <input type="checkbox" name="is_available" value="1" <?= $roleProfile['is_available'] ? 'checked' : '' ?> style="width:auto;">
                    Available to supervise
                </label>
            </div>

        <?php elseif ($u['role'] === 'company' && $roleProfile): ?>
            <div class="card">
                <h3>Company</h3>
                <label>Company name</label>
                <input type="text" name="company_name" value="<?= htmlspecialchars($roleProfile['company_name']) ?>">
                <label>Industry</label>
                <input type="text" name="industry" value="<?= htmlspecialchars($roleProfile['industry'] ?? '') ?>">
                <label>Website</label>
                <input type="text" name="website" value="<?= htmlspecialchars($roleProfile['website'] ?? '') ?>">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($roleProfile['address'] ?? '') ?>">
                <label>Contact phone</label>
                <input type="text" name="contact_phone" value="<?= htmlspecialchars($roleProfile['contact_phone'] ?? '') ?>">
            </div>

        <?php elseif ($u['role'] === 'coordinator' && $roleProfile): ?>
            <div class="card">
                <h3>Coordinator</h3>
                <label>Department</label>
                <input type="text" name="department" value="<?= htmlspecialchars($roleProfile['department']) ?>">
            </div>
        <?php endif; ?>

        <div class="card" style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary" style="width:auto;"
                    onclick="return confirm('Save changes to this profile?');">Save</button>
            <a href="<?= BASE_URL ?>/admin/user-profile.php?id=<?= $userId ?>" class="btn btn-secondary" style="width:auto;">Cancel</a>
        </div>
    </form>

    <?php if ($u['role'] === 'student'): ?>
    <script>
    (function () {
        const body = document.getElementById('examBody');
        const totalField = document.getElementById('totalCfu');

        function recalc() {
            let sum = 0;
            body.querySelectorAll('.cfuInput').forEach(function (inp) {
                const v = parseInt(inp.value, 10);
                if (!isNaN(v) && v > 0) sum += v;
            });
            totalField.value = sum;
        }

        function wireRow(tr) {
            const cfu = tr.querySelector('.cfuInput');
            if (cfu) cfu.addEventListener('input', recalc);
            const rm = tr.querySelector('.removeRow');
            if (rm) rm.addEventListener('click', function () { tr.remove(); recalc(); });
        }

        body.querySelectorAll('tr').forEach(wireRow);

        document.getElementById('addRow').addEventListener('click', function () {
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="text" name="exam_name[]"></td>' +
                '<td><input type="number" name="exam_cfu[]" class="cfuInput" min="0"></td>' +
                '<td><input type="text" name="exam_grade[]"></td>' +
                '<td><input type="date" name="exam_date[]"></td>' +
                '<td><button type="button" class="btn btn-secondary removeRow" style="padding:2px 8px;">✕</button></td>';
            body.appendChild(tr);
            wireRow(tr);
        });

        recalc();
    })();
    </script>
    <?php endif; ?>

<?php endif; ?>

<?php require '../includes/footer.php'; ?>
