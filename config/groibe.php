<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Groibe Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'contact_email' => env('GROIBE_CONTACT_EMAIL'),

    // Proxies whose X-Forwarded-For header is trusted: '*' behind Cloudflare, or a comma-separated list. Empty trusts none, so a
    // direct request cannot fake its address and slip past the rate limits (spec 17.2).
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'invites' => [
        'expire_days' => 7,
        // Bulk invites (spec 15.1) are queued and spread out: this many emails a minute at most.
        'per_minute' => 30,
    ],

    // Spec 15.1 and SCR-025: CSV only, run in chunks so a shared host's time limit is never reached.
    'imports' => [
        'max_file_kb' => 5120,
        'chunk_size' => 200,
        'preview_rows' => 20,
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
        'submission_max_kb' => 25 * 1024,
        'submission_max_files' => 10,
        'signed_url_minutes' => 10,
        'photo_max_kb' => 2 * 1024,
        'photo_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'photo_max_px' => 800,
    ],

    // Spec 17.3: public lists, stats and the homepage are cached for 5 to 15 minutes and cleared when their data changes.
    'cache' => [
        'public_minutes' => 10,
    ],

    // Spec 10.2: slugs a CMS page may not take, because a route or app area already owns them (SCR-021).
    'cms' => [
        'reserved_slugs' => [
            'admin', 'app', 'api', 'login', 'logout', 'register', 'forgot-password', 'reset-password', 'verify-email',
            'invite', 'up', 'storage', 'build', 'brand', 'livewire', 'sitemap.xml', 'robots.txt', 'contact', 'faq',
            'terms', 'privacy', 'opportunities', 'contributors', 'organizations', 'work', 'skills',
        ],
    ],

    'directory' => [
        'per_page' => 12,
    ],

    'opportunities' => [
        'per_page' => 12,
    ],

    // Spec 14: project and support threads.
    'messages' => [
        'max_chars' => 5000,
        'max_files' => 5,
        'poll_seconds' => 20,
        'page_size' => 50,
        'per_minute' => 20,
    ],

    // Per-category preferences are a placeholder until Pass 7. Locked categories can never be disabled.
    'notifications' => [
        'categories' => ['brief', 'sow', 'project', 'assignment', 'application', 'task', 'submission', 'deliverable', 'message', 'payout', 'review', 'portfolio', 'onboarding', 'security'],
        'locked_categories' => ['security'],
        // Shown on the preferences page (spec 14).
        'labels' => [
            'brief' => ['name' => 'Briefs', 'hint' => 'Status changes and questions about a brief'],
            'sow' => ['name' => 'Statements of Work', 'hint' => 'A statement of work sent or accepted'],
            'project' => ['name' => 'Projects', 'hint' => 'Project progress and delivery'],
            'assignment' => ['name' => 'Assignments', 'hint' => 'Invitations to a project team and changes to your seat'],
            'application' => ['name' => 'Applications', 'hint' => 'Updates on open role applications'],
            'task' => ['name' => 'Tasks', 'hint' => 'Tasks assigned to you or changed'],
            'submission' => ['name' => 'Submissions', 'hint' => 'Work received and review results'],
            'deliverable' => ['name' => 'Deliverables', 'hint' => 'Deliverables shared, accepted or sent back for changes'],
            'message' => ['name' => 'Messages', 'hint' => 'New messages in your conversations'],
            'payout' => ['name' => 'Payouts and invoices', 'hint' => 'A payout approved, paid or cancelled, or an invoice gone overdue'],
            'review' => ['name' => 'Reviews', 'hint' => 'Reviews requested or received'],
            'portfolio' => ['name' => 'Portfolio', 'hint' => 'Proof of work drafts and publication'],
            'onboarding' => ['name' => 'Onboarding', 'hint' => 'Changes to your account review status'],
            'security' => ['name' => 'Account security', 'hint' => 'Sign-in, password and account safety emails'],
        ],
    ],
];
