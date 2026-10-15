<?php

use App\Enums\BriefStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Livewire\Client\Briefs\BriefForm;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\User;
use App\Services\Organizations\OrganizationSlug;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Regression/helpers.php';

/** The registration form filled in the way a person would, with room to change any field. */
function registerClientForm(array $with = [])
{
    $values = [
        'name' => 'Ravi Shah', 'email' => 'ravi@acme.example', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
        'designation' => 'Founder', 'organization_name' => 'Acme Foods', 'legal_name' => 'Acme Foods Private Limited', 'gstin' => '27abcde1234f1z5',
        'website' => 'https://acme.example', 'industry' => 'FMCG', 'size_band' => '51-200', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India',
        ...$with,
    ];

    $component = Volt::test('auth.register-client');

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

/** A client of a pending organization, signed up through the form and with the email confirmed, as the emailed link does. */
function pendingClient(array $with = []): User
{
    registerClientForm($with)->call('register')->assertHasNoErrors();

    $user = User::query()->where('email', $with['email'] ?? 'ravi@acme.example')->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();

    return $user;
}

/** Walks the guided brief form to the end of its last step, ready to submit. */
function filledBriefForm(User $client)
{
    return Livewire::actingAs($client)->test(BriefForm::class)
        ->set('form.title', 'Shopper study')
        ->set('form.problem', 'We do not know why shoppers leave without buying in our regional stores.')
        ->set('form.desired_outcome', 'A clear ranked list of reasons, with evidence, within two months.')
        ->call('next')->set('form.project_type_id', ProjectType::factory()->create()->id)->set('form.skill_ids', [Skill::factory()->create()->id])->call('next')
        ->set('form.budget_band', '3l_10l')->set('form.timeline_band', '1_3m')->call('next')
        ->set('form.confidentiality', 'standard')->call('next');
}

// ---- The form ---------------------------------------------------------------------------------------------------------

it('asks for the organization details of spec 12, each field with a label', function () {
    $html = $this->get('/register/client')->assertOk()->assertSee('Your organization')->getContent();

    foreach (['Organization name', 'Registered legal name', 'GSTIN', 'Website', 'Industry', 'Number of people', 'City', 'State', 'Country', 'Your role in the organization'] as $label) {
        expect($html)->toContain($label);
    }

    foreach (config('groibe.organizations.size_bands') as $band) {
        expect($html)->toContain($band);
    }
});

// ---- Registering ------------------------------------------------------------------------------------------------------

it('creates the account, a pending organization and the owner link together', function () {
    registerClientForm()->call('register')->assertHasNoErrors()->assertRedirect(route('verification.notice', absolute: false));

    $user = User::query()->where('email', 'ravi@acme.example')->firstOrFail();
    $organization = Organization::query()->sole();

    expect($user->role)->toBe(UserRole::Client)
        ->and($organization->name)->toBe('Acme Foods')
        ->and($organization->slug)->toBe('acme-foods')
        ->and($organization->legal_name)->toBe('Acme Foods Private Limited')
        ->and($organization->gstin)->toBe('27ABCDE1234F1Z5')            // upper-cased
        ->and($organization->website)->toBe('https://acme.example')
        ->and($organization->industry)->toBe('FMCG')
        ->and($organization->size_band)->toBe('51-200')
        ->and([$organization->city, $organization->state, $organization->country])->toBe(['Mumbai', 'Maharashtra', 'India'])
        ->and($organization->status)->toBe(OrganizationStatus::Pending)
        ->and($organization->verified_at)->toBeNull()
        ->and($organization->can_submit_briefs)->toBeFalse()
        ->and($organization->is_public)->toBeFalse()
        ->and($organization->created_by)->toBe($user->id)
        ->and($user->organizationRole($organization))->toBe(OrgRole::Owner)
        ->and($user->organizations()->count())->toBe(1)
        ->and($organization->users()->first()->pivot->designation)->toBe('Founder');

    $this->assertAuthenticatedAs($user);
    expect(Activity::query()->where('log_name', 'organization')->where('event', 'registered')->where('causer_id', $user->id)->count())->toBe(1);
});

it('needs only the organization name beyond the account', function () {
    registerClientForm(['designation' => '', 'legal_name' => '', 'gstin' => '', 'website' => '', 'industry' => '', 'size_band' => '', 'city' => '', 'state' => ''])
        ->call('register')->assertHasNoErrors();

    $organization = Organization::query()->sole();

    expect($organization->name)->toBe('Acme Foods')->and($organization->gstin)->toBeNull()->and($organization->website)->toBeNull();
});

it('gives each organization its own slug, even for the same name', function () {
    pendingClient();
    pendingClient(['email' => 'second@acme.example', 'gstin' => '']);

    expect(Organization::query()->orderBy('id')->pluck('slug')->all())->toBe(['acme-foods', 'acme-foods-2']);
});

it('refuses bad details and creates nothing at all', function (array $bad, string $field) {
    registerClientForm($bad)->call('register')->assertHasErrors($field);

    expect(User::query()->count())->toBe(0)->and(Organization::query()->count())->toBe(0);
})->with([
    'no organization name' => [['organization_name' => ''], 'organization_name'],
    'GSTIN too short' => [['gstin' => '27ABCDE'], 'gstin'],
    'GSTIN with symbols' => [['gstin' => '27ABCDE1234F1Z!'], 'gstin'],
    'website that is not a link' => [['website' => 'not a site'], 'website'],
    'a size that is not offered' => [['size_band' => 'a lot'], 'size_band'],
    'no country' => [['country' => ''], 'country'],
    'a very long organization name' => [['organization_name' => str_repeat('a', 256)], 'organization_name'],
]);

it('leaves no account behind when the organization cannot be created', function () {
    $this->app->bind(OrganizationSlug::class, fn () => new class extends OrganizationSlug
    {
        public function unique(string $name): string
        {
            throw new RuntimeException('database went away');
        }
    });

    expect(fn () => registerClientForm()->call('register'))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0)->and(Organization::query()->count())->toBe(0);
});

// ---- Never joining someone else's organization ----------------------------------------------------------------------------

it('refuses a GSTIN that is already registered, without naming whose it is', function () {
    $existing = Organization::factory()->verified()->create(['name' => 'Secret Holdings Ltd', 'gstin' => '27ABCDE1234F1Z5']);

    $component = registerClientForm(['organization_name' => 'Someone Else'])->call('register')->assertHasErrors('gstin');
    $message = $component->errors()->first('gstin');

    expect($message)->toContain('already registered')->not->toContain('Secret')->not->toContain($existing->slug)
        ->and(User::query()->count())->toBe(0)
        ->and($existing->users()->count())->toBe(0);
});

it('creates a separate organization when the name matches an existing one, and never links to it', function () {
    $existing = Organization::factory()->verified()->create(['name' => 'Acme Foods', 'slug' => 'acme-foods']);
    $owner = clientUser($existing, OrgRole::Owner);

    $newcomer = pendingClient();
    $theirs = $newcomer->organizations()->sole();

    expect($theirs->id)->not->toBe($existing->id)
        ->and($theirs->status)->toBe(OrganizationStatus::Pending)
        ->and($existing->users()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($existing->refresh()->status)->toBe(OrganizationStatus::Verified);
});

it('shows a new organization none of another organization\'s data, on any page or address', function () {
    Storage::fake('local');
    $world = leakWorld('ALPHA');
    $newcomer = pendingClient();

    foreach (['/app/client', '/app/client/briefs', '/app/client/projects', '/app/client/deliverables', '/app/client/messages', '/notifications'] as $url) {
        $response = $this->actingAs($newcomer)->get($url);

        expect($response->getStatusCode())->toBe(200)
            ->and(firstMarkerIn($response->getContent(), [...$world['tenant'], ...$world['internal']]))->toBeNull("a marker of another organization is on {$url}");
    }

    foreach ([
        "/app/client/projects/{$world['project']->uuid}",
        "/app/client/briefs/{$world['brief']->uuid}",
        "/app/client/messages/{$world['thread']->uuid}",
        signedFileLink($world['briefFile']),
    ] as $url) {
        expect($this->actingAs($newcomer)->get($url)->getStatusCode())->toBeIn([403, 404], $url);
    }
});

// ---- The pending state ------------------------------------------------------------------------------------------------

it('tells a pending client plainly that the organization is waiting for verification, on the pages they use', function () {
    $client = pendingClient();

    foreach (['/app/client', '/app/client/briefs', '/app/client/briefs/create'] as $url) {
        $this->actingAs($client)->get($url)->assertOk()->assertSee('Waiting for verification')->assertSee('Acme Foods');
    }
});

it('lets a pending client prepare a draft but never submit a brief', function () {
    Storage::fake('local');
    publishAgreements();
    $client = pendingClient();
    acceptClientMsa($client->organizations()->sole());   // the agreement is not what blocks them

    $form = filledBriefForm($client)->call('submit');

    expect($form->errors()->first('submit'))->toContain('must be verified')
        ->and(Brief::query()->where('status', BriefStatus::Submitted->value)->count())->toBe(0);
});

it('lifts the notice and the block when an admin verifies the organization', function () {
    Storage::fake('local');
    publishAgreements();
    $client = pendingClient();
    $organization = $client->organizations()->sole();
    acceptClientMsa($organization);

    $organization->update(['status' => OrganizationStatus::Verified]);

    $this->actingAs($client)->get('/app/client')->assertOk()->assertDontSee('Waiting for verification');
    filledBriefForm($client)->call('submit')->assertHasNoErrors();

    expect(Brief::query()->where('status', BriefStatus::Submitted->value)->count())->toBe(1);
});

it('lets an admin clear a pending organization to submit briefs without verifying it', function () {
    Storage::fake('local');
    publishAgreements();
    $client = pendingClient();
    $organization = $client->organizations()->sole();
    acceptClientMsa($organization);

    $organization->update(['can_submit_briefs' => true]);

    $this->actingAs($client)->get('/app/client')->assertOk()->assertDontSee('Waiting for verification');
    filledBriefForm($client)->call('submit')->assertHasNoErrors();

    expect(Brief::query()->where('status', BriefStatus::Submitted->value)->count())->toBe(1)
        ->and($organization->refresh()->status)->toBe(OrganizationStatus::Pending);
});

it('does not tell a suspended organization it is waiting, and still refuses its briefs', function () {
    Storage::fake('local');
    publishAgreements();
    $client = pendingClient();
    $organization = $client->organizations()->sole();
    acceptClientMsa($organization);
    $organization->update(['status' => OrganizationStatus::Suspended]);

    $this->actingAs($client)->get('/app/client')->assertOk()->assertDontSee('Waiting for verification');

    expect(filledBriefForm($client)->call('submit')->errors()->first('submit'))->toContain('must be verified');
});

it('does not show the notice to people who are not waiting', function () {
    $verified = clientUser(Organization::factory()->verified()->create(), OrgRole::Owner);

    $this->actingAs($verified)->get('/app/client')->assertOk()->assertDontSee('Waiting for verification');
});
