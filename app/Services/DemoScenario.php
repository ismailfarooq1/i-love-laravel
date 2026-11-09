<?php

namespace App\Services;

class DemoScenario
{
    public const DOCUMENT = "# Support portal brief\n\nCustomers must be able to submit support requests and see their status.\nSupport staff must be able to reply and resolve requests.\nCustomers may attach files. File size and allowed formats are not yet decided.\nCustomers must only see their own requests.\nThe team needs a searchable queue with assignment and status filters.";

    /** @return array<string, mixed> */
    public function initial(): array
    {
        return [
            'name' => 'Customer support portal',
            'mode' => 'simulation',
            'documents' => [[
                'id' => 'brief-v1', 'name' => 'Support portal brief.md', 'version' => 1,
                'previous_id' => null, 'content' => self::DOCUMENT, 'created_at' => now()->toIso8601String(),
            ]],
            'nodes' => [], 'decisions' => [], 'history' => [], 'batches' => [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function templates(): array
    {
        return [
            'custom_root' => ['title' => 'New goal', 'description' => 'A human-defined objective.', 'children' => ['custom_scope', 'custom_delivery']],
            'custom_scope' => ['title' => 'Define scope and success criteria', 'description' => 'Agree on the intended outcomes and a small, verifiable first milestone.', 'children' => ['custom_requirements']],
            'custom_delivery' => ['title' => 'Deliver a first working increment', 'description' => 'Implement and validate a bounded increment against the approved scope.', 'children' => ['custom_implement', 'custom_verify']],
            'custom_requirements' => ['title' => 'Document the first milestone', 'description' => 'Record included behavior, exclusions, and observable success criteria.', 'criteria' => ['The milestone has explicit inclusions and exclusions.', 'A human reviewer approves its success criteria.']],
            'custom_implement' => ['title' => 'Implement the approved increment', 'description' => 'Refine this scripted placeholder into a concrete implementation task before approving.', 'criteria' => ['The approved milestone behavior is implemented.', 'Relevant automated checks pass.']],
            'custom_verify' => ['title' => 'Validate the increment', 'description' => 'Check the implemented behavior against the approved success criteria.', 'criteria' => ['Each success criterion has recorded verification evidence.', 'A human reviews the result.'], 'dependencies' => ['custom_implement']],
            'root' => ['title' => 'Launch a customer support portal', 'description' => 'Give customers a clear path to support and help staff resolve requests.', 'children' => ['requests', 'resolution'], 'line' => 1],
            'requests' => ['title' => 'Customers can submit requests', 'description' => 'Let customers describe a problem, attach context, and follow its status.', 'children' => ['identity', 'intake'], 'line' => 3],
            'resolution' => ['title' => 'Staff can resolve requests', 'description' => 'Give the support team a searchable queue and a conversation workflow.', 'children' => ['queue', 'reply'], 'line' => 4],
            'identity' => ['title' => 'Keep customer requests private', 'description' => 'Authenticate customers and scope every request to its owner.', 'children' => ['login', 'ownership'], 'line' => 6],
            'intake' => ['title' => 'Collect useful problem reports', 'description' => 'Collect the problem description and safely handle attachments.', 'children' => ['form', 'attachments'], 'line' => 5, 'question' => true],
            'login' => ['title' => 'Implement customer sign-in', 'description' => 'Implement a customer session and a sign-in screen.', 'criteria' => ['A registered customer can sign in and sign out.', 'Invalid credentials return an actionable error.'], 'line' => 6, 'assumption' => 'A password-based sign-in is proposed; the source does not specify an identity provider.'],
            'ownership' => ['title' => 'Enforce request ownership', 'description' => 'Scope request reads and writes to the authenticated customer.', 'criteria' => ['Customers cannot read or modify another customer’s request.', 'Ownership checks are covered by feature tests.'], 'line' => 6, 'dependencies' => ['login']],
            'form' => ['title' => 'Build the request submission form', 'description' => 'Collect a subject and description, then show the created request and status.', 'criteria' => ['A valid submission creates a request.', 'Missing required fields show validation errors.'], 'line' => 3],
            'attachments' => ['title' => 'Validate and store attachments', 'description' => 'Apply the human-approved attachment policy and store files privately.', 'criteria' => ['Files outside the approved policy are rejected.', 'Only the request owner and support staff can download attachments.'], 'line' => 5, 'dependencies' => ['form']],
            'queue' => ['title' => 'Build the staff request queue', 'description' => 'Provide text search, assignment filters, and status filters.', 'criteria' => ['Staff can filter requests by assignee and status.', 'Searching a request subject returns matching requests.'], 'line' => 7],
            'reply' => ['title' => 'Add staff replies and resolution', 'description' => 'Let staff reply to a request and mark it resolved.', 'criteria' => ['A reply appears in the customer conversation.', 'Resolved requests show their updated status.'], 'line' => 4, 'dependencies' => ['queue']],
        ];
    }
}
