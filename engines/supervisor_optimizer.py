"""
Supervisor Assignment Optimizer  (Category-3 feature #3)

PROBLEM
-------
After students accept their placements, every accepted placement needs exactly
one academic supervisor (a professor). We want an assignment that:
  - respects each professor's supervision_capacity (a HARD constraint),
  - only uses available professors (is_available = 1),
  - minimises a total COST that rewards domain fit and spreads load fairly.

This is a constrained assignment problem. We solve it with a classic two-phase
metaheuristic, the same shape described in the report:

  Phase 1 - GREEDY CONSTRUCTION
      Build a feasible assignment fast: walk the placements in a fixed order
      and give each one to the cheapest professor that still has spare capacity.

  Phase 2 - SWAP IMPROVEMENT (local search)
      Greedy is fast but rarely optimal. Repeatedly look for a pair of
      placements whose supervisors, if exchanged, lower the TOTAL cost without
      breaking capacity. Apply the single best improving swap, then look again.
      Stop when no swap improves the solution (a local optimum).

COST(professor p, placement a)
------------------------------
    cost = w_domain * domain_distance(p, a)
         + w_load   * load_penalty(p)

  domain_distance = 1 - cosine( embed(p.expertise_description),
                                embed(a.title + a.description + a.domain) )
        -> 0.0 when the professor's stated expertise matches the placement's
           subject perfectly, 1.0 when unrelated. Uses the same MiniLM encoder
           the matcher and recommender use (semantic_engine).

  load_penalty    = current_load / supervision_capacity  at the moment of the
        assignment. A professor who is already near capacity costs more, so the
        optimiser prefers to spread supervision rather than pile it on one person.

Lower total cost is better.

USAGE
-----
    python supervisor_optimizer.py --cycle-id 1
    python supervisor_optimizer.py --cycle-id 1 --dry-run    (compute, don't write)

OUTPUT (last stdout line is JSON, per the EngineRunner contract)
    {"assignments_written": 7, "total_cost": 2.9143, "greedy_cost": 3.1027,
     "swaps_applied": 2, "unassigned": 0, "duration_ms": 812}
"""

import argparse
import json
import time
from typing import Dict, List, Optional, Tuple

import mysql.connector

from config import DB_CONFIG
import semantic_engine


# Cost weights. Domain fit dominates; load balancing is a gentle tie-breaker.
W_DOMAIN = 0.8
W_LOAD = 0.2


# ============================================================
# DATA LOADING
# ============================================================

def load_placements(cur, cycle_id: int) -> List[dict]:
    """
    Accepted placements in this cycle that still need a supervisor.

    A placement is an allocation the student ACCEPTED. We skip any allocation
    that already has a supervisor_assignments row, so re-running the optimiser
    (e.g. after a second matching round) only assigns the new placements.
    """
    cur.execute("""
        SELECT a.id            AS allocation_id,
               a.student_id,
               o.id            AS offer_id,
               o.title,
               o.description,
               o.domain,
               CONCAT(u.first_name, ' ', u.last_name) AS student_name
        FROM allocations a
        JOIN matching_rounds r   ON r.id = a.round_id
        JOIN internship_offers o ON o.id = a.offer_id
        JOIN students s          ON s.id = a.student_id
        JOIN users u             ON u.id = s.user_id
        WHERE r.cycle_id = %s
          AND a.decision = 'accepted'
          AND a.id NOT IN (SELECT allocation_id FROM supervisor_assignments)
        ORDER BY a.id
    """, (cycle_id,))
    rows = cur.fetchall()
    for row in rows:
        parts = [row.get('title') or '', row.get('description') or '', row.get('domain') or '']
        row['text'] = '. '.join(p for p in parts if p).strip()
    return rows


def load_professors(cur) -> List[dict]:
    """
    Available professors with remaining capacity.

    remaining_capacity = supervision_capacity - current_load. Professors whose
    load already meets or exceeds their capacity, or who are unavailable, are
    excluded entirely -- they cannot take any placement.
    """
    cur.execute("""
        SELECT p.id AS professor_id,
               p.expertise_description,
               p.supervision_capacity,
               p.current_load,
               CONCAT(u.first_name, ' ', u.last_name) AS professor_name
        FROM professors p
        JOIN users u ON u.id = p.user_id
        WHERE p.is_available = 1
        ORDER BY p.id
    """)
    profs = []
    for row in cur.fetchall():
        remaining = int(row['supervision_capacity']) - int(row['current_load'])
        if remaining <= 0:
            continue
        row['remaining_capacity'] = remaining
        row['text'] = (row.get('expertise_description') or '').strip()
        profs.append(row)
    return profs


# ============================================================
# COST MATRIX
# ============================================================

def build_cost_matrix(placements: List[dict],
                      professors: List[dict]) -> Dict[int, Dict[int, float]]:
    """
    cost[allocation_id][professor_id] = W_DOMAIN * domain_distance
                                      + W_LOAD   * load_penalty

    domain_distance is 1 - cosine similarity between the professor's expertise
    text and the placement's text, both embedded once with MiniLM. Everything is
    encoded in a single batch for speed.
    """
    # Encode every professor and placement text exactly once.
    prof_texts = [p['text'] for p in professors]
    place_texts = [a['text'] for a in placements]

    prof_vecs = semantic_engine.encode(prof_texts) if prof_texts else []
    place_vecs = semantic_engine.encode(place_texts) if place_texts else []

    # Load penalty depends only on the professor, not the placement.
    load_penalty = {}
    for p in professors:
        cap = int(p['supervision_capacity']) or 1
        load_penalty[p['professor_id']] = int(p['current_load']) / cap

    cost = {}
    for ai, a in enumerate(placements):
        cost[a['allocation_id']] = {}
        for pi, p in enumerate(professors):
            # cosine sim -> both vectors are L2-normalised, so dot product.
            sim = float(place_vecs[ai].dot(prof_vecs[pi])) if len(place_texts) and len(prof_texts) else 0.0
            # Empty expertise or placement text -> neutral 0 similarity.
            domain_distance = 1.0 - max(-1.0, min(1.0, sim))
            cost[a['allocation_id']][p['professor_id']] = (
                W_DOMAIN * domain_distance
                + W_LOAD * load_penalty[p['professor_id']]
            )
    return cost


# ============================================================
# PHASE 1 - GREEDY CONSTRUCTION
# ============================================================

def greedy_assign(placements: List[dict],
                  professors: List[dict],
                  cost: Dict[int, Dict[int, float]]
                  ) -> Tuple[Dict[int, Optional[int]], Dict[int, int]]:
    """
    Walk placements in id order; give each to the feasible professor with the
    lowest cost. Decrement that professor's remaining capacity. If no professor
    has capacity left, the placement is left unassigned (None).

    Returns:
      assignment: allocation_id -> professor_id (or None if unassigned)
      remaining : professor_id  -> capacity left after greedy
    """
    remaining = {p['professor_id']: p['remaining_capacity'] for p in professors}
    assignment: Dict[int, Optional[int]] = {}

    for a in placements:
        aid = a['allocation_id']
        best_pid, best_cost = None, float('inf')
        for pid, c in cost[aid].items():
            if remaining[pid] > 0 and c < best_cost:
                best_pid, best_cost = pid, c
        assignment[aid] = best_pid
        if best_pid is not None:
            remaining[best_pid] -= 1

    return assignment, remaining


# ============================================================
# PHASE 2 - SWAP IMPROVEMENT (local search)
# ============================================================

def total_cost(assignment: Dict[int, Optional[int]],
               cost: Dict[int, Dict[int, float]]) -> float:
    return sum(cost[aid][pid] for aid, pid in assignment.items() if pid is not None)


def improve_by_swaps(assignment: Dict[int, Optional[int]],
                     cost: Dict[int, Dict[int, float]],
                     max_passes: int = 100) -> Tuple[Dict[int, Optional[int]], int]:
    """
    Repeatedly find the single best improving swap of two placements'
    supervisors and apply it. Swapping two ASSIGNED placements never changes
    anyone's load count (each professor still supervises the same number), so
    capacity stays valid automatically -- we only need to check that the swap
    lowers total cost.

    Stops at a local optimum (no swap improves) or after max_passes.
    Returns the improved assignment and the number of swaps applied.
    """
    swaps_applied = 0
    aids = [aid for aid, pid in assignment.items() if pid is not None]

    for _ in range(max_passes):
        best_delta = -1e-9   # require a real improvement (negative delta)
        best_pair = None

        for i in range(len(aids)):
            for j in range(i + 1, len(aids)):
                a1, a2 = aids[i], aids[j]
                p1, p2 = assignment[a1], assignment[a2]
                if p1 == p2:
                    continue
                # Both professors must be able to supervise the OTHER placement.
                if p2 not in cost[a1] or p1 not in cost[a2]:
                    continue
                current = cost[a1][p1] + cost[a2][p2]
                swapped = cost[a1][p2] + cost[a2][p1]
                delta = swapped - current      # negative => cheaper
                if delta < best_delta:
                    best_delta = delta
                    best_pair = (a1, a2)

        if best_pair is None:
            break   # local optimum reached

        a1, a2 = best_pair
        assignment[a1], assignment[a2] = assignment[a2], assignment[a1]
        swaps_applied += 1

    return assignment, swaps_applied


# ============================================================
# PERSISTENCE
# ============================================================

def persist(cur, assignment: Dict[int, Optional[int]],
            cost: Dict[int, Dict[int, float]]) -> int:
    """
    Write one supervisor_assignments row per assigned placement and bump each
    professor's current_load. allocation_id is UNIQUE, so we upsert defensively.
    """
    written = 0
    load_delta: Dict[int, int] = {}
    for aid, pid in assignment.items():
        if pid is None:
            continue
        c = round(cost[aid][pid], 4)
        cur.execute("""
            INSERT INTO supervisor_assignments (allocation_id, professor_id, cost_score)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE professor_id = VALUES(professor_id),
                                    cost_score   = VALUES(cost_score)
        """, (aid, pid, c))
        load_delta[pid] = load_delta.get(pid, 0) + 1
        written += 1

    for pid, delta in load_delta.items():
        cur.execute("""
            UPDATE professors SET current_load = current_load + %s WHERE id = %s
        """, (delta, pid))

    return written


# ============================================================
# MAIN
# ============================================================

def run(cycle_id: int, dry_run: bool = False) -> dict:
    started = time.time()
    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)

    cur.execute("SELECT id, name FROM academic_cycles WHERE id = %s", (cycle_id,))
    if not cur.fetchone():
        cur.close(); conn.close()
        return {'error': f'Cycle {cycle_id} not found'}

    placements = load_placements(cur, cycle_id)
    if not placements:
        cur.close(); conn.close()
        return {'error': 'No accepted placements awaiting a supervisor. '
                         'Students must accept their matches first.'}

    professors = load_professors(cur)
    if not professors:
        cur.close(); conn.close()
        return {'error': 'No available professors with spare supervision capacity.'}

    total_capacity = sum(p['remaining_capacity'] for p in professors)

    cost = build_cost_matrix(placements, professors)

    # Phase 1
    assignment, _remaining = greedy_assign(placements, professors, cost)
    greedy_cost = total_cost(assignment, cost)

    # Phase 2
    assignment, swaps = improve_by_swaps(assignment, cost)
    final_cost = total_cost(assignment, cost)

    unassigned = sum(1 for pid in assignment.values() if pid is None)

    written = 0
    if not dry_run:
        try:
            written = persist(cur, assignment, cost)
            conn.commit()
        except Exception as e:
            conn.rollback()
            cur.close(); conn.close()
            return {'error': f'Failed to persist assignments: {e}'}

    cur.close(); conn.close()

    return {
        'assignments_written': written,
        'placements': len(placements),
        'professors_available': len(professors),
        'total_capacity': total_capacity,
        'greedy_cost': round(greedy_cost, 4),
        'total_cost': round(final_cost, 4),
        'improvement': round(greedy_cost - final_cost, 4),
        'swaps_applied': swaps,
        'unassigned': unassigned,
        'dry_run': dry_run,
        'duration_ms': int((time.time() - started) * 1000),
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--cycle-id', type=int, required=True)
    # Accept --dry-run as a valued flag ("1"/"true"/"yes") so it works both from
    # the CLI (python supervisor_optimizer.py --cycle-id 1 --dry-run 1) and from
    # PHP's EngineRunner, which only emits "--key value" pairs.
    parser.add_argument('--dry-run', nargs='?', const='1', default='0',
                        help='Pass 1 to compute without writing to the database.')
    args = parser.parse_args()
    dry = str(args.dry_run).lower() in ('1', 'true', 'yes')
    print(json.dumps(run(args.cycle_id, dry)))
