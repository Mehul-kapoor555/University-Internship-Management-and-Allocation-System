"""
Recommendation Engine — computes ranked internship recommendations for a student.

Pipeline:
  1. Load student data + all open offers from MySQL
  2. Eligibility gate (deterministic boolean filter)
  3. Skill coverage score (our formula, feeds off ESCO skills)
  4. Academic fit score (our formula, uses verified academic records)
  5. Preference alignment score (our formula, deterministic rules)
  6. Semantic similarity score (Cat-2: uses MiniLM via semantic_engine)
  7. Weighted final score = 0.35 * semantic + 0.35 * coverage + 0.20 * academic + 0.10 * preference
  8. Write ranked scores + competency gaps to DB

Usage: python recommendation_engine.py --student-id 6
"""

import argparse
import json
import sys
import time
from typing import Dict, List, Tuple, Optional

import mysql.connector

import semantic_engine

from config import DB_CONFIG


# Weights for the final scoring formula. Documented in the report.
WEIGHT_SEMANTIC   = 0.35
WEIGHT_COVERAGE   = 0.35
WEIGHT_ACADEMIC   = 0.20
WEIGHT_PREFERENCE = 0.10

# Proficiency ordering (higher = better)
PROF_RANK = {'beginner': 1, 'intermediate': 2, 'advanced': 3}


# ============================================================
# DATA LOADING
# ============================================================

def load_student(cur, student_id: int) -> Optional[dict]:
    cur.execute("""
        SELECT s.id, s.matricola, s.program, s.total_cfu, s.competency_description,
               u.first_name, u.last_name
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = %s AND s.registration_status = 'approved'
    """, (student_id,))
    row = cur.fetchone()
    if not row:
        return None

    # Student's own skills
    cur.execute("""
        SELECT skill_id, proficiency FROM student_skills WHERE student_id = %s
    """, (student_id,))
    row['skills'] = {r['skill_id']: r['proficiency'] for r in cur.fetchall()}

    # Average grade across verified records (for academic fit)
    cur.execute("""
        SELECT grade FROM academic_records
        WHERE student_id = %s AND verified_by_user_id IS NOT NULL AND grade IS NOT NULL
    """, (student_id,))
    grades = []
    for r in cur.fetchall():
        # Grades like "28/30" -> take the numerator
        try:
            num = r['grade'].split('/')[0].strip()
            grades.append(float(num))
        except (ValueError, IndexError, AttributeError):
            continue
    row['avg_grade'] = sum(grades) / len(grades) if grades else None

    return row


def load_open_offers(cur) -> List[dict]:
    cur.execute("""
        SELECT o.id, o.title, o.description, o.domain, o.type, o.capacity, o.filled,
               o.min_cfu, o.required_program, o.duration_months, o.is_paid,
               o.is_remote, o.application_deadline
        FROM internship_offers o
        WHERE o.status = 'open'
    """)
    offers = cur.fetchall()

    if not offers:
        return []

    offer_ids = tuple(o['id'] for o in offers)
    placeholders = ','.join(['%s'] * len(offer_ids))
    cur.execute(f"""
        SELECT offer_id, skill_id, importance, minimum_proficiency
        FROM offer_skills WHERE offer_id IN ({placeholders})
    """, offer_ids)
    skills_by_offer = {}
    for r in cur.fetchall():
        skills_by_offer.setdefault(r['offer_id'], []).append(r)
    for o in offers:
        o['skills'] = skills_by_offer.get(o['id'], [])
    return offers


# ============================================================
# STAGE 1 — Eligibility gate (deterministic)
# ============================================================

def is_eligible(student: dict, offer: dict) -> Tuple[bool, Optional[str]]:
    """
    Deterministic boolean predicate. Returns (eligible, reason_if_not).
    This is NOT a scoring signal — it's a hard filter. Ineligible offers
    are dropped entirely, not just downranked.
    """
    if offer['min_cfu'] and student['total_cfu'] < offer['min_cfu']:
        return False, f"CFU too low ({student['total_cfu']} < required {offer['min_cfu']})"

    if offer['filled'] >= offer['capacity']:
        return False, "Offer already filled"

    if offer['required_program'] and offer['required_program'].strip() \
       and offer['required_program'].lower() != student['program'].lower():
        return False, f"Program mismatch (needs {offer['required_program']})"

    from datetime import date
    if offer['application_deadline'] and offer['application_deadline'] < date.today():
        return False, "Application deadline passed"

    return True, None


# ============================================================
# STAGE 2 — Skill coverage (our formula, uses ESCO IDs)
# ============================================================

def skill_coverage_score(student: dict, offer: dict) -> Tuple[float, List[dict]]:
    """
    Four-outcome skill matching model:
      - required + at-or-above-min : +1.0
      - required + below-min       : +0.5  (insufficient)
      - required + missing         : +0.0
      - preferred + at-or-above    : +0.3
      - preferred + below-min      : +0.1
      - preferred + missing        : +0.0

    Score is normalized by max possible score.
    Also returns gaps: list of {skill_id, gap_type} for the competency report.
    """
    if not offer['skills']:
        return 1.0, []   # no skills required -> full coverage by definition

    total_score = 0.0
    max_score = 0.0
    gaps = []

    for req in offer['skills']:
        skill_id = req['skill_id']
        min_needed = PROF_RANK[req['minimum_proficiency']]
        student_level = PROF_RANK.get(student['skills'].get(skill_id), 0)

        if req['importance'] == 'required':
            max_score += 1.0
            if student_level == 0:
                # missing
                gaps.append({'skill_id': skill_id, 'gap_type': 'missing'})
            elif student_level >= min_needed:
                total_score += 1.0
            else:
                total_score += 0.5
                gaps.append({'skill_id': skill_id, 'gap_type': 'insufficient'})
        else:  # preferred
            max_score += 0.3
            if student_level == 0:
                pass  # preferred + missing is fine, no gap flag
            elif student_level >= min_needed:
                total_score += 0.3
            else:
                total_score += 0.1

    normalized = total_score / max_score if max_score > 0 else 1.0
    return normalized, gaps


# ============================================================
# STAGE 3 — Academic fit (our formula)
# ============================================================

def academic_fit_score(student: dict, offer: dict) -> float:
    """
    Combines CFU margin and average grade quality.
      cfu_margin  = min(1.0, (student_cfu - offer_min_cfu) / offer_min_cfu)
      grade_qual  = (avg_grade - 18) / (30 - 18)   [Italian grade scale 18-30]
      score       = 0.6 * cfu_margin + 0.4 * grade_qual
    """
    min_cfu = offer['min_cfu'] or 0
    if min_cfu > 0:
        cfu_margin = min(1.0, max(0.0, (student['total_cfu'] - min_cfu) / min_cfu))
    else:
        cfu_margin = 1.0

    if student['avg_grade'] is not None:
        grade_qual = max(0.0, min(1.0, (student['avg_grade'] - 18.0) / 12.0))
    else:
        grade_qual = 0.5  # neutral default if no grades on file

    return 0.6 * cfu_margin + 0.4 * grade_qual


# ============================================================
# STAGE 4 — Preference alignment (our formula, tiebreaker signal)
# ============================================================

def preference_alignment_score(offer: dict) -> float:
    """
    Simple deterministic bonuses. Not the dominant signal — just breaks ties.
      +0.4 if paid
      +0.2 if remote-friendly
      +0.2 for shorter durations (<=4 months)
      +0.2 for near-term deadlines (<30 days)
    """
    score = 0.0
    if offer['is_paid']:
        score += 0.4
    if offer['is_remote']:
        score += 0.2
    if offer['duration_months'] and offer['duration_months'] <= 4:
        score += 0.2

    if offer['application_deadline']:
        from datetime import date
        days_left = (offer['application_deadline'] - date.today()).days
        if 0 <= days_left <= 30:
            score += 0.2

    return min(1.0, score)


# ============================================================
# EXPLANATION STRINGS
# ============================================================

def make_explanation(final_score: float, semantic: float, coverage: float,
                     academic: float, preference: float,
                     matched: int, required_total: int,
                     gaps: list) -> str:
    parts = []

    # Overall verdict
    if final_score >= 0.75:
        parts.append("Strong overall match.")
    elif final_score >= 0.55:
        parts.append("Solid match.")
    elif final_score >= 0.35:
        parts.append("Moderate match.")
    else:
        parts.append("Weak match — consider strengthening your profile first.")

    # Skill coverage detail
    if required_total > 0:
        parts.append(f"You meet {matched}/{required_total} required skills.")
    if gaps:
        missing = sum(1 for g in gaps if g['gap_type'] == 'missing')
        insuff = sum(1 for g in gaps if g['gap_type'] == 'insufficient')
        if missing > 0:
            parts.append(f"{missing} required skill(s) missing.")
        if insuff > 0:
            parts.append(f"{insuff} required skill(s) below the requested level.")

    # Semantic signal
    if semantic >= 0.65:
        parts.append("Your interests align well with this offer.")
    elif semantic <= 0.30:
        parts.append("Your description doesn't strongly overlap this offer's topic.")

    return " ".join(parts)


# ============================================================
# MAIN PIPELINE
# ============================================================

def run(student_id: int) -> dict:
    started = time.time()

    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)

    student = load_student(cur, student_id)
    if not student:
        cur.close(); conn.close()
        return {'error': f'Student {student_id} not found or not approved'}

    all_offers = load_open_offers(cur)

    # Stage 1: Eligibility
    eligible = []
    eligibility_records = []
    for o in all_offers:
        ok, reason = is_eligible(student, o)
        eligibility_records.append((student_id, o['id'], 1 if ok else 0, reason))
        if ok:
            eligible.append(o)

    # Persist eligibility (audit trail — the coordinator can inspect why an
    # offer was excluded for a given student).
    cur.execute("DELETE FROM eligibility WHERE student_id = %s", (student_id,))
    if eligibility_records:
        cur.executemany(
            "INSERT INTO eligibility (student_id, offer_id, is_eligible, reason) "
            "VALUES (%s, %s, %s, %s)",
            eligibility_records
        )

    # Clear old recommendation results for this student before writing fresh ones.
    cur.execute("DELETE FROM recommendation_scores WHERE student_id = %s", (student_id,))
    cur.execute("DELETE FROM similarity_scores WHERE student_id = %s", (student_id,))
    cur.execute("DELETE FROM competency_gaps WHERE student_id = %s", (student_id,))

    if not eligible:
        conn.commit(); cur.close(); conn.close()
        return {'student_id': student_id, 'recommendations_written': 0,
                'eligible_count': 0, 'total_offers': len(all_offers),
                'duration_ms': int((time.time() - started) * 1000)}

    # Stage 2, 3, 4: our own formulas (no ML)
    coverage_scores = []
    academic_scores = []
    preference_scores = []
    per_offer_gaps = []
    per_offer_required_stats = []

    for o in eligible:
        cov, gaps = skill_coverage_score(student, o)
        coverage_scores.append(cov)
        academic_scores.append(academic_fit_score(student, o))
        preference_scores.append(preference_alignment_score(o))

        # For explanation strings
        required_total = sum(1 for s in o['skills'] if s['importance'] == 'required')
        matched = sum(
            1 for s in o['skills']
            if s['importance'] == 'required'
            and PROF_RANK.get(student['skills'].get(s['skill_id']), 0) >= PROF_RANK[s['minimum_proficiency']]
        )
        per_offer_required_stats.append((matched, required_total))
        per_offer_gaps.append(gaps)

    # Stage 5: semantic similarity via MiniLM (Cat-2 pretrained model)
    anchor_text = student.get('competency_description') or \
        f"Student in {student['program']}"
    offer_texts = [
        (o['description'] or '') + ' ' + (o['domain'] or '') + ' ' + (o['title'] or '')
        for o in eligible
    ]
    semantic_scores = semantic_engine.similarity_matrix(anchor_text, offer_texts)

    # Stage 6: weighted final score
    final_scores = []
    for i, o in enumerate(eligible):
        # Semantic returns [-1, 1] but is almost always in [0, 1] for real descriptions.
        # Clip to [0, 1] to keep the weighted sum interpretable.
        sem = max(0.0, min(1.0, semantic_scores[i]))
        final = (
            WEIGHT_SEMANTIC   * sem              +
            WEIGHT_COVERAGE   * coverage_scores[i] +
            WEIGHT_ACADEMIC   * academic_scores[i] +
            WEIGHT_PREFERENCE * preference_scores[i]
        )
        final_scores.append((o, sem, coverage_scores[i], academic_scores[i],
                             preference_scores[i], final, per_offer_gaps[i],
                             per_offer_required_stats[i]))

    # Sort by final score, descending
    final_scores.sort(key=lambda x: x[5], reverse=True)

    # Write to DB
    for rank, (o, sem, cov, acad, pref, final, gaps, req_stats) in enumerate(final_scores, start=1):
        matched, required_total = req_stats
        explanation = make_explanation(final, sem, cov, acad, pref, matched, required_total, gaps)

        cur.execute(
            "INSERT INTO similarity_scores (student_id, offer_id, cosine_similarity, coverage) "
            "VALUES (%s, %s, %s, %s)",
            (student_id, o['id'], round(sem, 5), round(cov, 4))
        )

        cur.execute(
            "INSERT INTO recommendation_scores (student_id, offer_id, score, explanation, rank_position) "
            "VALUES (%s, %s, %s, %s, %s)",
            (student_id, o['id'], round(final, 4), explanation, rank)
        )

        if gaps:
            cur.executemany(
                "INSERT INTO competency_gaps (student_id, offer_id, skill_id, gap_type) "
                "VALUES (%s, %s, %s, %s)",
                [(student_id, o['id'], g['skill_id'], g['gap_type']) for g in gaps]
            )

    conn.commit()
    cur.close()
    conn.close()

    return {
        'student_id': student_id,
        'recommendations_written': len(final_scores),
        'eligible_count': len(eligible),
        'total_offers': len(all_offers),
        'duration_ms': int((time.time() - started) * 1000),
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--student-id', type=int, required=True)
    args = parser.parse_args()
    result = run(args.student_id)
    # JSON on stdout — PHP parses this
    print(json.dumps(result))