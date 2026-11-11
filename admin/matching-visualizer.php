<?php
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

$user = currentUser();
$activePage = 'cycle-control';

$roundId = (int)($_GET['round_id'] ?? 0);
if (!$roundId) {
    header('Location: ' . BASE_URL . '/admin/cycle-control.php');
    exit;
}

// ----- Load round -----
$stmt = $db->prepare("
    SELECT mr.*, ac.name AS cycle_name
    FROM matching_rounds mr
    JOIN academic_cycles ac ON ac.id = mr.cycle_id
    WHERE mr.id = ?
");
$stmt->execute([$roundId]);
$round = $stmt->fetch();
if (!$round) {
    header('Location: ' . BASE_URL . '/admin/cycle-control.php');
    exit;
}

// ----- Load trace -----
$stmt = $db->prepare("
    SELECT step_number, student_id, offer_id, outcome, bumped_student_id
    FROM matching_trace
    WHERE round_id = ?
    ORDER BY step_number ASC
");
$stmt->execute([$roundId]);
$traceRows = $stmt->fetchAll();

// ----- All students involved in the round (proposers OR bumped) -----
$stmt = $db->prepare("
    SELECT DISTINCT s.id, u.first_name, u.last_name, s.matricola
    FROM students s
    JOIN users u ON u.id = s.user_id
    WHERE s.id IN (
        SELECT student_id FROM matching_trace WHERE round_id = ?
        UNION
        SELECT bumped_student_id FROM matching_trace
          WHERE round_id = ? AND bumped_student_id IS NOT NULL
    )
    ORDER BY u.last_name, u.first_name
");
$stmt->execute([$roundId, $roundId]);
$studentsList = $stmt->fetchAll();
$students = [];
foreach ($studentsList as $s) {
    $students[(int)$s['id']] = [
        'id' => (int)$s['id'],
        'name' => $s['first_name'] . ' ' . $s['last_name'],
        'matricola' => $s['matricola'],
    ];
}

// ----- All offers involved -----
$stmt = $db->prepare("
    SELECT DISTINCT o.id, o.title, o.capacity, o.filled,
           COALESCE(c.company_name, CONCAT(u.first_name, ' ', u.last_name)) AS poster_name
    FROM internship_offers o
    JOIN users u ON u.id = o.posted_by_user_id
    LEFT JOIN companies c ON c.user_id = u.id
    WHERE o.id IN (SELECT offer_id FROM matching_trace WHERE round_id = ?)
    ORDER BY o.title
");
$stmt->execute([$roundId]);
$offersList = $stmt->fetchAll();
$offers = [];
foreach ($offersList as $o) {
    $offers[(int)$o['id']] = [
        'id' => (int)$o['id'],
        'title' => $o['title'],
        'poster' => $o['poster_name'],
        'capacity' => (int)$o['capacity'],
        'prior_filled' => (int)$o['filled'],
        'available' => max(0, (int)$o['capacity'] - (int)$o['filled']),
    ];
}

// ----- Student preferences (for verifier) -----
$stmt = $db->prepare("
    SELECT student_id, offer_id, `rank`
    FROM student_preferences
    WHERE cycle_id = ?
    ORDER BY student_id, `rank` ASC
");
$stmt->execute([(int)$round['cycle_id']]);
$studentPrefs = [];
foreach ($stmt->fetchAll() as $p) {
    $studentPrefs[(int)$p['student_id']][] = (int)$p['offer_id'];
}

// ----- Offer rankings -----
$stmt = $db->prepare("
    SELECT offer_id, student_id, `rank`
    FROM offer_rankings
    WHERE cycle_id = ?
    ORDER BY offer_id, `rank` ASC
");
$stmt->execute([(int)$round['cycle_id']]);
$offerRanks = [];
foreach ($stmt->fetchAll() as $r) {
    $offerRanks[(int)$r['offer_id']][] = (int)$r['student_id'];
}

// ----- Final allocations -----
$stmt = $db->prepare("SELECT student_id, offer_id FROM allocations WHERE round_id = ?");
$stmt->execute([$roundId]);
$finalMatch = [];
foreach ($stmt->fetchAll() as $a) {
    $finalMatch[(int)$a['student_id']] = (int)$a['offer_id'];
}

// ----- Trace to JS-friendly -----
$traceJs = [];
foreach ($traceRows as $t) {
    $traceJs[] = [
        'step'       => (int)$t['step_number'],
        'student_id' => (int)$t['student_id'],
        'offer_id'   => (int)$t['offer_id'],
        'outcome'    => $t['outcome'],
        'bumped_id'  => $t['bumped_student_id'] !== null ? (int)$t['bumped_student_id'] : null,
    ];
}

require '../includes/header.php';
?>

<div class="page-header">
    <h1>Matching visualizer — Round #<?= (int)$round['id'] ?></h1>
    <p>
        Cycle <?= htmlspecialchars($round['cycle_name']) ?> ·
        Status <span class="badge badge-<?= $round['status'] === 'published' ? 'open' : ($round['status'] === 'unstable' ? 'closed' : 'pending') ?>"><?= htmlspecialchars($round['status']) ?></span> ·
        <?= count($traceRows) ?> trace steps ·
        <?= (int)$round['blocking_pairs'] ?> blocking pairs
    </p>
    <p><a href="<?= BASE_URL ?>/admin/cycle-control.php">← Back to cycle control</a></p>
</div>

<div class="viz-tabs">
    <button class="viz-tab active" data-tab="playback">▶ Algorithm Playback</button>
    <button class="viz-tab" data-tab="verifier">✓ Stability Verifier</button>
    <button class="viz-tab" data-tab="analytics">📊 Analytics</button>
</div>

<!-- =========================================================== -->
<!-- TAB 1: PLAYBACK                                              -->
<!-- =========================================================== -->
<section class="viz-panel active" data-panel="playback">
    <div class="card viz-legend">
        <div class="legend-row">
            <span class="dot dot-idle"></span> idle
            <span class="dot dot-proposing"></span> proposing (this step)
            <span class="dot dot-held"></span> currently held by an offer
            <span class="dot dot-bumped"></span> just bumped
            <span class="dot dot-rejected"></span> rejected this step
            <span class="dot dot-final"></span> matched (final)
        </div>
        <div class="legend-row" style="margin-top:6px;color:var(--slate);font-size:12px;">
            Slots on the right show each offer's capacity. Filled circles are holders.
        </div>
    </div>

    <div class="playback-stage" id="stage">
        <div class="column column-students">
            <div class="column-header">Students</div>
            <div class="student-list" id="student-list">
                <?php foreach ($students as $s): ?>
                    <div class="entity entity-student" data-student-id="<?= $s['id'] ?>">
                        <div class="entity-name"><?= htmlspecialchars($s['name']) ?></div>
                        <div class="entity-meta"><?= htmlspecialchars($s['matricola']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <svg class="playback-arrows" id="arrows" xmlns="http://www.w3.org/2000/svg"></svg>

        <div class="column column-offers">
            <div class="column-header">Offers</div>
            <div class="offer-list" id="offer-list">
                <?php foreach ($offers as $o): ?>
                    <div class="entity entity-offer" data-offer-id="<?= $o['id'] ?>"
                         data-capacity="<?= $o['available'] ?>">
                        <div class="entity-name"><?= htmlspecialchars($o['title']) ?></div>
                        <div class="entity-meta"><?= htmlspecialchars($o['poster']) ?></div>
                        <div class="slots" data-offer-id="<?= $o['id'] ?>">
                            <?php for ($i = 0; $i < $o['available']; $i++): ?>
                                <div class="slot"></div>
                            <?php endfor; ?>
                            <span class="slot-label">0 / <?= $o['available'] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="step-narration" id="step-narration">Ready to play. Press ▶ to start.</div>
        <div class="playback-controls">
            <button class="btn-ctrl" id="btn-first" title="First">⏮</button>
            <button class="btn-ctrl" id="btn-prev" title="Previous">◀</button>
            <button class="btn-ctrl btn-play" id="btn-play" title="Play/Pause">▶</button>
            <button class="btn-ctrl" id="btn-next" title="Next">▶</button>
            <button class="btn-ctrl" id="btn-last" title="Last">⏭</button>
            <span class="step-counter" id="step-counter">0 / <?= count($traceJs) ?></span>
            <select id="speed-select" class="speed-select">
                <option value="2000">0.5×</option>
                <option value="1200" selected>1×</option>
                <option value="600">2×</option>
                <option value="300">4×</option>
            </select>
        </div>
        <input type="range" id="step-slider" class="step-slider"
               min="0" max="<?= count($traceJs) ?>" value="0" step="1">
    </div>

    <div class="card">
        <h3 style="margin:0 0 12px 0;">Trace log</h3>
        <ol class="trace-log" id="trace-log"></ol>
    </div>
</section>

<!-- =========================================================== -->
<!-- TAB 2: STABILITY VERIFIER                                    -->
<!-- =========================================================== -->
<section class="viz-panel" data-panel="verifier">
    <div class="card">
        <h3 style="margin-top:0;">What this checks</h3>
        <p>
            A matching is <strong>stable</strong> if no pair (student, offer) both prefer each other over
            what they got. The verifier walks every unmatched pair and asks:
        </p>
        <ol style="margin:8px 0 8px 20px;">
            <li>Does this student prefer that offer over their current match?</li>
            <li>If yes — does that offer either have a free seat, or rank this student above one of its current holders?</li>
        </ol>
        <p>
            If both are true → <strong>blocking pair</strong> → algorithm has a bug.
            A correct Gale-Shapley run always produces zero blocking pairs.
        </p>
    </div>

    <div class="card">
        <div class="verifier-controls">
            <button class="btn btn-primary" id="btn-run-verifier" style="width:auto;">▶ Run verifier</button>
            <span id="verifier-status" style="margin-left:16px;color:var(--slate);">Not yet run.</span>
        </div>
        <div class="verifier-summary" id="verifier-summary"></div>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Verification walkthrough</h3>
        <div class="verifier-log" id="verifier-log">
            <p style="color:var(--slate);">Press "Run verifier" to walk through the check.</p>
        </div>
    </div>
</section>

<!-- =========================================================== -->
<!-- TAB 3: ANALYTICS                                             -->
<!-- =========================================================== -->
<section class="viz-panel" data-panel="analytics">
    <div class="kpi-grid" id="kpi-grid"></div>

    <div class="card">
        <h3 style="margin-top:0;">Preference rank achieved</h3>
        <p style="color:var(--slate);font-size:13px;margin-top:0;">
            Of the students who got matched, how high on their wishlist did they land?
        </p>
        <div class="pref-histogram" id="pref-histogram"></div>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Trace outcome breakdown</h3>
        <p style="color:var(--slate);font-size:13px;margin-top:0;">
            What happened at each of the <?= count($traceJs) ?> trace steps.
        </p>
        <div class="outcome-bars" id="outcome-bars"></div>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Unmatched students</h3>
        <p style="color:var(--slate);font-size:13px;margin-top:0;">
            Students who submitted preferences but landed nowhere.
        </p>
        <div id="unmatched-list"></div>
    </div>
</section>


<!-- =========================================================== -->
<!-- STYLES                                                       -->
<!-- =========================================================== -->
<style>
/* Tabs */
.viz-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--border);
}
.viz-tab {
    background: none;
    border: none;
    padding: 12px 20px;
    font-family: inherit;
    font-size: 14px;
    color: var(--slate);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
}
.viz-tab.active {
    color: var(--strait-blue);
    border-bottom-color: var(--strait-blue);
    font-weight: 500;
}
.viz-panel { display: none; }
.viz-panel.active { display: block; }

/* Legend */
.viz-legend .legend-row { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; font-size: 12px; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
.dot-idle       { background: #d5d9df; }
.dot-proposing  { background: var(--mosaic-gold); box-shadow: 0 0 8px var(--mosaic-gold); }
.dot-held       { background: var(--strait-blue); }
.dot-bumped     { background: #e07070; }
.dot-rejected   { background: #ea8b8b; }
.dot-final      { background: #6aa66a; }

/* Playback stage */
.playback-stage {
    position: relative;
    display: grid;
    grid-template-columns: 1fr 260px 1fr;
    gap: 0;
    margin: 20px 0;
    background: var(--paper);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 20px;
    min-height: 500px;
}
.column-header {
    font-family: var(--font-mono);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--slate);
    margin-bottom: 12px;
}
.student-list, .offer-list {
    display: flex; flex-direction: column; gap: 8px;
}
.entity {
    padding: 10px 12px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 4px;
    transition: all 0.35s ease;
    position: relative;
}
.entity-name  { font-weight: 500; font-size: 13px; }
.entity-meta  { font-size: 11px; color: var(--slate); font-family: var(--font-mono); margin-top: 2px; }

/* State classes */
.entity.state-proposing {
    border-color: var(--mosaic-gold);
    background: #fff8e1;
    box-shadow: 0 0 0 3px rgba(198, 168, 62, 0.2);
    transform: translateX(6px);
}
.entity.state-target {
    border-color: var(--strait-blue);
    background: #e8f0fe;
    box-shadow: 0 0 0 3px rgba(64, 106, 172, 0.2);
    transform: translateX(-6px);
}
.entity.state-held    { border-color: var(--strait-blue); background: #f0f5ff; }
.entity.state-bumped  { border-color: #e07070; background: #fdeaea; }
.entity.state-rejected{ border-color: #ea8b8b; background: #fef3f3; }
.entity.state-final   { border-color: #6aa66a; background: #f0f9f0; }

/* Slots visualization */
.slots {
    display: flex; align-items: center; gap: 4px;
    margin-top: 8px; padding-top: 8px;
    border-top: 1px dashed var(--border);
}
.slot {
    width: 12px; height: 12px; border-radius: 50%;
    border: 2px solid var(--border); background: white;
    transition: all 0.35s ease;
}
.slot.filled {
    background: var(--strait-blue);
    border-color: var(--strait-blue);
    box-shadow: 0 0 0 2px rgba(64, 106, 172, 0.15);
}
.slot-label { font-family: var(--font-mono); font-size: 11px; color: var(--slate); margin-left: 6px; }

/* SVG arrow overlay */
.playback-arrows {
    grid-column: 2;
    grid-row: 1;
    width: 100%;
    height: 100%;
    align-self: stretch;
    justify-self: stretch;
    pointer-events: none;
    overflow: visible;
}

/* Controls */
.step-narration {
    font-size: 15px;
    padding: 12px 16px;
    background: var(--parchment);
    border-radius: 4px;
    margin-bottom: 12px;
    font-family: var(--font-mono);
    min-height: 22px;
}
.playback-controls {
    display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
}
.btn-ctrl {
    width: 40px; height: 40px;
    border: 1px solid var(--border);
    background: white;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    font-family: inherit;
}
.btn-ctrl:hover { background: var(--parchment); }
.btn-ctrl.btn-play { width: 60px; background: var(--strait-blue); color: white; border-color: var(--strait-blue); }
.btn-ctrl.btn-play:hover { background: var(--ink-navy); }
.step-counter { margin-left: 16px; font-family: var(--font-mono); font-size: 13px; color: var(--slate); }
.speed-select {
    margin-left: auto; padding: 6px 8px;
    border: 1px solid var(--border); border-radius: 4px;
    font-family: inherit; font-size: 13px;
}
.step-slider { width: 100%; }

/* Trace log */
.trace-log {
    list-style: none; padding: 0; margin: 0;
    max-height: 320px; overflow-y: auto;
    font-family: var(--font-mono); font-size: 12px;
}
.trace-log li {
    padding: 6px 10px; border-bottom: 1px solid var(--border);
    display: grid; grid-template-columns: 50px 1fr; gap: 12px;
}
.trace-log li.current { background: #fff8e1; border-left: 3px solid var(--mosaic-gold); }
.trace-log li .tl-step { color: var(--slate); }
.trace-log li .tl-outcome-accepted        { color: #4a8f4a; font-weight: 500; }
.trace-log li .tl-outcome-rejected_full   { color: #b06060; }
.trace-log li .tl-outcome-rejected_unranked{ color: #b06060; }
.trace-log li .tl-outcome-bumped          { color: #c07020; font-weight: 500; }

/* Verifier */
.verifier-summary {
    margin-top: 16px; padding: 16px; border-radius: 4px;
    display: none;
    font-family: var(--font-mono);
}
.verifier-summary.stable  { background: #f0f9f0; border: 1px solid #6aa66a; color: #2d5f2d; display: block; }
.verifier-summary.unstable{ background: #fdeaea; border: 1px solid #c04040; color: #6f2020; display: block; }

.verifier-log { font-family: var(--font-mono); font-size: 12px; max-height: 500px; overflow-y: auto; }
.verifier-log .v-student {
    margin: 12px 0; padding: 12px;
    background: white; border: 1px solid var(--border); border-radius: 4px;
}
.verifier-log .v-student-header {
    font-weight: 500; font-size: 13px;
    padding-bottom: 8px; border-bottom: 1px dashed var(--border); margin-bottom: 8px;
    display: flex; justify-content: space-between; align-items: center;
}
.verifier-log .v-check   { padding: 4px 0 4px 16px; color: var(--slate); }
.verifier-log .v-pass    { color: #4a8f4a; }
.verifier-log .v-fail    { color: #c04040; font-weight: 500; }
.verifier-log .v-neutral { color: var(--slate); }
.verifier-log .v-verdict { margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--border); font-weight: 500; }

/* Analytics */
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
.kpi { background: var(--paper); border: 1px solid var(--border); border-radius: 4px; padding: 16px; }
.kpi-value { font-family: var(--font-serif); font-size: 32px; color: var(--ink-navy); line-height: 1; }
.kpi-label { font-size: 12px; color: var(--slate); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

.pref-histogram { display: flex; flex-direction: column; gap: 6px; }
.hist-row { display: grid; grid-template-columns: 80px 1fr 60px; align-items: center; gap: 12px; font-size: 13px; }
.hist-bar { height: 22px; background: var(--strait-blue); border-radius: 3px; min-width: 2px; transition: width 0.5s ease; }
.hist-count { text-align: right; font-family: var(--font-mono); color: var(--slate); }

.outcome-bars .hist-bar.b-accepted           { background: #6aa66a; }
.outcome-bars .hist-bar.b-bumped             { background: #c07020; }
.outcome-bars .hist-bar.b-rejected_full      { background: #b06060; }
.outcome-bars .hist-bar.b-rejected_unranked  { background: #d4a0a0; }
</style>


<!-- =========================================================== -->
<!-- SCRIPT — data + all visualization logic                      -->
<!-- =========================================================== -->
<script>
// ---------- Data from PHP ----------
const STUDENTS = <?= json_encode($students, JSON_UNESCAPED_UNICODE) ?>;
const OFFERS   = <?= json_encode($offers,   JSON_UNESCAPED_UNICODE) ?>;
const TRACE    = <?= json_encode($traceJs) ?>;
const STUDENT_PREFS = <?= json_encode($studentPrefs) ?>;
const OFFER_RANKS   = <?= json_encode($offerRanks) ?>;
const FINAL_MATCH   = <?= json_encode($finalMatch) ?>;

// ---------- Tab switching ----------
document.querySelectorAll('.viz-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.viz-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.viz-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.querySelector(`.viz-panel[data-panel="${btn.dataset.tab}"]`).classList.add('active');
        if (btn.dataset.tab === 'analytics' && !window.__analyticsRendered) {
            renderAnalytics();
            window.__analyticsRendered = true;
        }
    });
});

// ===========================================================
//  PLAYBACK
// ===========================================================
const playback = (() => {
    // Reconstructed state at step N
    let stepIdx = 0;               // 0 = before any step; TRACE.length = after all steps
    let playing = false;
    let playTimer = null;
    let speedMs = 1200;

    // Reconstruct the world state by replaying trace 0..stepIdx
    function stateAtStep(idx) {
        const holds = {};   // offer_id -> [student_id, ...]
        const assigned = {}; // student_id -> offer_id or null
        for (const o of Object.values(OFFERS)) holds[o.id] = [];
        for (const s of Object.values(STUDENTS)) assigned[s.id] = null;

        for (let i = 0; i < idx; i++) {
            const t = TRACE[i];
            if (t.outcome === 'accepted') {
                if (t.bumped_id) {
                    // A bump — remove bumped, add proposer
                    holds[t.offer_id] = holds[t.offer_id].filter(sid => sid !== t.bumped_id);
                    assigned[t.bumped_id] = null;
                }
                holds[t.offer_id].push(t.student_id);
                assigned[t.student_id] = t.offer_id;
            }
            // rejected_* outcomes don't change state
        }
        return { holds, assigned };
    }

    // Narrative text for a step
    function narrate(t) {
        if (!t) return 'Initial state — no proposals yet.';
        const s = STUDENTS[t.student_id]?.name ?? `#${t.student_id}`;
        const o = OFFERS[t.offer_id]?.title ?? `offer #${t.offer_id}`;
        const bumped = t.bumped_id ? STUDENTS[t.bumped_id]?.name : null;
        switch (t.outcome) {
            case 'accepted':
                return bumped
                    ? `Step ${t.step}: ${s} proposed to ${o} — accepted. ${bumped} was bumped and returns to the queue.`
                    : `Step ${t.step}: ${s} proposed to ${o} — accepted (spare capacity).`;
            case 'rejected_full':
                return `Step ${t.step}: ${s} proposed to ${o} — rejected (offer full, prefers its current holders).`;
            case 'rejected_unranked':
                return `Step ${t.step}: ${s} proposed to ${o} — rejected (offer never ranked this student).`;
            default:
                return `Step ${t.step}: ${s} → ${o}: ${t.outcome}`;
        }
    }

    // Wipe all state classes off entities and slots
    function clearVisualState() {
        document.querySelectorAll('.entity').forEach(el => {
            el.classList.remove('state-proposing', 'state-target', 'state-held', 'state-bumped', 'state-rejected', 'state-final');
        });
        document.querySelectorAll('.slot').forEach(sl => sl.classList.remove('filled'));
        document.querySelectorAll('.slot-label').forEach(lab => {
            const parent = lab.closest('.slots');
            const oid = parent.dataset.offerId;
            const cap = document.querySelector(`.entity-offer[data-offer-id="${oid}"]`).dataset.capacity;
            lab.textContent = `0 / ${cap}`;
        });
    }

    // Render the world state on the DOM for a given step
    function render() {
        clearVisualState();
        const { holds, assigned } = stateAtStep(stepIdx);
        const current = stepIdx > 0 ? TRACE[stepIdx - 1] : null;

        // Mark held students and fill slots
        for (const [oidStr, sids] of Object.entries(holds)) {
            const oid = Number(oidStr);
            const slotsEl = document.querySelector(`.slots[data-offer-id="${oid}"]`);
            if (!slotsEl) continue;
            const slots = slotsEl.querySelectorAll('.slot');
            sids.forEach((sid, i) => {
                if (slots[i]) slots[i].classList.add('filled');
                document.querySelector(`.entity-student[data-student-id="${sid}"]`)?.classList.add('state-held');
            });
            const label = slotsEl.querySelector('.slot-label');
            const cap = document.querySelector(`.entity-offer[data-offer-id="${oid}"]`).dataset.capacity;
            if (label) label.textContent = `${sids.length} / ${cap}`;
        }

        // Highlight the current action
        if (current) {
            const proposer = document.querySelector(`.entity-student[data-student-id="${current.student_id}"]`);
            const target   = document.querySelector(`.entity-offer[data-offer-id="${current.offer_id}"]`);
            if (proposer) proposer.classList.add('state-proposing');
            if (target)   target.classList.add('state-target');

            if (current.bumped_id) {
                const bumped = document.querySelector(`.entity-student[data-student-id="${current.bumped_id}"]`);
                if (bumped) {
                    bumped.classList.remove('state-held');
                    bumped.classList.add('state-bumped');
                }
            }
            if (current.outcome === 'rejected_full' || current.outcome === 'rejected_unranked') {
                if (proposer) {
                    proposer.classList.remove('state-proposing');
                    proposer.classList.add('state-rejected');
                }
            }
        }

        // If we're at the very end, show final matches with the "final" color
        if (stepIdx === TRACE.length) {
            for (const [sidStr, oid] of Object.entries(FINAL_MATCH)) {
                document.querySelector(`.entity-student[data-student-id="${sidStr}"]`)?.classList.add('state-final');
            }
        }

        drawArrow(current);
        document.getElementById('step-narration').textContent = narrate(current);
        document.getElementById('step-counter').textContent = `${stepIdx} / ${TRACE.length}`;
        document.getElementById('step-slider').value = stepIdx;
        renderTraceLog();
    }

    // Draw an arrow in the SVG middle column between the proposer and the target offer
    function drawArrow(current) {
        const svg = document.getElementById('arrows');
        svg.innerHTML = '';
        if (!current) return;

        const stage = document.getElementById('stage');
        const stageRect = stage.getBoundingClientRect();
        const proposerEl = document.querySelector(`.entity-student[data-student-id="${current.student_id}"]`);
        const targetEl   = document.querySelector(`.entity-offer[data-offer-id="${current.offer_id}"]`);
        if (!proposerEl || !targetEl) return;

        const p = proposerEl.getBoundingClientRect();
        const t = targetEl.getBoundingClientRect();
        const svgRect = svg.getBoundingClientRect();

        // Convert to SVG-local coords
        const x1 = p.right  - svgRect.left;
        const y1 = p.top + p.height/2 - svgRect.top;
        const x2 = t.left   - svgRect.left;
        const y2 = t.top + t.height/2 - svgRect.top;

        const midX = (x1 + x2) / 2;
        const path = `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`;

        let colour = '#c6a83e';  // proposing
        if (current.outcome === 'accepted') colour = '#4a8f4a';
        if (current.outcome === 'rejected_full' || current.outcome === 'rejected_unranked') colour = '#c04040';

        const ns = 'http://www.w3.org/2000/svg';
        const defs = document.createElementNS(ns, 'defs');
        defs.innerHTML = `
            <marker id="arrowhead" viewBox="0 0 10 10" refX="9" refY="5"
                    markerWidth="8" markerHeight="8" orient="auto">
                <path d="M 0 0 L 10 5 L 0 10 z" fill="${colour}"/>
            </marker>`;
        svg.appendChild(defs);

        const arrow = document.createElementNS(ns, 'path');
        arrow.setAttribute('d', path);
        arrow.setAttribute('stroke', colour);
        arrow.setAttribute('stroke-width', '2.5');
        arrow.setAttribute('fill', 'none');
        arrow.setAttribute('marker-end', 'url(#arrowhead)');
        arrow.setAttribute('opacity', '0.9');
        svg.appendChild(arrow);

        // Bump indicator: red dashed line from bumped student back
        if (current.bumped_id) {
            const bumpedEl = document.querySelector(`.entity-student[data-student-id="${current.bumped_id}"]`);
            if (bumpedEl) {
                const b = bumpedEl.getBoundingClientRect();
                const bx = b.right - svgRect.left;
                const by = b.top + b.height/2 - svgRect.top;
                const bumpPath = document.createElementNS(ns, 'path');
                bumpPath.setAttribute('d', `M ${x2} ${y2} L ${bx} ${by}`);
                bumpPath.setAttribute('stroke', '#e07070');
                bumpPath.setAttribute('stroke-width', '2');
                bumpPath.setAttribute('stroke-dasharray', '4 4');
                bumpPath.setAttribute('fill', 'none');
                svg.appendChild(bumpPath);
            }
        }
    }

    function renderTraceLog() {
        const log = document.getElementById('trace-log');
        log.innerHTML = '';
        TRACE.forEach((t, i) => {
            const li = document.createElement('li');
            const stepN = document.createElement('span');
            stepN.className = 'tl-step';
            stepN.textContent = `#${t.step}`;

            const body = document.createElement('span');
            body.className = `tl-outcome tl-outcome-${t.outcome}`;
            const s = STUDENTS[t.student_id]?.name ?? `#${t.student_id}`;
            const o = OFFERS[t.offer_id]?.title ?? `#${t.offer_id}`;
            let text = `${s} → ${o}  ·  ${t.outcome.replace('_', ' ')}`;
            if (t.bumped_id) text += `  (bumped ${STUDENTS[t.bumped_id]?.name})`;
            body.textContent = text;

            li.appendChild(stepN); li.appendChild(body);
            if (i === stepIdx - 1) li.classList.add('current');
            log.appendChild(li);
        });
        // Auto-scroll to current
        const currentLi = log.querySelector('li.current');
        if (currentLi) currentLi.scrollIntoView({ block: 'nearest' });
    }

    function stepForward() {
        if (stepIdx < TRACE.length) { stepIdx++; render(); }
        else pause();
    }
    function stepBackward()  { if (stepIdx > 0)         { stepIdx--; render(); } }
    function goToStart()     { stepIdx = 0;             render(); }
    function goToEnd()       { stepIdx = TRACE.length;  render(); }
    function goToStep(n)     { stepIdx = Math.max(0, Math.min(TRACE.length, n)); render(); }

    function play() {
        if (stepIdx >= TRACE.length) stepIdx = 0;
        playing = true;
        document.getElementById('btn-play').textContent = '⏸';
        playTimer = setInterval(() => {
            if (stepIdx >= TRACE.length) { pause(); return; }
            stepForward();
        }, speedMs);
    }
    function pause() {
        playing = false;
        document.getElementById('btn-play').textContent = '▶';
        if (playTimer) { clearInterval(playTimer); playTimer = null; }
    }

    document.getElementById('btn-first').addEventListener('click', () => { pause(); goToStart(); });
    document.getElementById('btn-last').addEventListener('click',  () => { pause(); goToEnd(); });
    document.getElementById('btn-prev').addEventListener('click',  () => { pause(); stepBackward(); });
    document.getElementById('btn-next').addEventListener('click',  () => { pause(); stepForward(); });
    document.getElementById('btn-play').addEventListener('click',  () => { playing ? pause() : play(); });
    document.getElementById('step-slider').addEventListener('input', e => { pause(); goToStep(Number(e.target.value)); });
    document.getElementById('speed-select').addEventListener('change', e => {
        speedMs = Number(e.target.value);
        if (playing) { pause(); play(); }
    });

    window.addEventListener('resize', () => {
        // Redraw the arrow (positions changed)
        drawArrow(stepIdx > 0 ? TRACE[stepIdx - 1] : null);
    });

    // Initial render
    render();
    return { stateAtStep };
})();


// ===========================================================
//  STABILITY VERIFIER  — re-runs the check live in JS
// ===========================================================
document.getElementById('btn-run-verifier').addEventListener('click', () => {
    const logEl = document.getElementById('verifier-log');
    const summaryEl = document.getElementById('verifier-summary');
    const statusEl = document.getElementById('verifier-status');
    logEl.innerHTML = '';
    summaryEl.className = 'verifier-summary';
    summaryEl.textContent = '';
    statusEl.textContent = 'Running…';

    // Reconstruct final holds from allocations
    const finalHolds = {};   // offer_id -> [student_id]
    for (const [sidStr, oid] of Object.entries(FINAL_MATCH)) {
        (finalHolds[oid] = finalHolds[oid] || []).push(Number(sidStr));
    }

    // Fast offer-ranks lookup
    const offerRankOf = {};
    for (const [oidStr, sids] of Object.entries(OFFER_RANKS)) {
        offerRankOf[oidStr] = {};
        sids.forEach((sid, r) => { offerRankOf[oidStr][sid] = r; });
    }

    let blockingPairs = [];
    let studentsChecked = 0;

    // Iterate each student with preferences
    for (const [sidStr, prefs] of Object.entries(STUDENT_PREFS)) {
        const sid = Number(sidStr);
        const currentOffer = FINAL_MATCH[sid] ?? null;
        const currentRank  = currentOffer !== null ? prefs.indexOf(currentOffer) : Infinity;
        studentsChecked++;

        const studentDiv = document.createElement('div');
        studentDiv.className = 'v-student';
        const currentOfferName = currentOffer !== null ? (OFFERS[currentOffer]?.title ?? '?') : '(unmatched)';
        studentDiv.innerHTML = `
            <div class="v-student-header">
                <span>${STUDENTS[sid]?.name ?? '#'+sid}</span>
                <span class="v-neutral">matched to: ${currentOfferName}</span>
            </div>`;
        const detailsUl = document.createElement('ul');
        detailsUl.style.margin = '0'; detailsUl.style.paddingLeft = '18px'; detailsUl.style.listStyle = 'none';

        let anyBlockingForThisStudent = false;

        // Walk their preferences from top; stop at their current match
        for (let r = 0; r < prefs.length; r++) {
            if (r >= currentRank) break;   // they'd never leave what they have for these
            const oid = prefs[r];
            const holders = finalHolds[oid] || [];
            const available = OFFERS[oid]?.available ?? 0;
            const li = document.createElement('li');
            li.className = 'v-check';

            const oName = OFFERS[oid]?.title ?? `#${oid}`;

            // Is student ranked by this offer at all?
            const studentRankHere = offerRankOf[oid]?.[sid];
            if (studentRankHere === undefined) {
                li.innerHTML = `Prefers <em>${oName}</em> over current match → but offer never ranked ${STUDENTS[sid]?.name}. <span class="v-pass">Not a blocking pair ✓</span>`;
                detailsUl.appendChild(li);
                continue;
            }

            // Does offer have space?
            if (holders.length < available) {
                li.innerHTML = `Prefers <em>${oName}</em> over current match — offer has spare capacity AND ranks them. <span class="v-fail">BLOCKING PAIR ✗</span>`;
                detailsUl.appendChild(li);
                blockingPairs.push({ sid, oid, reason: 'offer has spare capacity' });
                anyBlockingForThisStudent = true;
                continue;
            }

            // Does offer prefer this student over any current holder?
            const betterThanAnyHolder = holders.some(h => (offerRankOf[oid][h] ?? Infinity) > studentRankHere);
            if (betterThanAnyHolder) {
                li.innerHTML = `Prefers <em>${oName}</em> over current match — offer prefers them over a current holder. <span class="v-fail">BLOCKING PAIR ✗</span>`;
                detailsUl.appendChild(li);
                blockingPairs.push({ sid, oid, reason: 'offer prefers this student' });
                anyBlockingForThisStudent = true;
            } else {
                li.innerHTML = `Prefers <em>${oName}</em> over current match — but offer prefers all current holders. <span class="v-pass">Not a blocking pair ✓</span>`;
                detailsUl.appendChild(li);
            }
        }

        if (detailsUl.children.length === 0) {
            const li = document.createElement('li');
            li.className = 'v-check v-neutral';
            li.textContent = 'Student got their top-preferred available offer — no higher option to check.';
            detailsUl.appendChild(li);
        }

        studentDiv.appendChild(detailsUl);
        const verdict = document.createElement('div');
        verdict.className = 'v-verdict';
        if (anyBlockingForThisStudent) {
            verdict.innerHTML = `<span class="v-fail">Result: instability found for this student.</span>`;
        } else {
            verdict.innerHTML = `<span class="v-pass">Result: stable for this student ✓</span>`;
        }
        studentDiv.appendChild(verdict);
        logEl.appendChild(studentDiv);
    }

    // Final summary
    statusEl.textContent = `Checked ${studentsChecked} students.`;
    if (blockingPairs.length === 0) {
        summaryEl.className = 'verifier-summary stable';
        summaryEl.innerHTML = `✓ <strong>MATCHING IS STABLE.</strong> Zero blocking pairs across ${studentsChecked} students. This confirms the Gale-Shapley algorithm produced a correct result.`;
    } else {
        summaryEl.className = 'verifier-summary unstable';
        summaryEl.innerHTML = `✗ <strong>MATCHING IS UNSTABLE.</strong> Found ${blockingPairs.length} blocking pair${blockingPairs.length===1?'':'s'}. This indicates a bug in the matching engine.`;
    }
});


// ===========================================================
//  ANALYTICS
// ===========================================================
function renderAnalytics() {
    // KPIs
    const studentsWithPrefs = Object.keys(STUDENT_PREFS).length;
    const matched = Object.keys(FINAL_MATCH).length;
    const unmatched = studentsWithPrefs - matched;
    const matchRate = studentsWithPrefs > 0 ? Math.round(matched * 100 / studentsWithPrefs) : 0;

    // Preference rank distribution (only for matched students)
    const rankHist = {};   // rank_position (1-indexed) -> count
    let totalRank = 0, countedRank = 0;
    for (const [sidStr, oid] of Object.entries(FINAL_MATCH)) {
        const prefs = STUDENT_PREFS[sidStr];
        if (!prefs) continue;
        const idx = prefs.indexOf(oid);
        if (idx < 0) continue;
        const rank = idx + 1;
        rankHist[rank] = (rankHist[rank] || 0) + 1;
        totalRank += rank; countedRank++;
    }
    const avgRank = countedRank > 0 ? (totalRank / countedRank).toFixed(2) : '—';

    const kpiHTML = `
        <div class="kpi"><div class="kpi-value">${matched}</div><div class="kpi-label">Matched</div></div>
        <div class="kpi"><div class="kpi-value">${unmatched}</div><div class="kpi-label">Unmatched</div></div>
        <div class="kpi"><div class="kpi-value">${matchRate}%</div><div class="kpi-label">Match rate</div></div>
        <div class="kpi"><div class="kpi-value">${avgRank}</div><div class="kpi-label">Avg preference rank achieved</div></div>
        <div class="kpi"><div class="kpi-value">${TRACE.length}</div><div class="kpi-label">Trace steps</div></div>`;
    document.getElementById('kpi-grid').innerHTML = kpiHTML;

    // Histogram of preference rank
    const maxRank = Math.max(1, ...Object.keys(rankHist).map(Number));
    const maxCount = Math.max(1, ...Object.values(rankHist));
    const histEl = document.getElementById('pref-histogram');
    histEl.innerHTML = '';
    for (let r = 1; r <= maxRank; r++) {
        const count = rankHist[r] || 0;
        const pct = Math.round(count * 100 / maxCount);
        histEl.innerHTML += `
            <div class="hist-row">
                <div>Rank #${r}</div>
                <div><div class="hist-bar" style="width:${pct}%"></div></div>
                <div class="hist-count">${count} student${count===1?'':'s'}</div>
            </div>`;
    }

    // Outcome breakdown
    const outcomes = {};
    for (const t of TRACE) { outcomes[t.outcome] = (outcomes[t.outcome] || 0) + 1; }
    const totalOut = TRACE.length || 1;
    const oEl = document.getElementById('outcome-bars');
    oEl.innerHTML = '';
    const labels = {
        accepted: 'Accepted', bumped: 'Bumped (via accept)',
        rejected_full: 'Rejected (offer full)', rejected_unranked: 'Rejected (unranked)'
    };
    for (const key of ['accepted', 'rejected_full', 'rejected_unranked']) {
        const count = outcomes[key] || 0;
        const pct = Math.round(count * 100 / totalOut);
        oEl.innerHTML += `
            <div class="hist-row">
                <div>${labels[key]}</div>
                <div><div class="hist-bar b-${key}" style="width:${pct}%"></div></div>
                <div class="hist-count">${count}  (${pct}%)</div>
            </div>`;
    }

    // Unmatched students
    const unmatchedEl = document.getElementById('unmatched-list');
    const unmatchedSids = Object.keys(STUDENT_PREFS)
        .map(Number)
        .filter(sid => !(sid in FINAL_MATCH));
    if (unmatchedSids.length === 0) {
        unmatchedEl.innerHTML = '<p style="color:var(--slate);">Every student with preferences got matched.</p>';
    } else {
        let html = '<table><thead><tr><th>Student</th><th>Preferences submitted</th><th>Likely reason</th></tr></thead><tbody>';
        for (const sid of unmatchedSids) {
            const s = STUDENTS[sid];
            const prefCount = STUDENT_PREFS[sid].length;
            // Diagnose: were they ranked by any of their preferred offers?
            const rankedBySome = STUDENT_PREFS[sid].some(oid => OFFER_RANKS[oid]?.includes(sid));
            const reason = rankedBySome
                ? 'Ranked by preferred offer(s), but bumped by better proposers all the way down the list.'
                : 'None of their preferred offers ranked them — they were rejected everywhere as unranked.';
            html += `<tr>
                <td>${s?.name ?? '#'+sid}<div class="offer-meta">${s?.matricola ?? ''}</div></td>
                <td>${prefCount}</td>
                <td style="font-size:12px;color:var(--slate);">${reason}</td>
            </tr>`;
        }
        html += '</tbody></table>';
        unmatchedEl.innerHTML = html;
    }
}
</script>

<?php require '../includes/footer.php'; ?>