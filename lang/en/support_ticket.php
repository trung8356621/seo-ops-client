<?php

declare(strict_types=1);

return [
    'trigger' => 'Ticket',
    'trigger_aria' => 'Open Support Ticket form',
    'dialog_aria' => 'Submit Support Ticket',
    'title' => 'Title',
    'title_placeholder' => 'Short summary',
    'content' => 'Content',
    'content_placeholder' => 'Describe the issue… paste screenshots with Ctrl+V',
    'attach' => 'Attach',
    'submit' => 'Send',
    'submitting' => 'Sending…',
    'remove_attachment' => 'Remove',
    'messages' => [
        'submitted' => 'Ticket submitted successfully.',
        'file_too_large' => 'File exceeds size limit.',
        'submit_failed' => 'Could not submit — please try again.',
    ],
    'errors' => [
        'unauthenticated' => 'Unauthenticated.',
        'invalid' => 'Invalid ticket payload.',
        'owner_missing' => 'Unable to resolve workspace owner.',
        'store_failed' => 'Could not store attachment.',
        'file_empty' => 'Empty or invalid file.',
        'file_too_large' => 'File exceeds the :mb MB limit.',
        'file_type' => 'File type not allowed. Allowed: :types.',
    ],
    'admin' => [
        'nav' => 'Support Tickets',
        'model' => 'Support Ticket',
        'plural' => 'Support Tickets',
        'columns' => [
            'id' => 'ID',
            'created_at' => 'Submitted',
            'user' => 'User',
            'title' => 'Title',
            'status' => 'Status',
            'service' => 'Service',
            'attachments' => 'Attachments',
            'page_url' => 'Page URL',
            'connection_hash' => 'Connection',
            'body' => 'Content',
        ],
        'view' => 'View',
        'empty_attachments' => 'No attachments',
    ],
];
