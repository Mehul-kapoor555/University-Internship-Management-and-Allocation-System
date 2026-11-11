<?php
/**
 * Matching is no longer run from its own page.
 *
 * This page used to run the matcher, publish the round and notify students —
 * all of which the cycle state machine already does through
 * action_run_matching_engine and action_publish_results_to_students. Two routes
 * doing the same work is what made the pieces feel disconnected: publishing
 * from here and publishing through the FSM behaved differently and could notify
 * students twice.
 *
 * Running and publishing are now steps of the chain in Cycle control, so there
 * is a single path through the cycle. This file stays as a redirect so old
 * links and bookmarks still land somewhere useful.
 *
 * The round visualiser is unaffected and is linked from the matching step.
 */
require '../includes/db.php';
require '../includes/auth.php';
requireRole(['coordinator']);

header('Location: ' . BASE_URL . '/admin/cycle-control.php');
exit;