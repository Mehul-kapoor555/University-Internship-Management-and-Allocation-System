"""
Applicant Ranking Engine — for one offer, rank the students who applied.

Same four signals as recommendation_engine.py, but the perspective is inverted:
  - anchor text is the offer description
  - candidate texts are the students' competency descriptions
  - skill coverage checks whether each applicant meets the offer's requirements
  - academic fit is the same formula
  - preference alignment doesn't apply here (that's a student-side signal),
    so we replace it with an "application recency" bonus (recent applicants
    get a small edge — this is a marginal tiebreaker only).

Writes results to applicant_rankings table (see migration below).
Usage: python applicant_ranking_engine.py --offer-id 3
"""

import argparse
import json
import sys
import time
from datetime import date
from typing import Optional

import mysql.connector

import semantic_engine

from config import DB_CONFIG

WEIGHT_SEMANTIC = 0.35
WEIGHT_COVERAGE = 0.35
WEIGHT_ACADEMIC = 0.20
WEIGHT_RECENCY  = 0.10

PROF_RANK = {'beginner': 1, 'intermediate': 2, 'advanced': 3}


def load_offer(cur, offer_id: int) -> Optional[dict]:
    cur.execute("""
        SELECT o.id, o.title, o.description, o.domain, o.min_cfu, o.required_program
        FROM internship_offers o
        WHERE o.id = %s
    """, (offer_id,))
    offer = cur.fetchone()
    if not offer:
        return None

    cur.execute("""
        SELECT skill_id, importance, minimum_proficiency
        FROM offer_skills WHERE offer_id = %s
    """, (offer_id,))
    offer['skills'] = cur.fetchall()
    return offer


def load_applicants(cur, offer_id: int) -> list:
    # Only this cycle's applicants. An offer can stay open across cycles, and
    # without the cycle filter it would be scored against applicants from an
    # earlier cycle as well. The subquery mirrors activeCycleId() in PHP.
    cur.execute("""
        SELECT a.id AS application_id, a.status, a.applied_at, a.cover_letter,
               s.id AS student_id, s.matricola, s.program, s.total_cfu,
               s.competency_description,
               u.first_name, u.last_name
        FROM applications a
        JOIN students s ON s.id = a.student_id
        JOIN users u ON u.id = s.user_id
        WHERE a.offer_id = %s AND a.status != 'withdrawn'
          AND a.cycle_id = (
              SELECT id FROM academic_cycles
              WHERE is_active = 1 ORDER BY id DESC LIMIT 1
          )
        ORDER BY a.applied_at ASC
    """, (offer_id,))
    applicants = cur.fetchall()

    for app in applicants:
        cur.execute("SELECT skill_id, proficiency FROM student_skills WHERE student_id = %s",
                    (app['student_id'],))
        app['skills'] = {r['skill_id']: r['proficiency'] for r in cur.fetchall()}

        cur.execute("""
            SELECT grade FROM academic_records
            WHERE student_id = %s AND verified_by_user_id IS NOT NULL AND grade IS NOT NULL
        """, (app['student_id'],))
        grades = []
        for r in cur.fetchall():
            try:
                grades.append(float(r['grade'].split('/')[0].strip()))
            except (ValueError, IndexError, AttributeError):
                pass
        app['avg_grade'] = sum(grades) / len(grades) if grades else None

    return applicants


def skill_coverage(offer: dict, applicant: dict) -> float:
    if not offer['skills']:
        return 1.0
    total = 0.0
    max_total = 0.0
    for req in offer['skills']:
        min_needed = PROF_RANK[req['minimum_proficiency']]
        stu_level = PROF_RANK.get(applicant['skills'].get(req['skill_id']), 0)
        if req['importance'] == 'required':
            max_total += 1.0
            if stu_level >= min_needed and stu_level > 0:
                total += 1.0
            elif stu_level > 0:
                total += 0.5
        else:
            max_total += 0.3
            if stu_level >= min_needed and stu_level > 0:
                total += 0.3
            elif stu_level > 0:
                total += 0.1
    return total / max_total if max_total > 0 else 1.0


def academic_fit(offer: dict, applicant: dict) -> float:
    min_cfu = offer['min_cfu'] or 0
    if min_cfu > 0:
        cfu_margin = min(1.0, max(0.0, (applicant['total_cfu'] - min_cfu) / min_cfu))
    else:
        cfu_margin = 1.0
    if applicant['avg_grade'] is not None:
        grade_qual = max(0.0, min(1.0, (applicant['avg_grade'] - 18.0) / 12.0))
    else:
        grade_qual = 0.5
    return 0.6 * cfu_margin + 0.4 * grade_qual


def recency_bonus(applicant: dict, all_dates: list) -> float:
    """Smaller time-since-application = higher bonus. Marginal signal."""
    if not all_dates:
        return 0.5
    earliest = min(all_dates)
    latest = max(all_dates)
    span = (latest - earliest).total_seconds() or 1
    age = (latest - applicant['applied_at']).total_seconds()
    return 1.0 - (age / span)  # newest = 1.0, oldest = 0.0


def make_explanation(final, sem, cov, acad, matched, required_total, applicant) -> str:
    parts = []
    if final >= 0.75:
        parts.append("Strong candidate.")
    elif final >= 0.55:
        parts.append("Good fit.")
    elif final >= 0.35:
        parts.append("Moderate fit — worth reviewing.")
    else:
        parts.append("Weak fit against requirements.")
    if required_total > 0:
        parts.append(f"Meets {matched}/{required_total} required skills.")
    if sem >= 0.60:
        parts.append("Description aligns well with the offer.")
    return " ".join(parts)


def run(offer_id: int) -> dict:
    started = time.time()

    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)

    offer = load_offer(cur, offer_id)
    if not offer:
        cur.close(); conn.close()
        return {'error': f'Offer {offer_id} not found'}

    applicants = load_applicants(cur, offer_id)
    if not applicants:
        cur.execute("DELETE FROM applicant_rankings WHERE offer_id = %s", (offer_id,))
        conn.commit(); cur.close(); conn.close()
        return {'offer_id': offer_id, 'applicants_ranked': 0,
                'duration_ms': int((time.time() - started) * 1000)}

    all_dates = [a['applied_at'] for a in applicants]

    # Semantic pass
    offer_text = (offer['description'] or '') + ' ' + (offer['domain'] or '') + ' ' + (offer['title'] or '')
    applicant_texts = [(a['competency_description'] or f"Student in {a['program']}") for a in applicants]
    sem_scores = semantic_engine.similarity_matrix(offer_text, applicant_texts)

    # Compute scores
    computed = []
    for i, app in enumerate(applicants):
        sem = max(0.0, min(1.0, sem_scores[i]))
        cov = skill_coverage(offer, app)
        acad = academic_fit(offer, app)
        rec = recency_bonus(app, all_dates)
        final = (WEIGHT_SEMANTIC * sem + WEIGHT_COVERAGE * cov
                 + WEIGHT_ACADEMIC * acad + WEIGHT_RECENCY * rec)

        required_total = sum(1 for s in offer['skills'] if s['importance'] == 'required')
        matched = sum(
            1 for s in offer['skills']
            if s['importance'] == 'required'
            and PROF_RANK.get(app['skills'].get(s['skill_id']), 0) >= PROF_RANK[s['minimum_proficiency']]
        )
        explanation = make_explanation(final, sem, cov, acad, matched, required_total, app)

        computed.append({
            'application_id': app['application_id'],
            'student_id': app['student_id'],
            'sem': round(sem, 5), 'cov': round(cov, 4),
            'acad': round(acad, 4), 'rec': round(rec, 4),
            'final': round(final, 4), 'explanation': explanation,
        })

    computed.sort(key=lambda x: x['final'], reverse=True)

    # Persist
    cur.execute("DELETE FROM applicant_rankings WHERE offer_id = %s", (offer_id,))
    for rank, c in enumerate(computed, start=1):
        cur.execute("""
            INSERT INTO applicant_rankings
              (offer_id, application_id, student_id, semantic_score, coverage_score,
               academic_score, recency_score, final_score, rank_position, explanation)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (offer_id, c['application_id'], c['student_id'], c['sem'], c['cov'],
              c['acad'], c['rec'], c['final'], rank, c['explanation']))

    conn.commit()
    cur.close()
    conn.close()

    return {'offer_id': offer_id, 'applicants_ranked': len(computed),
            'duration_ms': int((time.time() - started) * 1000)}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--offer-id', type=int, required=True)
    args = parser.parse_args()
    print(json.dumps(run(args.offer_id)))