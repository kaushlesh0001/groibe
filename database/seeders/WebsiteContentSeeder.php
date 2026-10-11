<?php

namespace Database\Seeders;

use App\Enums\FaqAudience;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\Page;
use App\Support\Website\HomepageSections;
use Illuminate\Database\Seeder;

/**
 * Website content for a fresh install (spec 19). Safe in production and safe to run twice: every row is matched on its
 * key, question or address and is never overwritten, so an admin's edits and approvals always survive.
 *
 * What goes live, and what does not:
 * - The eleven homepage sections are seeded switched on with empty content, so they show the built-in wording. That
 *   wording only restates the spec (see HomepageDefaults). Sections that list live records hide themselves until
 *   there is something to list.
 * - Every FAQ and the About page are seeded as drafts: inactive and marked "needs review". None of this wording has been
 *   approved yet, so none of it can reach a public page until an admin reads it and approves it in Admin.
 * - No testimonials are seeded. A testimonial must be a real quote from a real person.
 */
class WebsiteContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (array_keys(HomepageSections::TITLES) as $position => $key) {
            CmsSection::query()->firstOrCreate(
                ['key' => $key],
                ['title' => HomepageSections::title($key), 'content' => [], 'sort' => ($position + 1) * 10, 'is_active' => true, 'needs_review' => false],
            );
        }

        foreach ($this->faqs() as $position => [$audience, $question, $answer]) {
            Faq::query()->firstOrCreate(
                ['audience' => $audience, 'question' => $question],
                ['answer' => $answer, 'sort' => ($position + 1) * 10, 'is_active' => false, 'needs_review' => true],
            );
        }

        Page::query()->firstOrCreate(['slug' => 'about'], [
            'title' => 'About Groibe',
            'body_html' => $this->aboutBody(),
            'meta_title' => null,
            'meta_description' => 'Groibe began with a simple belief: the right people change everything.',
            'is_active' => false,
            'needs_review' => true,
        ]);
    }

    /**
     * Ten general, six contributor and six client questions (spec 19). The first four general ones are the old site's
     * wording as written, and the rest restate the spec. All are drafts.
     *
     * @return list<array{0: FaqAudience, 1: string, 2: string}>
     */
    private function faqs(): array
    {
        $general = FaqAudience::General;
        $contributor = FaqAudience::Contributor;
        $client = FaqAudience::Client;

        return [
            [$general, 'Is Groibe a job portal?', 'No. Groibe is a community-powered trust network where reputation grows through learning, contribution, collaboration, and delivery.'],
            [$general, 'Is Groibe a freelancing marketplace?', 'No. Businesses come to Groibe for outcomes delivered by trusted people, not random hourly profiles.'],
            [$general, 'Who can join?', 'Curious people, builders, operators, founders, students, and professionals who want to grow by contributing.'],
            [$general, 'How do businesses work with Groibe?', 'Businesses share a problem, Groibe shapes the outcome, and trusted contributors help deliver meaningful work.'],
            [$general, 'What is Groibe?', 'Groibe is a community-powered professional ecosystem and project delivery platform. Clients buy outcomes from Groibe, and Groibe assembles and manages a team of skilled professionals to deliver them.'],
            [$general, 'Who is accountable for the work?', 'Groibe is accountable to the client. Contributors are accountable to Groibe.'],
            [$general, 'Is the work checked before the client sees it?', 'Yes. Groibe checks submitted work internally first, and only approved work is shared with the client.'],
            [$general, 'Does Groibe take payments through the website?', 'No. There is no payment gateway or escrow on the website. Invoicing happens outside the platform, and the platform keeps references and statuses only.'],
            [$general, 'Is my profile public?', 'Only if you choose. A contributor profile is public only when the contributor turns it on and Groibe has approved the account. Proof of work is shown publicly only with the client\'s consent.'],
            [$general, 'How do I contact Groibe?', 'Use the contact page. Your message goes to the Groibe team, who reply by email.'],

            [$contributor, 'How do I become a contributor?', 'Create an account, complete your profile and skills, accept the Member Agreement and submit it for review. A Groibe admin reviews every application before you become an active member.'],
            [$contributor, 'Can I apply to any open role?', 'Only active contributors can apply. A role can be public, for members only or by invitation, and the Groibe admin decides who is shortlisted and selected.'],
            [$contributor, 'Do clients hire me directly?', 'No. Clients never hire contributors directly. Groibe manages the work and the relationship with the client.'],
            [$contributor, 'What is proof of work?', 'A record of a project you worked on. You write the details after the project closes, a Groibe admin approves it, and it is shown publicly only if the client agrees.'],
            [$contributor, 'How is my reputation shown?', 'As evidence: projects completed, how often your work was approved first time, how often it was on time, and the number and average of your reviews. There is no single score.'],
            [$contributor, 'Can I review the client?', 'Yes, once the project is closed. Reviews of clients are seen by the Groibe team only.'],

            [$client, 'How do I start?', 'Register your organization. Once it is verified, you can submit a brief that describes what you need.'],
            [$client, 'What happens after I submit a brief?', 'Groibe reviews it and may ask you questions. Then Groibe scopes the work and sends a statement of work for you to read and accept.'],
            [$client, 'Can I choose the contributors?', 'Groibe builds the team, and you do not hire contributors directly. If Groibe turns it on for your project, you may be shown a shortlist.'],
            [$client, 'How do I know the work is good?', 'Groibe checks the work internally first. You then review each deliverable and either accept it or ask for changes.'],
            [$client, 'Who in my organization can accept deliverables?', 'Owners and managers. Viewers can read everything shared with them but cannot accept or ask for changes.'],
            [$client, 'Will my project be shown publicly?', 'Only if you agree. After the project is accepted you choose to keep it private, to show it without naming your organization, or to show it with your name.'],
        ];
    }

    /** The old site's About text that was not flagged. The founder block is left out on purpose: it needs the founder's own confirmation. */
    private function aboutBody(): string
    {
        return '<p>Groibe began with a simple belief: the right people change everything.</p>'
            .'<p>Professionals struggle to prove themselves beyond a resume. Businesses struggle to find people they can trust. Groibe exists to make trust visible through learning, contribution, and delivery.</p>'
            .'<h2>Mission</h2>'
            .'<p>Build a trusted community where curious people grow together and businesses build with confidence.</p>'
            .'<p>Groibe sits between community, consulting, technology, and managed delivery.</p>'
            .'<p>The goal is not to copy existing platforms. The goal is to create a better trust layer for people and businesses.</p>';
    }
}
