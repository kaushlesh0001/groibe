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

    // Spec 17.2: security headers. HSTS is sent only on HTTPS; set the max age to 0 to switch it off while testing a new host.
    // Report-only mode shows what the Content-Security-Policy would block, in the browser console, without blocking it.
    'security' => [
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'csp_report_only' => (bool) env('CSP_REPORT_ONLY', false),
    ],

    // Spec 15.1 and SCR-025: CSV only, run in chunks so a shared host's time limit is never reached.
    'imports' => [
        'max_file_kb' => 5120,
        // The dry run reads every row in the admin's web request; above this the file is split (a shared host stops a request after 30 to 60 seconds).
        'max_rows' => 5000,
        'chunk_size' => 200,
        'preview_rows' => 20,
    ],

    // Spec 17.4 and SCR-032, SCR-034: logs, exception emails, backups and the daily housekeeping jobs.
    'ops' => [
        // Who gets the exception email. Empty sends none. No address is hard-coded: set it in `.env` on the host.
        'exception_email' => env('GROIBE_EXCEPTION_EMAIL'),
        // The same exception (class, file and line) is mailed once in this many minutes, and no more than the cap an hour in all.
        'exception_throttle_minutes' => 60,
        'exception_max_per_hour' => 10,

        // The host plan's database limit in MB; super admins are warned when the database passes `db_size_warn_percent` of it.
        'db_size_limit_mb' => (int) env('DB_SIZE_LIMIT_MB', 1024),
        'db_size_warn_percent' => 80,

        // Invited but not activated for this many days: reported to delivery managers, never deleted.
        'stale_invite_days' => 14,

        // An import's stored CSV and report are removed this many days after upload (the row and its counts stay).
        'import_file_days' => 30,

        // Email log retention by `created_at` (SCR-032).
        'email_log_sent_days' => 90,
        'email_log_failed_days' => 180,

        // SCR-039: days kept, used when the `settings` row of the same key (retention.<name>) is missing or not a whole number of 1 or more.
        'retention' => [
            'failed_jobs_days' => 30,
            'job_batches_days' => 30,
            'notifications_read_days' => 180,
            'activity_log_days' => 365,
        ],

        // SCR-039: super admins are reminded once a day while a data request that is still waiting is older than this.
        'data_request_reminder_days' => 7,

        // The weekly database dump. Written outside the web root: the command refuses a folder under `public`.
        'backup' => [
            'path' => env('GROIBE_BACKUP_PATH'),
            'keep' => (int) env('GROIBE_BACKUP_KEEP', 4),
        ],
    ],

    // Spec 12: organization details collected when a client registers. `organizations.size_band` is free text (spec 7.1); these are the choices offered.
    'organizations' => [
        'size_bands' => ['1-10', '11-50', '51-200', '201-1000', '1000+'],
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
        // Decoding needs about 4 bytes a pixel, so 25 million pixels (about 100 MB) keeps a request inside the 256 MB budget (spec 17.1).
        'photo_max_pixels' => 25_000_000,
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
