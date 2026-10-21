<?php

use App\Actions\Agreements\PublishAgreementTemplateVersion;
use App\Enums\AgreementType;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\CreateAgreementTemplate;
use App\Filament\Admin\Widgets\SystemStatusWidget;
use App\Models\AgreementTemplate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const TEST_BODY = '<p><strong>TEST TEXT, NOT LEGAL WORDING. This is a staging site.</strong></p><p>Dummy.</p>';

function asProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

function publishTest(string $body = TEST_BODY): AgreementTemplate
{
    return app(PublishAgreementTemplateVersion::class)(AgreementType::ClientMsa, adminUser(), ['title' => 'Client MSA', 'body_html' => $body]);
}

it('allows test wording outside production', function () {
    $template = publishTest();

    expect($template->exists)->toBeTrue()
        ->and($template->isTestWording())->toBeTrue()
        ->and($template->isPlaceholder())->toBeFalse();
});

it('refuses to publish test wording on production and saves nothing', function (string $body) {
    asProduction();

    expect(fn () => publishTest($body))->toThrow(ValidationException::class);
    expect(AgreementTemplate::query()->count())->toBe(0);
})->with([
    'exact' => TEST_BODY,
    'lower case' => '<p>test text, not legal wording</p>',
    'in the middle' => '<h1>Terms</h1><p>x TEST TEXT, NOT LEGAL WORDING y</p>',
]);

it('still publishes real wording on production', function () {
    asProduction();

    $template = publishTest('<p>Approved wording.</p>');

    expect($template->exists)->toBeTrue()->and($template->isTestWording())->toBeFalse();
});

it('does not retire the live version when the refused one is rejected', function () {
    publishTest('<p>Approved wording.</p>');
    asProduction();

    expect(fn () => publishTest())->toThrow(ValidationException::class);

    expect(AgreementTemplate::query()->count())->toBe(1)
        ->and(AgreementTemplate::currentFor(AgreementType::ClientMsa)->is_active)->toBeTrue();
});

it('shows the refusal on the body field of the admin form', function () {
    asProduction();

    Livewire::actingAs(adminUser())->test(CreateAgreementTemplate::class)
        ->fillForm([
            'type' => AgreementType::MemberAgreement->value,
            'title' => 'Member Agreement',
            'body_html' => TEST_BODY,
            'effective_from' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['body_html']);

    expect(AgreementTemplate::query()->count())->toBe(0);
});

describe('System status flag', function () {
    it('flags an active template that already holds test wording on production', function () {
        $template = publishTest();
        asProduction();

        Livewire::actingAs(adminUser())->test(SystemStatusWidget::class)
            ->assertSee('Alert: test wording is live on the site')
            ->assertSee($template->title);
    });

    it('does not flag it on staging, where test wording is expected', function () {
        publishTest();
        app()->detectEnvironment(fn (): string => 'staging');

        Livewire::actingAs(adminUser())->test(SystemStatusWidget::class)->assertDontSee('Alert: test wording');
    });

    it('does not flag real wording or a retired test version on production', function () {
        publishTest();
        publishTest('<p>Approved wording.</p>');
        asProduction();

        Livewire::actingAs(adminUser())->test(SystemStatusWidget::class)->assertDontSee('Alert: test wording');
    });
});
