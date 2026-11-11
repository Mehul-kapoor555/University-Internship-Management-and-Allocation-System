"""
Matching Engine — Gale-Shapley deferred-acceptance, many-to-one, student-proposing.

Category-3 component: fully implemented from scratch, no external algorithm libraries.
Uses only mysql-connector for DB access.

Algorithm:
  Each free student proposes to their top-choice remaining offer.
  Each offer keeps a heap of its k best current holders (k = capacity).
  A new proposer either fills a vacant slot or displaces the worst current holder
    (if the offer prefers the proposer). Displaced students go back to the free pool.
  Terminates when no free student has any offer left to propose to.
  Complexity: O(n * m) where n = students, m = offers.

Guarantees:
  - Stable matching (no blocking pair) — verified by a separate script.
  - Student-optimal (each student gets the best offer they can stably get).
  - Deterministic (same inputs → same outputs).

Every step is logged to matching_trace for full auditability.

Usage: python matching_engine.py --cycle-id 1
"""

import argparse
import json
import sys
import time
from collections import deque
from typing import Dict, List, Tuple

import mysql.connector


from config import DB_CONFIG


# ============================================================
# DATA LOADING
# ============================================================

def load_matching_data(cur, cycle_id: int):
    """
    Loads all preferences and capacities for the given academic cycle.
    Returns: (student_prefs, offer_prefs, offer_capacities, offer_current_filled)
    """
    # Student preferences (ranked list of offer_ids per student).
    # Scoped to this cycle: preferences and rankings carry a cycle_id, and
    # without the filter one cycle's matching read every other cycle's rows.
    cur.execute("""
        SELECT sp.student_id, sp.offer_id, sp.`rank`
        FROM student_preferences sp
        JOIN internship_offers o ON o.id = sp.offer_id
        WHERE o.status = 'open' AND sp.cycle_id = %s
        ORDER BY sp.student_id, sp.`rank` ASC
    """, (cycle_id,))
    student_prefs = {}
    for row in cur.fetchall():
        student_prefs.setdefault(row['student_id'], []).append(row['offer_id'])

    # Offer rankings (ranked list of student_ids per offer)
    cur.execute("""
        SELECT o_rank.offer_id, o_rank.student_id, o_rank.`rank`
        FROM offer_rankings o_rank
        JOIN internship_offers o ON o.id = o_rank.offer_id
        WHERE o.status = 'open' AND o_rank.cycle_id = %s
        ORDER BY o_rank.offer_id, o_rank.`rank` ASC
    """, (cycle_id,))
    offer_prefs = {}
    for row in cur.fetchall():
        offer_prefs.setdefault(row['offer_id'], []).append(row['student_id'])

    # Offer capacities & already-filled counts (some offers may have prior placements)
    cur.execute("""
        SELECT id, capacity, filled
        FROM internship_offers
        WHERE status = 'open'
    """)
    offer_capacities = {}
    offer_current_filled = {}
    for row in cur.fetchall():
        offer_capacities[row['id']] = int(row['capacity'])
        offer_current_filled[row['id']] = int(row['filled'])

    return student_prefs, offer_prefs, offer_capacities, offer_current_filled


# ============================================================
# THE ALGORITHM — Gale-Shapley many-to-one, student-proposing
# ============================================================

def gale_shapley(student_prefs: Dict[int, List[int]],
                 offer_prefs: Dict[int, List[int]],
                 offer_capacities: Dict[int, int],
                 offer_current_filled: Dict[int, int]):
    """
    Runs the deferred-acceptance algorithm.

    Returns:
        matching:   dict {offer_id: [student_id, ...]}
        assigned:   dict {student_id: offer_id or None}
        trace:      list of (student_id, offer_id, outcome, bumped_student_id_or_None)
    """
    # Precompute: for each offer, a fast lookup of "how does this offer rank this student?"
    # Lower rank number = more preferred. Missing student = not ranked = won't consider.
    offer_rank_of = {
        oid: {sid: r for r, sid in enumerate(prefs)}
        for oid, prefs in offer_prefs.items()
    }

    # Next-to-propose index per student
    next_prop = {sid: 0 for sid in student_prefs}

    # Currently held students per offer (tentative acceptances)
    holds: Dict[int, List[int]] = {oid: [] for oid in offer_prefs}
    # Any offer with capacity but no prefs at all still gets an entry so we can insert
    for oid in offer_capacities:
        holds.setdefault(oid, [])

    # Available capacity = max_capacity - already_filled_from_prior_rounds
    available_slots = {
        oid: max(0, offer_capacities[oid] - offer_current_filled.get(oid, 0))
        for oid in offer_capacities
    }

    # Track current match per student
    assigned = {sid: None for sid in student_prefs}

    # FIFO queue of free students who still have preferences left to try
    free = deque(sid for sid, prefs in student_prefs.items() if prefs)

    trace = []

    while free:
        sid = free.popleft()
        idx = next_prop[sid]

        # Exhausted preference list — stays unmatched
        if idx >= len(student_prefs[sid]):
            continue

        oid = student_prefs[sid][idx]
        next_prop[sid] += 1

        # Offer must exist and must have ranked this student
        if oid not in offer_rank_of or sid not in offer_rank_of[oid]:
            trace.append((sid, oid, 'rejected_unranked', None))
            free.append(sid)  # try their next choice
            continue

        cap = available_slots.get(oid, 0)
        held = holds[oid]

        if len(held) < cap:
            # There's a vacant slot — tentatively accept
            held.append(sid)
            assigned[sid] = oid
            trace.append((sid, oid, 'accepted', None))
        else:
            if cap == 0:
                # Offer has no capacity at all this cycle
                trace.append((sid, oid, 'rejected_full', None))
                free.append(sid)
                continue

            # Offer full — compare proposer to worst current holder
            worst_sid = max(held, key=lambda s: offer_rank_of[oid][s])
            if offer_rank_of[oid][sid] < offer_rank_of[oid][worst_sid]:
                # Proposer is preferred → bump the worst holder
                held.remove(worst_sid)
                held.append(sid)
                assigned[sid] = oid
                assigned[worst_sid] = None
                free.append(worst_sid)  # displaced student goes back to try next
                trace.append((sid, oid, 'accepted', worst_sid))
            else:
                trace.append((sid, oid, 'rejected_full', None))
                free.append(sid)

    return holds, assigned, trace


# ============================================================
# PERSISTENCE
# ============================================================

def create_matching_round(cur, cycle_id: int) -> int:
    cur.execute("""
        INSERT INTO matching_rounds (cycle_id, status, run_at)
        VALUES (%s, 'running', NOW())
    """, (cycle_id,))
    return cur.lastrowid


def persist_results(cur, round_id: int, matching: Dict[int, List[int]],
                    trace: list):
    # Allocations
    for oid, sids in matching.items():
        for sid in sids:
            cur.execute("""
                INSERT INTO allocations (round_id, student_id, offer_id, status)
                VALUES (%s, %s, %s, 'tentative')
            """, (round_id, sid, oid))

    # Trace
    for step_num, (sid, oid, outcome, bumped) in enumerate(trace, start=1):
        cur.execute("""
            INSERT INTO matching_trace
              (round_id, step_number, student_id, offer_id, outcome, bumped_student_id)
            VALUES (%s, %s, %s, %s, %s, %s)
        """, (round_id, step_num, sid, oid, outcome, bumped))


# ============================================================
# MAIN
# ============================================================

def run(cycle_id: int) -> dict:
    started = time.time()

    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)

    # Confirm cycle exists
    cur.execute("SELECT id, name FROM academic_cycles WHERE id = %s", (cycle_id,))
    cycle = cur.fetchone()
    if not cycle:
        cur.close(); conn.close()
        return {'error': f'Cycle {cycle_id} not found'}

    student_prefs, offer_prefs, offer_capacities, offer_filled = load_matching_data(cur, cycle_id)

    # Anyone who applied this cycle but has no preference rows cannot be seen
    # by the algorithm at all — they are not ranked last, they are absent.
    # auto_ranking.php is supposed to make this impossible, so this count
    # should always be 0. It is reported either way rather than assumed, so a
    # student can never go missing without it showing up.
    cur.execute("""
        SELECT COUNT(DISTINCT a.student_id) AS n
        FROM applications a
        JOIN internship_offers o ON o.id = a.offer_id
        WHERE a.cycle_id = %s
          AND a.status <> 'withdrawn'
          AND o.status = 'open'
          AND a.student_id NOT IN (
              SELECT student_id FROM student_preferences WHERE cycle_id = %s
          )
    """, (cycle_id, cycle_id))
    applied_but_unranked = int(cur.fetchone()['n'])

    if not student_prefs:
        cur.close(); conn.close()
        return {'error': 'No student preferences found. Students need to rank offers before matching can run.'}

    if not offer_prefs:
        cur.close(); conn.close()
        return {'error': 'No offer rankings found. Offer posters need to rank applicants before matching can run.'}

    # Create the round record
    round_id = create_matching_round(cur, cycle_id)

    # Run the algorithm
    matching, assigned, trace = gale_shapley(
        student_prefs, offer_prefs, offer_capacities, offer_filled
    )

    # Persist
    persist_results(cur, round_id, matching, trace)

    # Update round status to 'stable' (verifier will confirm)
    matched_count = sum(1 for oid in matching for _ in matching[oid])
    unmatched_students = [sid for sid, oid in assigned.items() if oid is None]

    cur.execute("""
        UPDATE matching_rounds SET status = 'stable' WHERE id = %s
    """, (round_id,))

    conn.commit()
    cur.close()
    conn.close()

    return {
        'round_id': round_id,
        'cycle_id': cycle_id,
        'cycle_name': cycle['name'],
        'total_students': len(student_prefs),
        'matched_students': matched_count,
        'unmatched_students': len(unmatched_students),
        'applied_but_unranked': applied_but_unranked,
        'total_offers': len(offer_capacities),
        'offers_with_matches': sum(1 for oid in matching if matching[oid]),
        'trace_steps': len(trace),
        'duration_ms': int((time.time() - started) * 1000),
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--cycle-id', type=int, required=True)
    args = parser.parse_args()
    result = run(args.cycle_id)
    print(json.dumps(result))