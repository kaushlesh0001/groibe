<?php

use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The Pass 3 gate: a private contributor must never appear in any public route, search or sitemap.
 * Each "hidden" case carries a unique marker in its headline, bio and name, plus a shared skill, so every
 * surface can be checked for the marker.
 */
function hiddenContributors(): array
{
    $skill = Skill::factory()->create(['name' => 'Shared skill', 'slug' => 'shared-skill']);
    $profileBase = fn (string $marker, array $extra = []) => ['headline' => "Headline {$marker}", 'bio' => "Bio {$marker} ".str_repeat('x', 30), 'is_public' => true, 'slug' => "slug-{$marker}", ...$extra];

    $cases = [
        'private toggle off' => contributorIn(OnboardingState::Active, $profileBase('zzprivate', ['is_public' => false]), ['name' => 'Name zzprivate']),
        'never published (no slug)' => contributorIn(OnboardingState::Active, $profileBase('zznoslug', ['slug' => null]), ['name' => 'Name zznoslug']),
        'registered' => contributorIn(OnboardingState::Registered, $profileBase('zzregistered'), ['name' => 'Name zzregistered']),
        'profile complete' => contributorIn(OnboardingState::ProfileComplete, $profileBase('zzcomplete'), ['name' => 'Name zzcomplete']),
        'pending review' => contributorIn(OnboardingState::PendingReview, $profileBase('zzpending'), ['name' => 'Name zzpending']),
        'rejected' => contributorIn(OnboardingState::Rejected, $profileBase('zzrejected'), ['name' => 'Name zzrejected']),
        'suspended' => contributorIn(OnboardingState::Suspended, $profileBase('zzsuspended'), ['name' => 'Name zzsuspended']),
        // An admin can set users.status to active in the Users resource, so the onboarding state must hold on its own.
        'pending review but account marked active' => contributorIn(OnboardingState::PendingReview, $profileBase('zzpendingactive'), ['name' => 'Name zzpendingactive', 'status' => UserStatus::Active]),
        'rejected but account marked active' => contributorIn(OnboardingState::Rejected, $profileBase('zzrejectedactive'), ['name' => 'Name zzrejectedactive', 'status' => UserStatus::Active]),
        'suspended state but account marked active' => contributorIn(OnboardingState::Suspended, $profileBase('zzsuspendedactive'), ['name' => 'Name zzsuspendedactive', 'status' => UserStatus::Active]),
        'active state but account suspended' => contributorIn(OnboardingState::Active, $profileBase('zzacctsusp'), ['name' => 'Name zzacctsusp', 'status' => UserStatus::Suspended]),
        'soft deleted user' => tap(contributorIn(OnboardingState::Active, $profileBase('zzdeleted'), ['name' => 'Name zzdeleted']), fn ($u) => $u->delete()),
    ];

    $client = clientUser();
    $client->profile()->create($profileBase('zzclient', ['onboarding_state' => OnboardingState::Active]));
    $client->update(['name' => 'Name zzclient']);
    $cases['client account with a public-looking profile'] = $client;

    foreach ($cases as $contributor) {
        $contributor->skills()->attach($skill->id, ['level' => 'expert']);
    }

    return $cases;
}

function markerOf(string $label): string
{
    return [
        'private toggle off' => 'zzprivate',
        'never published (no slug)' => 'zznoslug',
        'registered' => 'zzregistered',
        'profile complete' => 'zzcomplete',
        'pending review' => 'zzpending',
        'rejected' => 'zzrejected',
        'suspended' => 'zzsuspended',
        'pending review but account marked active' => 'zzpendingactive',
        'rejected but account marked active' => 'zzrejectedactive',
        'suspended state but account marked active' => 'zzsuspendedactive',
        'active state but account suspended' => 'zzacctsusp',
        'soft deleted user' => 'zzdeleted',
        'client account with a public-looking profile' => 'zzclient',
    ][$label];
}

beforeEach(function () {
    $this->visible = publicContributor('Visible Vandana', ['headline' => 'Headline zzvisible', 'bio' => 'Bio zzvisible '.str_repeat('y', 30)]);
    $this->hidden = hiddenContributors();
});

it('lists only the visible contributor on the directory', function () {
    $response = $this->get('/contributors')->assertOk()->assertSee('Visible Vandana');

    foreach (array_keys($this->hidden) as $label) {
        $response->assertDontSee('Name '.markerOf($label), false)->assertDontSee('Headline '.markerOf($label), false);
    }
});

it('never returns a hidden contributor from search by headline, bio or skill', function (string $query) {
    $body = $this->get('/contributors?'.$query)->assertOk()->getContent();

    foreach (array_keys($this->hidden) as $label) {
        expect($body)->not->toContain(markerOf($label));
    }
})->with([
    'headline word' => ['q=Headline'],
    'bio word' => ['q=Bio'],
    'each hidden marker' => ['q=zz'],
    'skill filter' => ['skill=shared-skill'],
    'search plus skill' => ['q=Headline&skill=shared-skill'],
    'wildcard characters' => ['q=%25'],
]);

it('finds the visible contributor by headline and bio search', function () {
    $this->get('/contributors?q=zzvisible')->assertOk()->assertSee('Visible Vandana');
    $this->get('/contributors?q=zznothing')->assertOk()->assertDontSee('Visible Vandana');
});

it('returns 404 for the profile page of every hidden contributor', function () {
    foreach ($this->hidden as $label => $contributor) {
        $slug = 'slug-'.markerOf($label);

        $this->get('/contributors/'.$slug)->assertNotFound();
        $this->get('/contributors/'.$slug.'/photo')->assertNotFound();
    }
});

it('does not reveal a hidden profile through an unknown slug versus a private one', function () {
    $private = $this->get('/contributors/slug-zzprivate');
    $missing = $this->get('/contributors/slug-does-not-exist');

    expect($private->getStatusCode())->toBe($missing->getStatusCode());
});

it('keeps every hidden contributor out of the sitemap', function () {
    $body = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

    expect($body)->toContain('/contributors/'.$this->visible->profile->slug)->toContain('/contributors</loc>');

    foreach (array_keys($this->hidden) as $label) {
        expect($body)->not->toContain(markerOf($label));
    }
});

it('removes a profile from the directory, sitemap and page the moment it is made private', function () {
    $slug = $this->visible->profile->slug;

    $this->get('/contributors/'.$slug)->assertOk();

    $this->visible->profile->update(['is_public' => false]);

    $this->get('/contributors/'.$slug)->assertNotFound();
    $this->get('/contributors')->assertDontSee('Visible Vandana');
    expect($this->get('/sitemap.xml')->getContent())->not->toContain($slug);
});

it('removes a profile the moment the contributor is suspended', function () {
    $slug = $this->visible->profile->slug;

    $this->visible->profile->update(['onboarding_state' => OnboardingState::Suspended]);
    $this->visible->update(['status' => UserStatus::Suspended]);

    $this->get('/contributors/'.$slug)->assertNotFound();
    expect($this->get('/sitemap.xml')->getContent())->not->toContain($slug);
});

it('serves the visible profile with indexable headers and no private data', function () {
    $this->visible->profile->update(['phone' => '+91 99999 00000', 'linkedin_url' => 'https://www.linkedin.com/in/vandana']);

    $response = $this->get('/contributors/'.$this->visible->profile->slug)
        ->assertOk()
        ->assertSee('Visible Vandana')
        ->assertHeader('X-Robots-Tag', 'index, follow')
        ->assertDontSee('+91 99999 00000')
        ->assertDontSee($this->visible->email)
        ->assertSee('rel="nofollow noopener noreferrer"', false);

    expect($response->getContent())->not->toContain('noindex');
});

it('lets the owner and admins preview a non-public profile with noindex, never guests or other contributors', function () {
    $owner = $this->hidden['private toggle off'];
    $url = '/contributors/slug-zzprivate';

    $this->actingAs($owner)->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('not public');
    $this->actingAs(adminUser())->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->actingAs(contributorIn(OnboardingState::Active))->get($url)->assertNotFound();
    $this->actingAs(clientUser())->get($url)->assertNotFound();
});

it('serves the public photo only for a visible profile, from the private disk', function () {
    Storage::fake('local');
    Storage::disk('local')->put('profile-photos/seen.jpg', 'jpegbytes');
    Storage::disk('local')->put('profile-photos/hidden.jpg', 'jpegbytes');

    $this->visible->profile->update(['photo_path' => 'profile-photos/seen.jpg']);
    $this->hidden['private toggle off']->profile->update(['photo_path' => 'profile-photos/hidden.jpg']);

    $this->get('/contributors/'.$this->visible->profile->slug.'/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/contributors/slug-zzprivate/photo')->assertNotFound();
});

it('turns the directory, profiles and sitemap entries off with the public_directories flag', function () {
    app(FeatureFlags::class)->set(FeatureFlag::PublicDirectories, false);

    $this->get('/contributors')->assertNotFound();
    $this->get('/contributors/'.$this->visible->profile->slug)->assertNotFound();
    $this->get('/contributors/'.$this->visible->profile->slug.'/photo')->assertNotFound();

    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->not->toContain('/contributors');
});

it('keeps private areas out of robots.txt crawling and points to the sitemap', function () {
    $this->get('/robots.txt')->assertOk()
        ->assertSee('Disallow: /admin', false)
        ->assertSee('Disallow: /app/', false)
        ->assertSee('Sitemap: '.route('sitemap'), false);
});

it('paginates the directory and escapes content', function () {
    publicContributor('Escape Eve', ['headline' => '<script>alert(1)</script>']);

    $this->get('/contributors')->assertOk()->assertDontSee('<script>alert(1)</script>', false);

    foreach (range(1, 14) as $i) {
        publicContributor("Person {$i}");
    }

    $this->get('/contributors')->assertOk()->assertSee('page=2', false);
});

it('issues the same number of queries for 3 profiles as for 12 on the directory', function () {
    $count = function (): int {
        $this->get('/contributors')->assertOk(); // warm
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/contributors')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // The 3 visible profiles are in the DB from beforeEach (1 visible) plus 2 more.
    publicContributor('Two');
    publicContributor('Three');
    $small = $count();

    foreach (range(1, 9) as $i) {
        publicContributor("More {$i}");
    }
    $large = $count();

    expect($large)->toBe($small);
});

it('keeps a profile row count sanity check so the fixtures stay meaningful', function () {
    expect(Profile::query()->publiclyVisible()->count())->toBe(1)
        ->and(Profile::query()->count())->toBe(14);
});
