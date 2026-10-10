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
];
