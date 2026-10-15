<?php

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\UserStatus;
use App\Livewire\Client\OrganizationPage;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Regression/helpers.php';

/*
 * Pass 9 item 8 (review finding), spec 12 and spec 5 / SCR-002: the client Organization page. Owner and manager edit the
 * profile, a viewer reads it, everyone sees who is in the organization, and only the owner invites.
 */

beforeEach(function () {
    Notification::fake();

    $this->org = Organization::factory()->verified()->create(['name' => 'Acme Foods', 'gstin' => '27ABCDE1234F1Z5']);
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);

    $this->page = fn (User $as) => Livewire::actingAs($as)->test(OrganizationPage::class);
});

/** Fills the invite form and sends it. */
function sendInvite($component, string $email = 'new.person@acme.test', string $role = 'manager', string $name = 'New Person')
{
    return $component->set('inviteName', $name)->set('inviteEmail', $email)->set('inviteRole', $role)->call('invite');
}

// ---- Reading --------------------------------------------------------------------------------------------------------

it('is in the client navigation and opens for every role of the organization', function () {
    foreach ([$this->owner, $this->manager, $this->viewer] as $person) {
        $this->actingAs($person)->get('/app/client/organization')->assertOk()->assertSee('Acme Foods')->assertSee('Organization');
        $this->actingAs($person)->get('/app/client')->assertOk()->assertSee(route('client.organization'), false);
    }
});

it('lists the people with their roles, and shows colleagues\' addresses only to those who manage the organization', function () {
    foreach ([$this->owner, $this->manager] as $person) {
        $this->actingAs($person)->get('/app/client/organization')->assertSee($this->viewer->name)->assertSee($this->viewer->email)->assertSee('Owner')->assertSee('Manager')->assertSee('Viewer');
    }

    $this->actingAs($this->viewer)->get('/app/client/organization')
        ->assertSee($this->owner->name)->assertSee('Owner')
        ->assertDontSee($this->owner->email)->assertDontSee($this->manager->email);
});

it('gives a viewer a read-only page, with no save button and no invite form', function () {
    $this->actingAs($this->viewer)->get('/app/client/organization')
        ->assertSee('You can read these details')->assertDontSee('Save details')->assertDontSee('Invite a colleague')->assertDontSee('Send invitation');
});

it('gives a manager the profile form but not the invite form, and the owner both', function () {
    $this->actingAs($this->manager)->get('/app/client/organization')->assertSee('Save details')->assertDontSee('Send invitation');
    $this->actingAs($this->owner)->get('/app/client/organization')->assertSee('Save details')->assertSee('Send invitation');
});

it('shows a pending organization the waiting notice', function () {
    $pending = Organization::factory()->create(['status' => OrganizationStatus::Pending, 'name' => 'Waiting Ltd']);
    $owner = clientUser($pending, OrgRole::Owner);

    $this->actingAs($owner)->get('/app/client/organization')->assertOk()->assertSee('Waiting for verification');
});

it('shows nothing of another organization, its people or its data', function () {
    Storage::fake('local');
    $other = leakWorld('ALPHA');

    $response = $this->actingAs($this->owner)->get('/app/client/organization')->assertOk();

    expect(firstMarkerIn($response->getContent(), [...$other['tenant'], ...$other['internal'], $other['people']['owner']->email, $other['people']['owner']->name]))->toBeNull();
});

it('refuses a client without an organization, a contributor, an admin and a visitor', function () {
    $this->actingAs(User::factory()->client()->create())->get('/app/client/organization')->assertNotFound();
    $this->actingAs(contributorUser())->get('/app/client/organization')->assertForbidden();
    $this->actingAs(adminUser())->get('/app/client/organization')->assertForbidden();

    auth()->forgetGuards();
    $this->flushSession();
    $this->get('/app/client/organization')->assertRedirect('/login');
});

// ---- Editing the profile ----------------------------------------------------------------------------------------------

it('lets the owner and a manager save the details, and records which fields changed without their values', function (string $who) {
    $person = $this->{$who};

    ($this->page)($person)
        ->set('form.industry', 'Packaged food')->set('form.size_band', '51-200')->set('form.city', 'Pune')->set('form.website', 'https://acme.example')
        ->set('form.description', 'We make snacks.')->call('saveProfile')->assertHasNoErrors()->assertSee('Organization details saved');

    $this->org->refresh();

    expect([$this->org->industry, $this->org->size_band, $this->org->city, $this->org->website, $this->org->description])
        ->toBe(['Packaged food', '51-200', 'Pune', 'https://acme.example', 'We make snacks.']);

    $entry = Activity::query()->where('event', 'profile_updated')->latest('id')->firstOrFail();

    expect($entry->causer_id)->toBe($person->id)
        ->and($entry->properties['fields'])->toContain('industry', 'city')
        ->and($entry->properties->toJson())->not->toContain('Packaged food')->not->toContain('We make snacks');
})->with(['owner', 'manager']);

it('refuses a viewer who sends the profile anyway', function () {
    expectRefused(($this->page)($this->viewer)->set('form.industry', 'Hijacked')->call('saveProfile'));

    expect($this->org->refresh()->industry)->not->toBe('Hijacked');
});

it('keeps the name and GSTIN of a verified organization as Groibe checked them', function () {
    ($this->page)($this->owner)->set('form.name', 'Renamed Ltd')->set('form.gstin', '29ZZZZZ9999Z1Z1')->set('form.city', 'Delhi')->call('saveProfile')->assertHasNoErrors();

    $this->org->refresh();

    expect($this->org->name)->toBe('Acme Foods')->and($this->org->gstin)->toBe('27ABCDE1234F1Z5')->and($this->org->city)->toBe('Delhi');
});

it('lets a pending organization correct its name and GSTIN', function () {
    $pending = Organization::factory()->create(['status' => OrganizationStatus::Pending, 'name' => 'Acme Fodos', 'gstin' => null]);
    $owner = clientUser($pending, OrgRole::Owner);

    ($this->page)($owner)->set('form.name', 'Acme Foods Pvt')->set('form.gstin', '27abcde1234f1z9')->call('saveProfile')->assertHasNoErrors();

    expect($pending->refresh()->name)->toBe('Acme Foods Pvt')->and($pending->gstin)->toBe('27ABCDE1234F1Z9')->and($pending->status)->toBe(OrganizationStatus::Pending);
});

it('refuses bad details, and a GSTIN that another organization holds', function () {
    $pending = Organization::factory()->create(['status' => OrganizationStatus::Pending, 'gstin' => null]);
    $owner = clientUser($pending, OrgRole::Owner);

    ($this->page)($owner)->set('form.gstin', 'short')->call('saveProfile')->assertHasErrors('form.gstin');
    ($this->page)($owner)->set('form.website', 'not a link')->call('saveProfile')->assertHasErrors('form.website');
    ($this->page)($owner)->set('form.size_band', 'a lot')->call('saveProfile')->assertHasErrors('form.size_band');
    ($this->page)($owner)->set('form.name', '')->call('saveProfile')->assertHasErrors('form.name');
    ($this->page)($owner)->set('form.gstin', '27ABCDE1234F1Z5')->call('saveProfile')->assertHasErrors('form.gstin');
});

it('edits only the signed-in person\'s own organization, whatever is sent', function () {
    $other = Organization::factory()->verified()->create(['name' => 'Other Co', 'city' => 'Chennai']);

    // The only id on the form is locked: changing it is refused, and the page always works on the person's own organization.
    $page = ($this->page)($this->owner);

    expect(fn () => $page->set('form.organizationId', $other->id))->toThrow(Exception::class, 'Cannot update locked property');

    $page->set('form.city', 'Goa')->call('saveProfile')->assertHasNoErrors();

    expect($other->refresh()->city)->toBe('Chennai')->and($this->org->refresh()->city)->toBe('Goa');
});

// ---- Inviting ------------------------------------------------------------------------------------------------------

it('lets the owner invite a colleague, who gets a working activation link and lands in the organization', function (string $role) {
    sendInvite(($this->page)($this->owner), 'new.person@acme.test', $role)->assertHasNoErrors()->assertSee('Invitation sent to new.person@acme.test');

    $invited = User::query()->where('email', 'new.person@acme.test')->firstOrFail();

    expect($invited->status)->toBe(UserStatus::Pending)->and($invited->password)->toBeNull()
        ->and($invited->organizations()->pluck('organizations.id')->all())->toBe([$this->org->id])
        ->and($invited->organizationRole($this->org)->value)->toBe($role)
        ->and($invited->hasRole('client'))->toBeTrue()
        ->and($invited->profile)->not->toBeNull();

    $link = null;
    Notification::assertSentTo($invited, InviteNotification::class, function (InviteNotification $notice) use (&$link): bool {
        $link = $notice->url;

        return true;
    });

    auth()->forgetGuards();
    $this->flushSession();
    $this->get($link)->assertOk()->assertSee('Activate your account');

    Volt::test('auth.invite', ['token' => $invited->uuid])->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')->call('activate')->assertHasNoErrors();

    expect($invited->refresh()->status)->toBe(UserStatus::Active);
    $this->actingAs($invited)->get('/app/client/organization')->assertOk()->assertSee('Acme Foods');

    expect(Activity::query()->where('event', 'member_invited')->where('causer_id', $this->owner->id)->count())->toBe(1);
})->with(['manager', 'viewer']);

it('refuses an invitation from a manager or a viewer, and creates nobody', function (string $who) {
    $before = User::query()->count();

    expectRefused(sendInvite(($this->page)($this->{$who})));

    expect(User::query()->count())->toBe($before);
    Notification::assertNothingSent();
})->with(['manager', 'viewer']);

it('does not invite an owner, however the request is edited', function () {
    sendInvite(($this->page)($this->owner), 'boss@acme.test', 'owner')->assertHasErrors('inviteRole');

    expect(User::query()->where('email', 'boss@acme.test')->exists())->toBeFalse();
});

it('refuses an email that already has an account, and links nobody to this organization', function () {
    $other = Organization::factory()->verified()->create();
    $stranger = clientUser($other, OrgRole::Owner);
    $gone = User::factory()->client()->create(['email' => 'gone@acme.test']);
    $gone->delete();

    foreach ([$stranger->email, strtoupper($stranger->email), 'gone@acme.test', $this->viewer->email] as $email) {
        sendInvite(($this->page)($this->owner), $email)->assertHasErrors('inviteEmail');
    }

    expect($this->org->users()->pluck('users.id')->all())->toEqualCanonicalizing([$this->owner->id, $this->manager->id, $this->viewer->id])
        ->and($other->users()->pluck('users.id')->all())->toBe([$stranger->id]);
    Notification::assertNothingSent();
});

it('checks the name and the email address', function () {
    sendInvite(($this->page)($this->owner), 'not-an-email')->assertHasErrors('inviteEmail');
    sendInvite(($this->page)($this->owner), 'ok@acme.test', 'viewer', '')->assertHasErrors('inviteName');
});

it('limits an owner to ten invitations an hour', function () {
    $page = ($this->page)($this->owner);

    foreach (range(1, 10) as $n) {
        sendInvite($page, "person{$n}@acme.test", 'viewer')->assertHasNoErrors();
    }

    sendInvite($page, 'person11@acme.test', 'viewer')->assertHasErrors('inviteEmail');

    expect(User::query()->where('email', 'person11@acme.test')->exists())->toBeFalse();
});

// ---- Resending ------------------------------------------------------------------------------------------------------

it('lets the owner resend an invitation to someone who has not activated, and no one else', function () {
    sendInvite(($this->page)($this->owner), 'later@acme.test', 'viewer');
    $waiting = User::query()->where('email', 'later@acme.test')->firstOrFail();
    Notification::fake();

    ($this->page)($this->owner)->assertSee('Invited, not activated')->assertSee('Resend invitation')->call('resend', $waiting->id)->assertSee('A new invitation was sent');

    Notification::assertSentTo($waiting, InviteNotification::class);
});

it('says so when the person has already activated, and sends nothing', function () {
    ($this->page)($this->owner)->call('resend', $this->viewer->id)->assertHasErrors('inviteEmail');

    Notification::assertNothingSent();
});

it('refuses a resend by a manager or a viewer', function (string $who) {
    sendInvite(($this->page)($this->owner), 'later@acme.test', 'viewer');
    $waiting = User::query()->where('email', 'later@acme.test')->firstOrFail();
    Notification::fake();

    expectRefused(($this->page)($this->{$who})->call('resend', $waiting->id));

    Notification::assertNothingSent();
})->with(['manager', 'viewer']);

it('finds nobody of another organization to resend to', function () {
    $otherOrg = Organization::factory()->verified()->create();
    $waiting = User::factory()->client()->invited()->create();
    $otherOrg->users()->attach($waiting->id, ['org_role' => 'viewer']);

    expectRefused(($this->page)($this->owner)->call('resend', $waiting->id));

    Notification::assertNothingSent();
});

it('limits resends to the same person to three an hour', function () {
    sendInvite(($this->page)($this->owner), 'later@acme.test', 'viewer');
    $waiting = User::query()->where('email', 'later@acme.test')->firstOrFail();

    $page = ($this->page)($this->owner);

    foreach (range(1, 3) as $n) {
        $page->call('resend', $waiting->id)->assertHasNoErrors();
    }

    $page->call('resend', $waiting->id)->assertHasErrors('inviteEmail');
});
