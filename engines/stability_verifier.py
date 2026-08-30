"""
Stability Verifier — independent check that a matching produced by
matching_engine.py contains no blocking pairs.

A blocking pair (s, o) exists when BOTH sides would prefer each other over
their current assignment:
  1. Student s prefers offer o to their current match (or is unmatched)
  2. Offer o has spare capacity, OR prefers s to at least one current holder

If any blocking pair exists, the matching is unstable — this should never
happen for a correct Gale-Shapley implementation. The verifier is a proof.

Usage: python stability_verifier.py --round-id 1
"""

import argparse
import json
import time

import mysql.connector


from config import DB_CONFIG


def run(round_id: int) -> dict:
    started = time.time()
    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)

    # Load the round
    cur.execute("SELECT id, cycle_id FROM matching_rounds WHERE id = %s", (round_id,))
    round_row = cur.fetchone()
    if not round_row:
        cur.close(); conn.close()
        return {'error': f'Round {round_id} not found'}

    # Load current matching
    cur.execute("""
        SELECT student_id, offer_id FROM allocations WHERE round_id = %s
    """, (round_id,))
    student_of = {}
    matching = {}
    for row in cur.fetchall():
        student_of[row['student_id']] = row['offer_id']
        matching.setdefault(row['offer_id'], []).append(row['student_id'])

    # Load student preferences
    cur.execute("""
        SELECT sp.student_id, sp.offer_id, sp.`rank`
        FROM student_preferences sp
        JOIN internship_offers o ON o.id = sp.offer_id
        WHERE o.status = 'open'
        ORDER BY sp.student_id, sp.`rank` ASC
    """)
    student_prefs = {}
    for row in cur.fetchall():
        student_prefs.setdefault(row['student_id'], []).append(row['offer_id'])

    # Load offer rankings
    cur.execute("""
        SELECT o_rank.offer_id, o_rank.student_id, o_rank.`rank`
        FROM offer_rankings o_rank
        JOIN internship_offers o ON o.id = o_rank.offer_id
        WHERE o.status = 'open'
        ORDER BY o_rank.offer_id, o_rank.`rank` ASC
    """)
    offer_rank_of = {}
    for row in cur.fetchall():
        offer_rank_of.setdefault(row['offer_id'], {})[row['student_id']] = row['rank']

    # Load offer capacities
    cur.execute("SELECT id, capacity, filled FROM internship_offers WHERE status = 'open'")
    capacities = {}
    prior_filled = {}
    for row in cur.fetchall():
        capacities[row['id']] = int(row['capacity'])
        prior_filled[row['id']] = int(row['filled'])

    # Rank position of student's current match, for comparison
    student_current_rank = {}
    for sid, prefs in student_prefs.items():
        cur_oid = student_of.get(sid)
        for r, oid in enumerate(prefs):
            if oid == cur_oid:
                student_current_rank[sid] = r
                break
        # If unmatched, treat rank as infinity (any offer is "better")
        student_current_rank.setdefault(sid, float('inf'))

    # Scan for blocking pairs
    blocking_pairs = []

    for sid, prefs in student_prefs.items():
        current_rank = student_current_rank[sid]

        # Check every offer ranked higher (lower index) than current match
        for r, oid in enumerate(prefs):
            if r >= current_rank:
                break  # student is happy with current

            if oid not in offer_rank_of or sid not in offer_rank_of[oid]:
                continue

            current_holders = matching.get(oid, [])
            available = max(0, capacities[oid] - prior_filled.get(oid, 0))

            # Would this offer accept sid?
            #   yes if: (a) offer has an empty slot, OR
            #           (b) offer ranks sid above at least one current holder
            if len(current_holders) < available:
                blocking_pairs.append({'student_id': sid, 'offer_id': oid, 'reason': 'offer has spare capacity'})
                continue

            s_rank = offer_rank_of[oid][sid]
            for holder in current_holders:
                h_rank = offer_rank_of[oid].get(holder, float('inf'))
                if s_rank < h_rank:
                    blocking_pairs.append({
                        'student_id': sid, 'offer_id': oid,
                        'reason': f'offer ranks student above current holder {holder}'
                    })
                    break

    # Persist result
    cur.execute("""
        UPDATE matching_rounds SET blocking_pairs = %s, status = %s
        WHERE id = %s
    """, (len(blocking_pairs), 'stable' if not blocking_pairs else 'unstable', round_id))

    conn.commit()
    cur.close()
    conn.close()

    return {
        'round_id': round_id,
        'blocking_pairs_count': len(blocking_pairs),
        'is_stable': len(blocking_pairs) == 0,
        'blocking_pairs': blocking_pairs[:10],  # cap payload — usually 0
        'duration_ms': int((time.time() - started) * 1000),
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--round-id', type=int, required=True)
    args = parser.parse_args()
    print(json.dumps(run(args.round_id)))