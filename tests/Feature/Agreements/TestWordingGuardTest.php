<?php

use App\Actions\Agreements\PublishAgreementTemplateVersion;
use App\Enums\AgreementType;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\CreateAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\EditAgreementTemplate;
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

it('sees through markup, entities and spacing that split the banner (SUGGESTIONS 324)', function (string $body) {
    expect(AgreementTemplate::bodyHasTestWording($body))->toBeTrue();

    asProduction();
    expect(fn () => publishTest($body))->toThrow(ValidationException::class);
    expect(AgreementTemplate::query()->count())->toBe(0);
})->with([
    'tags split the words' => '<p>TEST <strong>TEXT</strong>, NOT <em>LEGAL</em> WORDING</p>',
    'tag inside a word' => '<p>TE<b>ST</b> TEXT, NOT LEGAL WORDING</p>',
    'non-breaking space entity' => '<p>TEST&nbsp;TEXT,&nbsp;NOT&nbsp;LEGAL&nbsp;WORDING</p>',
    'numeric entity' => '<p>TEST&#160;TEXT, NOT LEGAL&#32;WORDING</p>',
    'raw non-breaking space' => "<p>TEST\u{00A0}TEXT, NOT\u{00A0}LEGAL WORDING</p>",
    'narrow no-break space' => "<p>TEST\u{202F}TEXT, NOT LEGAL WORDING</p>",
    'several spaces and a line break' => "<p>TEST   TEXT,\n   NOT\tLEGAL  \r\n WORDING</p>",
    'zero-width characters' => "<p>TEST\u{200B} TEXT, NOT LE\u{FEFF}GAL WORDING</p>",
    'inside a comment-split tag' => '<p>TEST TEXT, NOT <!-- x -->LEGAL WORDING</p>',
    'lower case across tags' => '<p>test <b>text</b>, not legal wording</p>',
]);

it('does not flag wording that only mentions test or text', function (string $body) {
    expect(AgreementTemplate::bodyHasTestWording($body))->toBeFalse();
})->with([
    'part of the banner' => '<p>TEST TEXT</p>',
    'other words' => '<p>This is the legal wording. Not a test.</p>',
    'empty' => '',
    'markup only' => '<p></p><br>',
]);

it('treats a null body as no test wording', function () {
    expect(AgreementTemplate::bodyHasTestWording(null))->toBeFalse();
});

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

/*
 * Filament's form test helpers only work while the app still reports "testing" (they check runningUnitTests()), so the Revise page
 * is filled in first and production is switched on just before the save. The save is what the guard sits behind.
 */
it('refuses test wording from the Revise page too, and keeps the live version (SUGGESTIONS 325)', function () {
    $live = publishTest('<p>Approved wording.</p>');

    $page = Livewire::actingAs(adminUser())->test(EditAgreementTemplate::class, ['record' => $live->getKey()])
        ->fillForm(['title' => 'Client MSA', 'body_html' => TEST_BODY]);
    asProduction();
    $page->call('save')->assertHasFormErrors(['body_html']);

    expect(AgreementTemplate::query()->count())->toBe(1)
        ->and($live->refresh()->is_active)->toBeTrue()
        ->and(AgreementTemplate::currentFor(AgreementType::ClientMsa)->version)->toBe(1);
});

it('refuses a split banner from the Revise page', function () {
    $live = publishTest('<p>Approved wording.</p>');

    $page = Livewire::actingAs(adminUser())->test(EditAgreementTemplate::class, ['record' => $live->getKey()])
        ->fillForm(['title' => 'Client MSA', 'body_html' => '<p>TEST&nbsp;<b>TEXT</b>, NOT LEGAL WORDING</p>']);
    asProduction();
    $page->call('save')->assertHasFormErrors(['body_html']);

    expect(AgreementTemplate::query()->count())->toBe(1);
});

it('lets the Revise page publish real wording on production, with the new text', function () {
    $live = publishTest('<p>Approved wording.</p>');

    $page = Livewire::actingAs(adminUser())->test(EditAgreementTemplate::class, ['record' => $live->getKey()])
        ->fillForm(['title' => 'Client MSA', 'body_html' => '<p>Approved wording, revised.</p>']);
    asProduction();
    $page->call('save')->assertHasNoFormErrors();

    $current = AgreementTemplate::currentFor(AgreementType::ClientMsa);
    expect($current->version)->toBe(2)
        ->and($current->body_html)->toContain('Approved wording, revised.')
        ->and($live->refresh()->body_html)->toContain('Approved wording.')
        ->and($live->is_active)->toBeFalse();
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
