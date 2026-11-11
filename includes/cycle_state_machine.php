<?php
/**
 * ============================================================================
 * CYCLE STATE MACHINE — DEFINITION (Layer 1)
 * ============================================================================
 * Pure data description of the cycle lifecycle. This file does NOT execute;
 * it just describes the workflow. The engine reads it and drives transitions.
 * Read top-to-bottom, this IS the state diagram.
 * ============================================================================
 */

return [

    'DRAFT' => [
        'label' => 'Draft',
        'description' => 'Coordinator is setting up the cycle. Nothing is open to other roles yet.',
        'visible_to' => ['coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'open_applications' => [
                'target' => 'APPLICATIONS_OPEN',
                'label' => 'Open applications',
                'requires' => ['check_cycle_has_offers'],
                'on_transition' => 'action_notify_all_applications_open',
            ],
            'cancel' => [
                'target' => 'CANCELLED',
                'label' => 'Cancel cycle',
                'requires' => [],
                // Stands the cycle down as well as marking it cancelled: a
                // cancelled cycle is not the one the system should be running.
                'on_transition' => 'action_deactivate_cycle',
            ],
        ],
    ],

    'APPLICATIONS_OPEN' => [
        'label' => 'Applications open',
        'description' => 'Students may apply to offers and rank their preferences. Deadline is enforced by the coordinator, not automatically.',
        'visible_to' => ['student', 'company', 'professor', 'coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'close_applications' => [
                'target' => 'APPLICATIONS_INTEGRITY',
                'label' => 'Close applications',
                'requires' => [],
                'on_transition' => 'action_notify_coordinator_apps_closed',
            ],
            'cancel' => [
                'target' => 'CANCELLED',
                'label' => 'Cancel cycle',
                'requires' => [],
                // Stands the cycle down as well as marking it cancelled: a
                // cancelled cycle is not the one the system should be running.
                'on_transition' => 'action_deactivate_cycle',
            ],
        ],
    ],

    'APPLICATIONS_INTEGRITY' => [
        'label' => 'Reviewing applications',
        'description' => 'Coordinator verifies applications and preferences are clean and complete before opening ranking.',
        'visible_to' => ['coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'open_ranking' => [
                'target' => 'RANKING_OPEN',
                'label' => 'Approve and open ranking',
                'requires' => [
                    'check_applications_present',
                    'check_all_applications_valid',
                    'check_all_students_have_preferences',
                    'check_no_orphaned_applications',
                ],
                'on_transition' => 'action_notify_posters_to_rank',
            ],
            'reopen_applications' => [
                'target' => 'APPLICATIONS_OPEN',
                'label' => 'Reopen applications (fix issues)',
                'requires' => [],
            ],
        ],
    ],

    'RANKING_OPEN' => [
        'label' => 'Ranking open',
        'description' => 'Professors and companies rank the students who applied to their offers. Students can still see status but cannot change applications.',
        'visible_to' => ['company', 'professor', 'coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'close_ranking' => [
                'target' => 'RANKING_INTEGRITY',
                'label' => 'Close ranking',
                'requires' => [],
                'on_transition' => 'action_notify_coordinator_ranking_closed',
            ],
        ],
    ],

    'RANKING_INTEGRITY' => [
        'label' => 'Reviewing rankings',
        'description' => 'Coordinator verifies every offer with applicants has a complete ranking before running the matching engine.',
        'visible_to' => ['coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'run_matching' => [
                'target' => 'MATCHING_RUNNING',
                'label' => 'Approve and run matching engine',
                'requires' => [
                    'check_all_offers_ranked',
                    'check_rankings_reference_applicants_only',
                ],
                'on_transition' => 'action_run_matching_engine',
            ],
            'reopen_ranking' => [
                'target' => 'RANKING_OPEN',
                'label' => 'Reopen ranking (fix issues)',
                'requires' => [],
            ],
        ],
    ],

    'MATCHING_RUNNING' => [
        'label' => 'Matching in progress',
        'description' => 'The Gale-Shapley engine and stability verifier are executing. Transient state; coordinator advances after review.',
        'visible_to' => ['coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'matching_complete' => [
                'target' => 'MATCHING_INTEGRITY',
                'label' => 'Matching complete — review results',
                'requires' => ['check_matching_round_exists', 'check_verifier_confirms_stability'],
            ],
        ],
    ],

    'MATCHING_INTEGRITY' => [
        'label' => 'Reviewing matching results',
        'description' => 'Coordinator reviews the tentative allocations and the stability verifier report before publishing to students.',
        'visible_to' => ['coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'publish_to_students' => [
                'target' => 'RESULTS_PUBLISHED',
                'label' => 'Publish results to students',
                'requires' => ['check_matching_stable'],
                'on_transition' => 'action_publish_results_to_students',
            ],
            'discard_and_rerun' => [
                'target' => 'RANKING_INTEGRITY',
                'label' => 'Discard results and return to ranking review',
                'requires' => [],
                'on_transition' => 'action_discard_current_round',
            ],
        ],
    ],

    'RESULTS_PUBLISHED' => [
        'label' => 'Results published — awaiting student decisions',
        'description' => 'Matched students can accept or reject their offer. No response by the deadline is treated as automatic rejection. Professors and companies still do NOT see the results at this stage.',
        'visible_to' => ['student', 'coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'close_decisions' => [
                'target' => 'POST_DECISION_INTEGRITY',
                'label' => 'Close decision window',
                'requires' => [],
                'on_transition' => 'action_timeout_pending_decisions',
            ],
        ],
    ],

    'POST_DECISION_INTEGRITY' => [
        'label' => 'Reviewing decisions',
        'description' => 'Coordinator reviews who accepted, who rejected, and who timed out. Decides whether to run another matching round for unmatched students, or to finalise the cycle.',
        'visible_to' => ['coordinator'],
        'is_terminal' => false,
        'transitions' => [
            'run_next_round' => [
                'target' => 'MATCHING_RUNNING',
                'label' => 'Run next matching round for remaining students',
                'requires' => [
                    'check_unmatched_pool_nonempty',
                    'check_unclaimed_offers_exist',
                ],
                'on_transition' => 'action_run_matching_engine',
                'increments_round' => true,
            ],
            'finalise' => [
                'target' => 'FINAL_PUBLICATION',
                'label' => 'Finalise cycle and publish to companies/professors',
                'requires' => [],
                'on_transition' => 'action_publish_to_posters',
            ],
        ],
    ],

    'FINAL_PUBLICATION' => [
        'label' => 'Cycle closed — final results shared',
        'description' => 'Terminal state. All accepted matches are now visible to professors and companies. No further transitions.',
        'visible_to' => ['student', 'company', 'professor', 'coordinator'],
        'is_terminal' => true,
        'transitions' => [],
    ],

    'CANCELLED' => [
        'label' => 'Cycle cancelled',
        'description' => 'Terminal state. The coordinator killed this cycle. No further activity.',
        'visible_to' => ['coordinator'],
        'is_terminal' => true,
        'transitions' => [],
    ],

];