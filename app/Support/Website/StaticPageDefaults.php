<?php

namespace App\Support\Website;

use App\Enums\FeatureFlag;
use App\Services\FeatureFlags\FeatureFlags;

/**
 * Built-in text for the pages an admin has not written in the Pages screen yet: For contributors and For clients.
 * It restates the spec (sections 2, 6, 11 and 12) and makes no claims about results, customers or numbers. A Page
 * with the same address, once published in Admin, replaces it.
 */
class StaticPageDefaults
{
    /** @return array{title: string, description: string, intro: string, blocks: list<array{heading: string, items: list<string>}>, cta: array{label: string, route: string}} */
    public static function forContributors(): array
    {
        return [
            'title' => 'For contributors',
            'description' => 'Join Groibe as a skilled professional, work on real client projects and build proof of your work.',
            'intro' => 'Groibe is for skilled professionals who want to do real client work and have a record of it. Clients never hire you directly. Groibe is accountable to the client and manages the work with you.',
            'blocks' => [
                ['heading' => 'How you join', 'items' => [
                    'Create an account and complete your profile with your skills and availability.',
                    'Accept the Member Agreement.',
                    'Groibe reviews your profile before you become an active member.',
                ]],
                ['heading' => 'What you do', 'items' => [
                    'Apply to open roles, or be invited onto a project.',
                    'Work on tasks and submit your work. Groibe checks it before the client sees it.',
                    'Review the client once the project is closed.',
                ]],
                ['heading' => 'What you keep', 'items' => [
                    'Proof of work that you write up, shown publicly only when the client agrees.',
                    'Reputation facts from your projects and the reviews written about you: counts and averages, never a single score.',
                ]],
            ],
            'cta' => ['label' => 'Join Groibe', 'route' => 'register.contributor'],
        ];
    }

    /** @return array{title: string, description: string, intro: string, blocks: list<array{heading: string, items: list<string>}>, cta: array{label: string, route: string}} */
    public static function forClients(): array
    {
        return [
            'title' => 'For clients',
            'description' => 'Submit a brief and get outcomes delivered by a team Groibe assembles and manages.',
            'intro' => 'You buy outcomes from Groibe. Groibe is accountable to you, assembles the team, manages the work and checks it before you see it.',
            'blocks' => [
                ['heading' => 'How it starts', 'items' => [
                    'Register your organization and submit a brief describing the outcome you need.',
                    'Groibe scopes the work and sends a statement of work for you to read and accept.',
                ]],
                ['heading' => 'How the work runs', 'items' => [
                    'Groibe staffs the project and manages the team. You do not hire contributors directly.',
                    'Work is checked by Groibe first. You review each deliverable and accept it or ask for changes.',
                    'You see progress, and anything waiting for your action, in your client area.',
                    // The promise is only made while project threads exist (spec 14, `messaging` flag).
                    ...(app(FeatureFlags::class)->enabled(FeatureFlag::Messaging)
                        ? ['You can message the team in the project thread. Groibe staff keep the conversation in one place.']
                        : []),
                ]],
                ['heading' => 'When it is done', 'items' => [
                    'You can review the contributors who worked on your project.',
                    'You choose whether the work may be shown publicly, and whether your organization is named.',
                ]],
            ],
            'cta' => ['label' => 'Submit a brief', 'route' => 'register.client'],
        ];
    }
}
