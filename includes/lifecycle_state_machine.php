<?php
/**
 * ============================================================================
 * INTERNSHIP LIFECYCLE STATE MACHINE — DEFINITION (Layer 1)
 * ============================================================================
 * Pure data description of one accepted placement's lifecycle. This file does
 * NOT execute; it describes the workflow. LifecycleStateMachine reads it and
 * drives transitions. Read top-to-bottom, this IS the state diagram of
 * Figure 8.12 in the report.
 *
 * This is the architectural twin of cycle_state_machine.php: same declarative
 * shape, a different lifecycle. The cycle machine governs a whole semester and
 * runs once; this machine governs one placement and runs once per accepted
 * placement, so many instances progress concurrently.
 *
 * Two lifecycle-specific keys extend the cycle definition's vocabulary:
 *   'on_entry'      side effects fired when a state is ENTERED (generate a
 *                   document, dispatch a form + email). Per the report, states
 *                   act on entry.
 *   'awaits_form'   the Google form kind this state waits on (drives webhook
 *                   routing). 'final_docs' waits on a SET of three (the join).
 * ============================================================================
 */

return [

    'allocated' => [
        'label' => 'Allocated',
        'description' => 'The placement has been accepted. Documentation has not started yet.',
        'visible_to' => ['coordinator', 'student', 'professor'],
        'is_terminal' => false,
        'transitions' => [
            'begin_documentation' => [
                'target' => 'doc_pending',
                'label' => 'Begin documentation',
                'requires' => [],           // coordinator-driven, no guard
            ],
        ],
    ],

    'doc_pending' => [
        'label' => 'Agreement pending',
        'description' => 'The agreement PDF has been generated and sent to the student to sign through a form.',
        'visible_to' => ['coordinator', 'student'],
        'is_terminal' => false,
        'on_entry' => ['generate_agreement', 'email_student_agreement_form'],
        'awaits_form' => 'signed_agreement',
        'transitions' => [
            'submit_agreement' => [
                'target' => 'awaiting_prof_approval',
                'label' => 'Signed agreement received',
                'requires' => [],           // fired by the webhook on form arrival
                'trigger' => 'webhook',
            ],
        ],
    ],

    'awaiting_prof_approval' => [
        'label' => 'Awaiting professor approval',
        'description' => 'The supervising professor must approve the signed agreement.',
        'visible_to' => ['coordinator', 'professor', 'student'],
        'is_terminal' => false,
        'on_entry' => ['email_professor_approve'],
        'transitions' => [
            'prof_approve' => [
                'target' => 'awaiting_coord_approval',
                'label' => 'Approve (professor)',
                'requires' => [],
                'trigger' => 'professor',
            ],
            'prof_reject' => [
                'target' => 'revising',
                'label' => 'Request revision (professor)',
                'requires' => [],
                'trigger' => 'professor',
            ],
        ],
    ],

    'awaiting_coord_approval' => [
        'label' => 'Awaiting coordinator approval',
        'description' => 'The coordinator must give final approval before the internship becomes active.',
        'visible_to' => ['coordinator', 'student'],
        'is_terminal' => false,
        'on_entry' => ['email_coordinator_approve'],
        'transitions' => [
            'coord_approve' => [
                'target' => 'active',
                'label' => 'Approve (coordinator)',
                'requires' => [],
                'trigger' => 'coordinator',
            ],
            'coord_reject' => [
                'target' => 'revising',
                'label' => 'Request revision (coordinator)',
                'requires' => [],
                'trigger' => 'coordinator',
            ],
        ],
    ],

    'active' => [
        'label' => 'Active',
        'description' => 'The internship is running. It stays here until the coordinator begins closure.',
        'visible_to' => ['coordinator', 'student', 'professor'],
        'is_terminal' => false,
        'on_entry' => ['email_all_activated'],
        'transitions' => [
            'begin_closure' => [
                'target' => 'final_docs',
                'label' => 'Begin closure',
                'requires' => [],
                'trigger' => 'coordinator',
            ],
        ],
    ],

    'final_docs' => [
        'label' => 'Final documents',
        'description' => 'Waiting on three independent submissions: the student final report, the company evaluation, and the professor assessment. Only the submission that completes the set advances the placement.',
        'visible_to' => ['coordinator', 'student', 'professor'],
        'is_terminal' => false,
        'on_entry' => ['email_final_form_links'],
        'awaits_form' => ['final_report', 'company_evaluation', 'professor_assessment'], // JOIN
        'transitions' => [
            'submit_final' => [
                'target' => 'awaiting_final_approval',
                'label' => 'All final documents received',
                'requires' => [],           // fired only when the join is complete
                'trigger' => 'webhook',
            ],
        ],
    ],

    'awaiting_final_approval' => [
        'label' => 'Awaiting final approval',
        'description' => 'All final documents are in. The professor gives the final assessment approval.',
        'visible_to' => ['coordinator', 'professor', 'student'],
        'is_terminal' => false,
        'transitions' => [
            'final_approve' => [
                'target' => 'closed',
                'label' => 'Final approve',
                'requires' => [],
                'trigger' => 'professor',
            ],
            'final_reject' => [
                'target' => 'revising',
                'label' => 'Request revision (final)',
                'requires' => [],
                'trigger' => 'professor',
            ],
        ],
    ],

    'revising' => [
        'label' => 'Revising',
        'description' => 'A revision was requested. A revision notice PDF was generated and the student was emailed a resubmission form. On resubmission the placement returns to the approval chain.',
        'visible_to' => ['coordinator', 'student'],
        'is_terminal' => false,
        'on_entry' => ['generate_revision_notice', 'email_student_revision_form'],
        'awaits_form' => 'revision_resubmit',
        'transitions' => [
            'resubmit' => [
                'target' => 'awaiting_prof_approval',
                'label' => 'Revision resubmitted',
                'requires' => [],
                'trigger' => 'webhook',
            ],
            'abandon' => [
                'target' => 'rejected',
                'label' => 'Abandon placement',
                'requires' => [],
                'trigger' => 'coordinator',
            ],
        ],
    ],

    'rejected' => [
        'label' => 'Rejected',
        'description' => 'The placement was abandoned. A rejection notice PDF was generated and the parties notified. Terminal.',
        'visible_to' => ['coordinator', 'student', 'professor'],
        'is_terminal' => true,
        'on_entry' => ['generate_rejection_notice', 'email_rejection'],
        'transitions' => [],
    ],

    'closed' => [
        'label' => 'Closed',
        'description' => 'The internship is complete. A completion certificate PDF was generated and all parties notified. Terminal.',
        'visible_to' => ['coordinator', 'student', 'professor'],
        'is_terminal' => true,
        'on_entry' => ['generate_certificate', 'email_certificate'],
        'transitions' => [],
    ],

];
