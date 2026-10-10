<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Groibe Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'contact_email' => env('GROIBE_CONTACT_EMAIL'),

    'invites' => [
        'expire_days' => 7,
    ],

    'dashboards' => [
        'contributor' => '/app/contributor',
        'client' => '/app/client',
        'admin' => '/admin',
    ],

    // Stored as string keys on briefs (spec 7.3). Labels are display only.
    'briefs' => [
        'budget_bands' => [
            'under_1l' => 'Under INR 1 lakh',
            '1l_3l' => 'INR 1 to 3 lakh',
            '3l_10l' => 'INR 3 to 10 lakh',
            '10l_25l' => 'INR 10 to 25 lakh',
            '25l_plus' => 'Above INR 25 lakh',
            'not_sure' => 'Not sure yet',
        ],
        'timeline_bands' => [
            'under_2w' => 'Under 2 weeks',
            '2_4w' => '2 to 4 weeks',
            '1_3m' => '1 to 3 months',
            '3m_plus' => 'More than 3 months',
            'flexible' => 'Flexible',
        ],
    ],

    // Spec 17.2: allowlisted extensions, 20 MB for documents.
    'uploads' => [
        'document_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'png', 'jpg', 'jpeg'],
        'document_max_kb' => 20 * 1024,
        'signed_url_minutes' => 10,
        'photo_max_kb' => 2 * 1024,
        'photo_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'photo_max_px' => 800,
    ],

    'directory' => [
        'per_page' => 12,
    ],

    // Per-category preferences are a placeholder until Pass 7. Locked categories can never be disabled.
    'notifications' => [
        'categories' => ['brief', 'sow', 'project', 'assignment', 'task', 'onboarding', 'security'],
        'locked_categories' => ['security'],
    ],
];
