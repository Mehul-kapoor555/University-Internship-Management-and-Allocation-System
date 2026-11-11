<?php
require '../includes/db.php';   // also pulls in includes/functions.php
require '../includes/auth.php';
requireRole(['company']);

$user = currentUser();
// Posting no longer has its own menu entry, so keep "My offers" highlighted
// while the form is open — that is the section this page belongs to.
$activePage = 'my-offers';
$error = '';
$old = $_POST;

// Offers carry no deadline of their own. Applying is governed by the cycle:
// students can apply while the coordinator has applications open, and cannot
// once they are closed. A per-offer date would be a second, competing answer to
// a question the cycle already settles.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $capacity = max(1, (int)($_POST['capacity'] ?? 1));
    $minCfu = ($_POST['min_cfu'] ?? '') !== '' ? (int)$_POST['min_cfu'] : null;
    $requiredProgram = trim($_POST['required_program'] ?? '');
    $durationMonths = max(1, (int)($_POST['duration_months'] ?? 3));
    $location = trim($_POST['location'] ?? '');
    $isRemote = isset($_POST['is_remote']) ? 1 : 0;
    $isPaid = isset($_POST['is_paid']) ? 1 : 0;

    // The stipend only exists for a paid position. If the box is unticked, any
    // amount that arrived is discarded rather than stored, so an unpaid offer
    // can never carry a stray figure from a half-filled form.
    $stipend = null;
    if ($isPaid && ($_POST['stipend_monthly'] ?? '') !== '') {
        $stipend = (float)$_POST['stipend_monthly'];
    }

    $contactEmail = trim($_POST['contact_email'] ?? '');

    // ---- validation ----
    if ($title === '' || $description === '') {
        $error = 'Title and description are required.';
    } elseif ($isPaid && $stipend !== null && $stipend < 0) {
        $error = 'The monthly stipend cannot be negative.';
    } else {
        $stmt = $db->prepare(
            "INSERT INTO internship_offers
             (posted_by_user_id, type, title, description, domain, capacity, min_cfu,
              required_program, duration_months, location, is_remote, is_paid,
              stipend_monthly, contact_email, status)
             VALUES (?, 'company', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
        );
        $stmt->execute([
            $user['id'], $title, $description, $domain, $capacity, $minCfu,
            $requiredProgram, $durationMonths, $location, $isRemote, $isPaid,
            $stipend, $contactEmail
        ]);
        $offerId = (int)$db->lastInsertId();

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

        // A draft offer is invisible until a coordinator approves it, so the
        // coordinators have to be told one is waiting. Without this the offer
        // sits in the review queue with nothing to announce it.
        $posterName = $user['name'];
        $cstmt = $db->query("SELECT id FROM users WHERE role = 'coordinator' AND is_active = 1");
        foreach ($cstmt->fetchAll() as $c) {
            notify(
                $db, $c['id'],
                'Offer awaiting review',
                $posterName . ' submitted "' . $title . '" for approval.',
                'action',
                BASE_URL . '/admin/offer-detail.php?id=' . $offerId
            );
        }

        header('Location: ' . BASE_URL . '/company/my-offers.php?posted=1');
        exit;
    }
}

/** Repopulate the form after a failed submit instead of discarding the work. */
function keep(array $old, string $field, string $fallback = ''): string {
    return htmlspecialchars($old[$field] ?? $fallback);
}
$paidChecked = isset($old['is_paid']);

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Post an internship offer</h1>
    <p>New offers go to the coordinator for review before appearing publicly.</p>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" class="card form-narrow">
    <label for="title">Title</label>
    <input type="text" id="title" name="title" value="<?= keep($old, 'title') ?>" required>

    <label for="description">Description</label>
    <textarea id="description" name="description" required><?= keep($old, 'description') ?></textarea>

    <label for="domain">Domain</label>
    <input type="text" id="domain" name="domain" value="<?= keep($old, 'domain') ?>" placeholder="e.g. Web Development">

    <label for="capacity">Capacity (students)</label>
    <input type="number" id="capacity" name="capacity" value="<?= keep($old, 'capacity', '1') ?>" min="1">

    <label for="min_cfu">Minimum CFU</label>
    <input type="number" id="min_cfu" name="min_cfu" value="<?= keep($old, 'min_cfu') ?>" min="0">

    <label for="required_program">Required program</label>
    <input type="text" id="required_program" name="required_program" value="<?= keep($old, 'required_program') ?>" placeholder="Leave blank for any">

    <label for="duration_months">Duration (months)</label>
    <input type="number" id="duration_months" name="duration_months" value="<?= keep($old, 'duration_months', '3') ?>" min="1">

    <label for="location">Location</label>
    <input type="text" id="location" name="location" value="<?= keep($old, 'location') ?>">

    <label><input type="checkbox" name="is_remote" style="width:auto;display:inline-block;margin-right:6px;"
                  <?= isset($old['is_remote']) ? 'checked' : '' ?>> Remote available</label>

    <label><input type="checkbox" id="is_paid" name="is_paid" style="width:auto;display:inline-block;margin-right:6px;"
                  onchange="toggleStipend()" <?= $paidChecked ? 'checked' : '' ?>> Paid internship</label>

    <!-- Only meaningful for a paid position, so it stays out of the way until
         the box above is ticked. -->
    <div id="stipend-block" style="<?= $paidChecked ? '' : 'display:none;' ?>">
        <label for="stipend_monthly">Monthly stipend (€)</label>
        <input type="number" id="stipend_monthly" name="stipend_monthly" step="0.01" min="0"
               value="<?= keep($old, 'stipend_monthly') ?>" placeholder="e.g. 500.00">
    </div>

    <label for="contact_email">Contact email (if different from your account)</label>
    <input type="email" id="contact_email" name="contact_email" value="<?= keep($old, 'contact_email') ?>">

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

<script>
function toggleStipend() {
    var paid = document.getElementById('is_paid').checked;
    var block = document.getElementById('stipend-block');
    block.style.display = paid ? '' : 'none';
    // Clear the amount when the position is switched back to unpaid, so a
    // figure typed and then abandoned is not silently submitted.
    if (!paid) document.getElementById('stipend_monthly').value = '';
}
toggleStipend();
</script>

<?php require '../includes/footer.php'; ?>