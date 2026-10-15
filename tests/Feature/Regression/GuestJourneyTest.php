<?php

use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleVisibility;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\ProofOfWork;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

/*
 * Pass 9 item 7, persona 1 of 4: the guest. Spec 20: the public site shows what is public and nothing else, a visitor can reach
 * Groibe through the contact form and can start either registration, and every signed-in area asks for a sign-in.
 */

it('walks a visitor through the public site, then to the contact form and the sign-up pages', function () {
    config(['groibe.contact_email' => 'contact@groibe.test']);
    Mail::fake();

    // What exists: one of every public thing, and one of every private look-alike.
    OpenRole::factory()->published()->public()->create(['title' => 'Retail survey analyst']);
    OpenRole::factory()->create(['title' => 'Unpublished draft role']);
    OpenRole::factory()->published()->visibility(OpenRoleVisibility::MembersOnly)->create(['title' => 'Members only role']);
    OpenRole::factory()->published()->inviteOnly()->create(['title' => 'Invite only role']);

    $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous, 'title' => 'Confidential rollout']);
    $item = ProofOfWork::factory()->published()->create([
        'user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'title' => 'Regional market sizing', 'summary' => 'Sized a market.',
    ]);
    $hidden = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::None]);
    ProofOfWork::factory()->published()->create(['user_id' => $hidden['contributor']->id, 'project_id' => $hidden['project']->id, 'title' => 'Work the client did not agree to show']);

    $privatePerson = contributorIn(OnboardingState::Active, ['is_public' => false, 'slug' => 'private-person-abc123'], ['name' => 'Private Person']);
    $openOrg = Organization::factory()->verified()->create(['name' => 'Open Org Ltd', 'slug' => 'open-org', 'is_public' => true]);
    $closedOrg = Organization::factory()->verified()->create(['name' => 'Closed Org Ltd', 'slug' => 'closed-org', 'is_public' => false]);

    // 1. The home page and the pages that explain the offer.
    $this->get('/')->assertOk()->assertSee('Where Ideas Find')->assertHeader('X-Frame-Options', 'DENY');

    foreach (['/how-it-works', '/for-contributors', '/for-clients', '/faq', '/contact'] as $page) {
        $this->get($page)->assertOk();
    }

    // 2. Opportunities: the public one is listed and opens; the draft, members-only and invite-only ones do not exist for a visitor.
    $this->get('/opportunities')->assertOk()->assertSee('Retail survey analyst')
        ->assertDontSee('Unpublished draft role')->assertDontSee('Members only role')->assertDontSee('Invite only role');
    $slug = OpenRole::query()->where('title', 'Retail survey analyst')->value('slug');
    $this->get("/opportunities/{$slug}")->assertOk()->assertSee('Retail survey analyst');

    foreach (['Unpublished draft role', 'Members only role', 'Invite only role'] as $title) {
        $this->get('/opportunities/'.OpenRole::query()->where('title', $title)->value('slug'))->assertNotFound();
    }

    // 3. People and organizations: public ones only.
    $this->get('/contributors')->assertOk()->assertSee($seat['contributor']->name)->assertDontSee('Private Person');
    $this->get("/contributors/{$seat['contributor']->profile->slug}")->assertOk();
    $this->get("/contributors/{$privatePerson->profile->slug}")->assertNotFound();
    $this->get('/organizations')->assertOk()->assertSee('Open Org Ltd')->assertDontSee('Closed Org Ltd');
    $this->get('/organizations/open-org')->assertOk();
    $this->get('/organizations/closed-org')->assertNotFound();

    // 4. Proof of work: consented items only, with the client anonymous.
    $this->get('/work')->assertOk()->assertSee('Regional market sizing')->assertDontSee('Work the client did not agree to show');
    $this->get("/work/{$item->slug}")->assertOk()->assertSee('Sized a market.');

    // 5. The sitemap and robots file agree with the pages.
    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->toContain("/opportunities/{$slug}")->toContain('/organizations/open-org')->toContain("/work/{$item->slug}")
        ->not->toContain('closed-org')->not->toContain($privatePerson->profile->slug)->not->toContain('Unpublished');
    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap');

    // 6. The contact form stores the message and emails the team; a bot that fills the hidden field is told thank you and stored nowhere.
    $this->post('/contact', ['name' => 'Asha Rao', 'email' => 'asha@example.test', 'subject' => 'A question', 'message' => 'I would like to know more about Groibe.'])
        ->assertRedirect()->assertSessionHas('status');
    expect(ContactMessage::query()->count())->toBe(1);
    Mail::assertSent(ContactMessageReceived::class, fn ($mail) => $mail->hasTo('contact@groibe.test'));

    $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'Buy things now, please and thank you.', 'company_website' => 'https://spam.test'])
        ->assertRedirect();
    expect(ContactMessage::query()->count())->toBe(1);

    // 7. A visitor can start either registration, and sign in.
    $this->get('/register/contributor')->assertOk()->assertSee('Create');
    $this->get('/register/client')->assertOk();
    $this->get('/login')->assertOk();
    $this->get('/forgot-password')->assertOk();
});

it('sends a visitor to sign in from every signed-in area, and refuses a signed link without one', function () {
    foreach (['/app/client', '/app/client/projects', '/app/client/briefs/create', '/app/contributor', '/app/contributor/work', '/app/contributor/payouts',
        '/notifications', '/settings/privacy', '/settings/notifications', '/dashboard'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }

    expect($this->get('/admin')->headers->get('Location'))->toContain('/admin/login');
    expect($this->get('/admin/projects')->headers->get('Location'))->toContain('/admin/login');

    $this->post('/logout')->assertRedirect();
    $this->get('/files/not-a-real-file')->assertRedirect('/login');
});

it('keeps pages that are switched off away from visitors, and says nothing about what is behind them', function () {
    app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, false);
    app(FeatureFlags::class)->set(FeatureFlag::PublicDirectories, false);
    app(FeatureFlags::class)->flush();

    OpenRole::factory()->published()->public()->create(['title' => 'Role behind a flag']);

    foreach (['/opportunities', '/contributors', '/organizations'] as $url) {
        $this->get($url)->assertNotFound();
    }

    $this->get('/')->assertOk()->assertDontSee('Role behind a flag');
});
