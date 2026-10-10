<?php

use App\Actions\Agreements\AcceptAgreement;
use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Agreements\CreateAgreementTemplateVersion;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\OrgRole;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use App\Services\Agreements\AgreementGate;
use Database\Seeders\AgreementTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => publishAgreements());

it('seeds one active placeholder per agreement type without hard-coded legal wording', function () {
    expect(AgreementTemplate::query()->count())->toBe(count(AgreementType::cases()));

    foreach (AgreementType::cases() as $type) {
        $template = AgreementTemplate::currentFor($type);
        expect($template)->not->toBeNull()
            ->and($template->version)->toBe(1)
            ->and($template->body_html)->toContain('Placeholder');
    }
});

it('seeding twice does not duplicate templates', function () {
    $this->seed(AgreementTemplateSeeder::class);

    expect(AgreementTemplate::query()->count())->toBe(count(AgreementType::cases()));
});

it('publishes a new version, retires the old one and keeps old acceptances pointing at the old text', function () {
    $org = Organization::factory()->verified()->create();
    $owner = acceptClientMsa($org);
    $v1 = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    $v2 = app(CreateAgreementTemplateVersion::class)($v1, adminUser(), ['title' => 'Client MSA', 'body_html' => '<p>Updated</p>']);

    expect($v2->version)->toBe(2)
        ->and($v2->is_active)->toBeTrue()
        ->and($v1->refresh()->is_active)->toBeFalse()
        ->and($v1->body_html)->not->toContain('Updated')
        ->and(AgreementTemplate::currentFor(AgreementType::ClientMsa)->is($v2))->toBeTrue()
        ->and(AgreementAcceptance::query()->where('user_id', $owner->id)->sole()->template_id)->toBe($v1->id);
});

it('only a super admin may publish template versions', function () {
    $v1 = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    foreach ([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
        expect(fn () => app(CreateAgreementTemplateVersion::class)($v1, adminUser($role), ['title' => 'x', 'body_html' => 'x']))
            ->toThrow(AuthorizationException::class);
    }
    expect(AgreementTemplate::query()->where('type', 'client_msa')->count())->toBe(1);
});

it('lets every admin role read templates but not clients or contributors', function () {
    $template = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    foreach (AdminRole::cases() as $role) {
        expect(adminUser($role)->can('view', $template))->toBeTrue();
    }

    expect(clientUser()->can('view', $template))->toBeFalse()
        ->and(contributorUser()->can('view', $template))->toBeFalse()
        ->and(adminUser(AdminRole::DeliveryManager)->can('update', $template))->toBeFalse()
        ->and(adminUser()->can('update', $template))->toBeTrue();
});

it('does not allow deleting a template that has acceptances', function () {
    $org = Organization::factory()->verified()->create();
    acceptClientMsa($org);
    $template = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    expect(adminUser()->can('delete', $template))->toBeFalse()
        ->and(adminUser()->can('delete', AgreementTemplate::currentFor(AgreementType::Terms)))->toBeTrue();
});

it('records user, organization, version, time, ip and user agent when the owner accepts the Client MSA', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);

    $acceptance = app(AcceptClientMsa::class)($owner, $org, '198.51.100.4', 'TestAgent/1.0');

    expect($acceptance->user_id)->toBe($owner->id)
        ->and($acceptance->organization_id)->toBe($org->id)
        ->and($acceptance->template_id)->toBe(AgreementTemplate::currentFor(AgreementType::ClientMsa)->id)
        ->and($acceptance->ip)->toBe('198.51.100.4')
        ->and($acceptance->user_agent)->toBe('TestAgent/1.0')
        ->and($acceptance->accepted_at)->not->toBeNull();
    expect(app(AgreementGate::class)->hasAcceptedClientMsa($org))->toBeTrue();
    expect(Activity::query()->where('log_name', 'agreement')->where('event', 'accepted')->exists())->toBeTrue();
});

it('is idempotent per user and version', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);

    app(AcceptClientMsa::class)($owner, $org);
    app(AcceptClientMsa::class)($owner, $org);

    expect(AgreementAcceptance::query()->count())->toBe(1);
});

it('only the owner of that organization can accept the Client MSA', function () {
    $org = Organization::factory()->verified()->create();
    clientUser($org, OrgRole::Owner);
    $manager = clientUser($org, OrgRole::Manager);
    $viewer = clientUser($org, OrgRole::Viewer);
    $foreignOwner = clientUser(Organization::factory()->verified()->create(), OrgRole::Owner);

    foreach ([$manager, $viewer, $foreignOwner, contributorUser(), adminUser()] as $actor) {
        expect(fn () => app(AcceptClientMsa::class)($actor, $org))->toThrow(AuthorizationException::class);
    }

    expect(AgreementAcceptance::query()->count())->toBe(0)
        ->and(app(AgreementGate::class)->hasAcceptedClientMsa($org))->toBeFalse();
});

it('does not let an acceptance by another organization satisfy the gate', function () {
    $other = Organization::factory()->verified()->create();
    acceptClientMsa($other);
    $org = Organization::factory()->verified()->create();
    clientUser($org, OrgRole::Owner);

    expect(app(AgreementGate::class)->hasAcceptedClientMsa($org))->toBeFalse();
});

it('refuses to record acceptance of a retired version', function () {
    $v1 = AgreementTemplate::currentFor(AgreementType::ClientMsa);
    app(CreateAgreementTemplateVersion::class)($v1, adminUser(), ['title' => 'v2', 'body_html' => '<p>v2</p>']);
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);

    expect(fn () => app(AcceptAgreement::class)($v1->refresh(), $owner, $org))->toThrow(ActionBlocked::class);
});

it('keeps acceptances append-only', function () {
    $org = Organization::factory()->verified()->create();
    $owner = acceptClientMsa($org);
    $acceptance = AgreementAcceptance::query()->sole();

    expect($owner->can('update', $acceptance))->toBeFalse()
        ->and($owner->can('delete', $acceptance))->toBeFalse()
        ->and(adminUser()->can('update', $acceptance))->toBeFalse()
        ->and(adminUser()->can('delete', $acceptance))->toBeFalse()
        ->and($owner->can('view', $acceptance))->toBeTrue()
        ->and(clientUser($org, OrgRole::Manager)->can('view', $acceptance))->toBeFalse();
});
