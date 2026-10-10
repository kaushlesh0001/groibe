<?php

use App\Actions\Agreements\PublishAgreementTemplateVersion;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\CreateAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\EditAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\ListAgreementTemplates;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('shows the Agreement Templates screen under System even before any template exists', function () {
    asUser(adminUser())->get('/admin/agreement-templates')
        ->assertOk()
        ->assertSee('Agreement Templates')
        ->assertSee('New version');
});

it('lets the super admin publish the first version of a type from an empty database', function () {
    expect(AgreementTemplate::query()->count())->toBe(0);

    Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
        ->fillForm([
            'type' => AgreementType::MemberAgreement->value,
            'title' => 'Member Agreement',
            'body_html' => '<p>Approved member wording</p>',
            'effective_from' => now()->subDay()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $current = AgreementTemplate::currentFor(AgreementType::MemberAgreement);
    expect($current->version)->toBe(1)
        ->and($current->is_active)->toBeTrue()
        ->and($current->body_html)->toContain('Approved member wording')
        ->and($current->effective_from->isYesterday())->toBeTrue();
});

it('publishes the next version and keeps the old one on record for its acceptances', function () {
    publishAgreements();
    $v1 = AgreementTemplate::currentFor(AgreementType::ClientMsa);
    $owner = acceptClientMsa(Organization::factory()->verified()->create());
    $originalBody = $v1->body_html;

    Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
        ->fillForm([
            'type' => AgreementType::ClientMsa->value,
            'title' => 'Client MSA 2026',
            'body_html' => '<p>New MSA wording</p>',
            'effective_from' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $v1->refresh();
    $v2 = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    expect($v2->version)->toBe(2)
        ->and($v2->title)->toBe('Client MSA 2026')
        ->and($v1->is_active)->toBeFalse()
        ->and($v1->body_html)->toBe($originalBody)
        ->and(AgreementTemplate::query()->where('type', 'client_msa')->count())->toBe(2)
        ->and(AgreementAcceptance::query()->where('user_id', $owner->id)->sole()->template_id)->toBe($v1->id);
});

it('publishes a new version when an existing one is revised, never editing it in place', function () {
    publishAgreements();
    $v1 = AgreementTemplate::currentFor(AgreementType::Privacy);

    Livewire::actingAs(adminUser())->test(EditAgreementTemplate::class, ['record' => $v1->getKey()])
        ->assertFormSet(['version' => 1])
        ->fillForm(['title' => 'Privacy Policy', 'body_html' => '<p>Revised</p>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($v1->refresh()->body_html)->toContain('Placeholder')
        ->and(AgreementTemplate::currentFor(AgreementType::Privacy)->version)->toBe(2);
});

it('refuses to change the wording of a saved version at the model level', function () {
    publishAgreements();
    $template = AgreementTemplate::currentFor(AgreementType::Terms);

    foreach (['title' => 'Other', 'body_html' => '<p>Other</p>', 'version' => 9, 'type' => 'privacy', 'effective_from' => now()->addYear()] as $field => $value) {
        expect(fn () => $template->fresh()->update([$field => $value]))->toThrow(LogicException::class);
    }

    expect($template->fresh()->body_html)->toContain('Placeholder');

    $template->fresh()->update(['is_active' => false]);
    expect($template->fresh()->is_active)->toBeFalse();
});

it('rejects an effective date in the future and missing fields', function () {
    Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
        ->fillForm([
            'type' => AgreementType::Terms->value,
            'title' => 'Terms',
            'body_html' => '<p>Terms</p>',
            'effective_from' => now()->addWeek()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['effective_from']);

    Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
        ->fillForm(['type' => null, 'title' => '', 'body_html' => ''])
        ->call('create')
        ->assertHasFormErrors(['type', 'title', 'body_html']);

    expect(AgreementTemplate::query()->count())->toBe(0);
});

it('describes where every agreement type is used', function () {
    foreach (AgreementType::cases() as $type) {
        expect($type->usedAt())->not->toBeEmpty();
    }

    expect(AgreementType::MemberAgreement->usedAt())->toContain('onboarding')
        ->and(AgreementType::ClientMsa->usedAt())->toContain('first brief');

    publishAgreements();
    Livewire::actingAs(adminUser())->test(ListAgreementTemplates::class)
        ->assertSee(AgreementType::MemberAgreement->usedAt())
        ->assertSee(AgreementType::ClientMsa->usedAt());
});

it('shows the where-used note on the create form once a type is picked', function () {
    Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
        ->assertSee('Pick a type to see where it is used.')
        ->fillForm(['type' => AgreementType::ClientMsa->value])
        ->assertSee('Where this is used: '.AgreementType::ClientMsa->usedAt());
});

it('keeps publishing out of reach for every admin role except super admin', function () {
    publishAgreements();
    $template = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    foreach ([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
        asUser(adminUser($role))->get('/admin/agreement-templates/create')->assertForbidden();
        asUser(adminUser($role))->get("/admin/agreement-templates/{$template->getKey()}/edit")->assertForbidden();
        asUser(adminUser($role))->get('/admin/agreement-templates')->assertOk()->assertDontSee('New version');
    }

    expect(fn () => app(PublishAgreementTemplateVersion::class)(
        AgreementType::ClientMsa, adminUser(AdminRole::DeliveryManager), ['title' => 'x', 'body_html' => 'x'],
    ))->toThrow(AuthorizationException::class);

    expect(AgreementTemplate::query()->where('type', 'client_msa')->count())->toBe(1);
});

it('keeps the screen away from guests, contributors and clients', function () {
    asUser(contributorUser())->get('/admin/agreement-templates')->assertForbidden();
    asUser(clientUser())->get('/admin/agreement-templates/create')->assertForbidden();

    test()->flushSession();
    auth()->forgetGuards();
    $this->get('/admin/agreement-templates/create')->assertRedirect('/admin/login');
});

it('logs each published version in the audit trail', function () {
    $admin = adminUser();

    app(PublishAgreementTemplateVersion::class)(AgreementType::Terms, $admin, ['title' => 'Terms', 'body_html' => '<p>T</p>']);

    $log = Activity::query()->where('log_name', 'agreement')->where('event', 'version_published')->sole();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->properties['version'])->toBe(1);
});
