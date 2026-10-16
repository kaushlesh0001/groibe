<?php

use App\Actions\Agreements\AcceptAgreement;
use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Agreements\PublishAgreementTemplateVersion;
use App\Actions\Contributors\AcceptMemberAgreement;
use App\Actions\Contributors\SubmitContributorForReview;
use App\Actions\Sows\AcceptSow;
use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\CreateAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\ListAgreementTemplates;
use App\Livewire\Client\Agreements\AcceptMsa;
use App\Livewire\Contributor\Onboarding;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Agreements\AgreementGate;
use Database\Seeders\AgreementTemplateSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * A placeholder agreement counts as not published (spec 8, SCR-036). One marker is shared by all five seeded templates, and
 * `AgreementTemplate::currentPublishedFor()` is the one lookup every acceptance path uses: contributor onboarding step 4, the
 * Client MSA page, the Client MSA gate, SOW creation and SOW acceptance. The Agreement Templates screen still opens the
 * placeholder, labelled, so a super admin can replace it.
 */

/** Take the placeholder marker out of the given types, as if the founder had published real wording. */
function approvePlaceholderWording(AgreementType ...$types): void
{
    foreach (AgreementTemplate::query()->whereIn('type', $types)->get() as $template) {
        DB::table('agreement_templates')->where('id', $template->id)->update([
            'body_html' => str_replace(AgreementTemplate::PLACEHOLDER_NOTE, 'Approved wording.', (string) $template->body_html),
        ]);
    }
}

beforeEach(fn () => $this->seed(AgreementTemplateSeeder::class));

describe('the shared marker', function () {
    it('is in all five seeded templates, the SOW template included', function (AgreementType $type) {
        $template = AgreementTemplate::currentFor($type);

        expect($template)->not->toBeNull()
            ->and($template->isPlaceholder())->toBeTrue()
            ->and(substr_count((string) $template->body_html, AgreementTemplate::PLACEHOLDER_NOTE))->toBe(1);
    })->with(AgreementType::cases());

    it('no longer leaves the SOW template with a different sentence than the other four', function () {
        $sow = AgreementTemplate::currentFor(AgreementType::SowTemplate);

        expect($sow->body_html)->not->toContain('approved SOW wording')
            ->and($sow->body_html)->toContain('{{milestones_table}}');
    });

    it('makes every placeholder unpublished, and real wording published', function () {
        foreach (AgreementType::cases() as $type) {
            expect(AgreementTemplate::currentFor($type))->not->toBeNull()
                ->and(AgreementTemplate::currentPublishedFor($type))->toBeNull();
        }

        approvePlaceholderWording(AgreementType::MemberAgreement, AgreementType::ClientMsa, AgreementType::SowTemplate);

        foreach ([AgreementType::MemberAgreement, AgreementType::ClientMsa, AgreementType::SowTemplate] as $type) {
            expect(AgreementTemplate::currentPublishedFor($type)?->is(AgreementTemplate::currentFor($type)))->toBeTrue();
        }

        expect(AgreementTemplate::currentPublishedFor(AgreementType::Terms))->toBeNull()
            ->and(AgreementTemplate::currentPublishedFor(AgreementType::Privacy))->toBeNull();
    });

    it('keeps /terms and /privacy at 404 while they are placeholders', function () {
        $this->get('/terms')->assertNotFound();
        $this->get('/privacy')->assertNotFound();
    });
});

describe('contributor onboarding step 4 and the Member Agreement', function () {
    it('says the agreement is not published and offers no accept button', function () {
        $user = completedContributor();

        Livewire::actingAs($user)->test(Onboarding::class)
            ->assertSet('step', 4)
            ->assertSee('The Member Agreement is not published yet')
            ->assertDontSee('I have read and accept the Member Agreement')
            ->assertDontSee('Accept and continue')
            ->assertDontSee('Placeholder text for Member Agreement');
    });

    it('refuses an acceptance sent anyway, and stores nothing', function () {
        $user = completedContributor();

        Livewire::actingAs($user)->test(Onboarding::class)
            ->set('agreed', true)
            ->call('acceptAgreement')
            ->assertHasErrors('agreed');

        expect(fn () => app(AcceptMemberAgreement::class)($user))->toThrow(ActionBlocked::class, 'No active Member Agreement is published')
            ->and(AgreementAcceptance::query()->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('does not count an acceptance of the placeholder, so the contributor cannot be submitted', function () {
        $user = completedContributor();
        app(AcceptAgreement::class)(AgreementTemplate::currentFor(AgreementType::MemberAgreement), $user);

        expect(AgreementAcceptance::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(fn () => app(AcceptMemberAgreement::class)($user))->toThrow(ActionBlocked::class)
            ->and(fn () => app(SubmitContributorForReview::class)($user->profile, $user))->toThrow(ActionBlocked::class);

        Livewire::actingAs($user)->test(Onboarding::class)->assertSee('The Member Agreement is not published yet');
    });

    it('accepts again once real wording is published, as a new acceptance', function () {
        $user = completedContributor();
        app(AcceptAgreement::class)(AgreementTemplate::currentFor(AgreementType::MemberAgreement), $user);

        $real = app(PublishAgreementTemplateVersion::class)(AgreementType::MemberAgreement, adminUser(), ['title' => 'Member Agreement', 'body_html' => '<p>Real member wording</p>']);

        app(AcceptMemberAgreement::class)($user);

        expect(AgreementAcceptance::query()->where('user_id', $user->id)->where('template_id', $real->id)->count())->toBe(1)
            ->and(AgreementAcceptance::query()->where('user_id', $user->id)->count())->toBe(2);
    });
});

describe('the Client MSA page and gate', function () {
    beforeEach(function () {
        $this->org = Organization::factory()->verified()->create();
        $this->owner = clientUser($this->org, OrgRole::Owner);
    });

    it('says the MSA has not been published and stores no acceptance', function () {
        $this->actingAs($this->owner)->get('/app/client/agreements/client-msa')
            ->assertOk()
            ->assertSee('The Client MSA has not been published yet')
            ->assertDontSee('Placeholder text for Client MSA');

        Livewire::actingAs($this->owner)->test(AcceptMsa::class)
            ->set('confirmed', true)->call('accept')->assertHasErrors('confirmed');

        expect(AgreementAcceptance::query()->where('organization_id', $this->org->id)->exists())->toBeFalse();
    });

    it('keeps the gate closed, so no brief can be raised', function () {
        $gate = app(AgreementGate::class);

        expect($gate->hasAcceptedClientMsa($this->org))->toBeFalse()
            ->and(fn () => $gate->assertMayRaiseBrief($this->org))->toThrow(ActionBlocked::class)
            ->and(fn () => app(AcceptClientMsa::class)($this->owner, $this->org))->toThrow(ActionBlocked::class, 'No active Client MSA is published');
    });

    it('does not count an existing acceptance of the placeholder, and needs a new one for real wording', function () {
        $gate = app(AgreementGate::class);
        app(AcceptAgreement::class)(AgreementTemplate::currentFor(AgreementType::ClientMsa), $this->owner, $this->org);

        expect(AgreementAcceptance::query()->where('organization_id', $this->org->id)->count())->toBe(1)
            ->and($gate->hasAcceptedClientMsa($this->org))->toBeFalse()
            ->and(fn () => $gate->assertClientMsaAccepted($this->org))->toThrow(ActionBlocked::class);

        app(PublishAgreementTemplateVersion::class)(AgreementType::ClientMsa, adminUser(), ['title' => 'Client MSA', 'body_html' => '<p>Real MSA wording</p>']);

        expect($gate->hasAcceptedClientMsa($this->org))->toBeFalse();

        app(AcceptClientMsa::class)($this->owner, $this->org);

        expect($gate->hasAcceptedClientMsa($this->org))->toBeTrue();
    });
});

describe('SOW creation and acceptance', function () {
    beforeEach(function () {
        Notification::fake();
        $this->org = Organization::factory()->verified()->create();
        $this->owner = clientUser($this->org, OrgRole::Owner);
        $this->dm = adminUser(AdminRole::DeliveryManager);
        $this->project = Project::factory()->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);
    });

    it('refuses to build a SOW from the placeholder template', function () {
        expect(fn () => app(CreateSow::class)($this->project, $this->dm))->toThrow(ActionBlocked::class, 'No active SOW template is published')
            ->and($this->project->sows()->count())->toBe(0);
    });

    it('builds a SOW once real wording is published', function () {
        approvePlaceholderWording(AgreementType::SowTemplate);

        expect(app(CreateSow::class)($this->project, $this->dm)->status)->toBe(SowStatus::Draft);
    });

    it('refuses to accept a SOW whose template is, or has become, a placeholder', function () {
        approvePlaceholderWording(AgreementType::SowTemplate, AgreementType::ClientMsa);
        app(AcceptClientMsa::class)($this->owner, $this->org);

        $sow = app(SendSow::class)(app(CreateSow::class)($this->project, $this->dm), $this->dm);

        // The template the SOW was built from is a placeholder again, as for a SOW built before the rule existed.
        $template = AgreementTemplate::query()->findOrFail($sow->templateId());
        DB::table('agreement_templates')->where('id', $template->id)->update(['body_html' => '<p>Draft. '.AgreementTemplate::PLACEHOLDER_NOTE.'</p>']);

        expect(fn () => app(AcceptSow::class)($sow, $this->owner, true))->toThrow(ActionBlocked::class, 'No active SOW template is published')
            ->and($sow->refresh()->status)->toBe(SowStatus::Sent)
            ->and($this->project->refresh()->status)->toBe(ProjectStatus::SowSent)
            ->and(AgreementAcceptance::query()->where('project_id', $this->project->id)->exists())->toBeFalse();
    });

    it('accepts a SOW built from real wording', function () {
        approvePlaceholderWording(AgreementType::SowTemplate, AgreementType::ClientMsa);
        app(AcceptClientMsa::class)($this->owner, $this->org);

        $sow = app(SendSow::class)(app(CreateSow::class)($this->project, $this->dm), $this->dm);

        expect(app(AcceptSow::class)($sow, $this->owner, true)->status)->toBe(SowStatus::Accepted);
    });
});

describe('the Agreement Templates screen', function () {
    it('labels each template as a placeholder or real wording', function () {
        Livewire::actingAs(adminUser())->test(ListAgreementTemplates::class)
            ->assertSee('Still a placeholder')
            ->assertDontSee('Real wording');

        approvePlaceholderWording(AgreementType::ClientMsa);

        Livewire::actingAs(adminUser())->test(ListAgreementTemplates::class)
            ->assertSee('Still a placeholder')
            ->assertSee('Real wording');
    });

    it('still opens the placeholder for replacement, with a notice', function () {
        expect(AgreementTemplate::currentFor(AgreementType::ClientMsa)?->isPlaceholder())->toBeTrue();

        Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
            ->fillForm(['type' => AgreementType::ClientMsa->value])
            ->assertSee('still carries the placeholder note');
    });

    it('shows no notice once the text is real', function () {
        approvePlaceholderWording(AgreementType::ClientMsa);

        Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
            ->fillForm(['type' => AgreementType::ClientMsa->value])
            ->assertDontSee('still carries the placeholder note');
    });
});

describe('demo data', function () {
    it('publishes labelled demo wording for the three acceptance documents, once, and leaves Terms and Privacy alone', function () {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        foreach ([AgreementType::MemberAgreement, AgreementType::ClientMsa, AgreementType::SowTemplate] as $type) {
            $current = AgreementTemplate::currentPublishedFor($type);

            expect($current)->not->toBeNull()
                ->and($current->version)->toBe(2)
                ->and($current->title)->toContain('demo wording')
                ->and($current->body_html)->toContain('not legal text')
                ->and(AgreementTemplate::query()->where('type', $type)->count())->toBe(2);
        }

        expect(AgreementTemplate::currentPublishedFor(AgreementType::Terms))->toBeNull()
            ->and(AgreementTemplate::currentPublishedFor(AgreementType::Privacy))->toBeNull();
    });
});
