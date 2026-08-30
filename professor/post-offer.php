<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['professor']);

$user = currentUser();
$activePage = 'post-offer';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $domain = trim($_POST['domain']);
    $capacity = (int)$_POST['capacity'];
    $minCfu = $_POST['min_cfu'] !== '' ? (int)$_POST['min_cfu'] : null;
    $requiredProgram = trim($_POST['required_program']);
    $durationMonths = (int)$_POST['duration_months'];
    $isPaid = isset($_POST['is_paid']) ? 1 : 0;
    $stipend = $_POST['stipend_monthly'] !== '' ? (float)$_POST['stipend_monthly'] : null;
    $contactEmail = trim($_POST['contact_email']);
    $deadline = $_POST['application_deadline'] !== '' ? $_POST['application_deadline'] : null;

    if ($title === '' || $description === '') {
        $error = 'Title and description are required.';
    } else {
        $stmt = $db->prepare(
            "INSERT INTO internship_offers
             (posted_by_user_id, type, title, description, domain, capacity, min_cfu,
              required_program, duration_months, is_paid, stipend_monthly,
              contact_email, application_deadline, status)
             VALUES (?, 'research', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
        );
        $stmt->execute([
            $user['id'], $title, $description, $domain, $capacity, $minCfu,
            $requiredProgram, $durationMonths, $isPaid, $stipend,
            $contactEmail, $deadline
        ]);
        $offerId = $db->lastInsertId();

        // Save offer skills
        if (isset($_POST['offer_skills']) && is_array($_POST['offer_skills'])) {
            $ins = $db->prepare(
                "INSERT INTO offer_skills (offer_id, skill_id, importance, minimum_proficiency)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($_POST['offer_skills'] as $skillId => $data) {
                $importance = in_array($data['importance'] ?? '', ['required','preferred'])
                    ? $data['importance'] : 'required';
                $minProf = in_array($data['minimum_proficiency'] ?? '', ['beginner','intermediate','advanced'])
                    ? $data['minimum_proficiency'] : 'beginner';
                $ins->execute([$offerId, (int)$skillId, $importance, $minProf]);
            }
        }

        header('Location: ' . BASE_URL . '/professor/my-offers.php?posted=1');
        exit;
    }
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Post a research offer</h1>
    <p>New offers go to the coordinator for review before appearing publicly.</p>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" class="card form-narrow">
    <label for="title">Title</label>
    <input type="text" id="title" name="title" required>

    <label for="description">Description</label>
    <textarea id="description" name="description" required></textarea>

    <label for="domain">Domain</label>
    <input type="text" id="domain" name="domain" placeholder="e.g. Machine Learning">

    <label for="capacity">Capacity (students)</label>
    <input type="number" id="capacity" name="capacity" value="1" min="1">

    <label for="min_cfu">Minimum CFU</label>
    <input type="number" id="min_cfu" name="min_cfu">

    <label for="required_program">Required program</label>
    <input type="text" id="required_program" name="required_program" placeholder="Leave blank for any">

    <label for="duration_months">Duration (months)</label>
    <input type="number" id="duration_months" name="duration_months" value="3" min="1">

    <label><input type="checkbox" name="is_paid" style="width:auto;display:inline-block;margin-right:6px;"> Paid position</label>

    <label for="stipend_monthly">Monthly stipend (€, optional)</label>
    <input type="number" id="stipend_monthly" name="stipend_monthly" step="0.01" placeholder="e.g. 500.00">

    <label for="contact_email">Contact email (if different from your account)</label>
    <input type="email" id="contact_email" name="contact_email">

    <label for="application_deadline">Application deadline</label>
    <input type="date" id="application_deadline" name="application_deadline">

    <label>Required skills</label>
    <div class="skill-picker"
         data-endpoint="<?= BASE_URL ?>/includes/skill-search.php"
         data-input-name="offer_skills"
         data-show-proficiency="false"
         data-show-importance="true"
         data-existing='[]'>
    </div>

    <button type="submit" class="btn btn-primary" style="width:auto;margin-top:16px;">Submit for review</button>
</form>
<script src="<?= BASE_URL ?>/assets/js/skill-picker.js"></script>

<?php require '../includes/footer.php'; ?>