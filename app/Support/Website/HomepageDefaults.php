<?php

namespace App\Support\Website;

/**
 * Built-in wording for the homepage sections, used for any field Admin leaves empty. It only restates the spec:
 * the section names and taglines of 10.1 and the delivery loop of 2.1. It contains no claims, customers or numbers.
 * Longer marketing copy belongs in the admin screens, where it is reviewed before it goes live.
 */
class HomepageDefaults
{
    /** @return array<string, mixed> */
    public static function for(string $key): array
    {
        return match ($key) {
            'hero' => [
                'heading' => 'Where Ideas Find',
                'highlight' => 'People',
                'subheading' => 'Groibe assembles and manages teams of skilled professionals to deliver outcomes for growing businesses.',
                'primary_label' => 'Explore Opportunities',
                'secondary_label' => 'Join Groibe',
                'client_line' => 'Need work delivered?',
                'client_link_label' => 'Submit a brief',
            ],
            'ecosystem' => [
                'heading' => 'One platform. Two sides. One delivery loop.',
                'subheading' => 'Clients describe the outcome they need. Groibe scopes it, builds the team, checks the work and delivers it.',
                'contributors_title' => 'Contributors',
                'contributors_text' => 'Apply to open roles, work on real client projects and build proof of your work and a record of your results.',
                'clients_title' => 'Clients',
                'clients_text' => 'Submit a brief, accept a scoped plan and receive checked work from a team Groibe manages for you.',
            ],
            'how_it_works' => [
                'heading' => 'How it works',
                'subheading' => 'Five steps, from the first brief to proof of the work.',
                'contributor_steps' => [
                    ['title' => 'Brief', 'text' => 'A client writes down the outcome they need.'],
                    ['title' => 'Scope', 'text' => 'Groibe breaks the work into milestones and tasks.'],
                    ['title' => 'Staff', 'text' => 'You are assigned to the work, or you apply to an open role.'],
                    ['title' => 'Deliver', 'text' => 'You submit your work. Groibe checks it before the client sees it.'],
                    ['title' => 'Prove', 'text' => 'Approved work becomes proof of work on your profile, with the client\'s consent.'],
                ],
                'client_steps' => [
                    ['title' => 'Brief', 'text' => 'Tell Groibe what you need and the outcome you want.'],
                    ['title' => 'Scope', 'text' => 'Groibe scopes and prices the work and sends a statement of work for you to accept.'],
                    ['title' => 'Staff', 'text' => 'Groibe builds and manages the team.'],
                    ['title' => 'Deliver', 'text' => 'Work is checked first. You review each deliverable and accept it or ask for changes.'],
                    ['title' => 'Prove', 'text' => 'You review the contributors and choose whether the work may be shown.'],
                ],
            ],
            'open_roles' => ['heading' => 'Open roles', 'intro' => '', 'limit' => 6, 'cta_label' => 'See all opportunities'],
            'proof_of_work' => ['heading' => 'Proof of work', 'intro' => '', 'limit' => 6, 'cta_label' => 'See more work'],
            'community' => ['heading' => 'Community', 'intro' => '', 'limit' => 6, 'cta_label' => 'See all contributors'],
            'organizations' => ['heading' => 'Organizations', 'intro' => '', 'limit' => 12, 'cta_label' => 'See all organizations'],
            'stats' => ['heading' => 'Groibe in numbers'],
            'testimonials' => ['heading' => 'What people say'],
            'faq' => ['heading' => 'Questions', 'intro' => ''],
            'final_cta' => [
                'heading' => 'Ready to start?',
                'text' => 'Join as a contributor, or tell us what you need delivered.',
                'button_label' => 'Join Groibe',
                'secondary_label' => 'Submit a brief',
            ],
            default => [],
        };
    }

    /** Admin content over the defaults. An empty field falls back to its default. @param array<string, mixed>|null $content @return array<string, mixed> */
    public static function merged(string $key, ?array $content): array
    {
        $merged = self::for($key);

        foreach ($content ?? [] as $field => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }
}
