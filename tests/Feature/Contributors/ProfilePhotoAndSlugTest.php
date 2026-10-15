<?php

use App\Actions\Contributors\SetProfileVisibility;
use App\Actions\Contributors\UploadProfilePhoto;
use App\Enums\OnboardingState;
use App\Livewire\Contributor\EditProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->contributor = contributorIn(OnboardingState::Active, [], ['name' => 'Priya Nair']);
});

function bigImage(int $width = 1600, int $height = 1200): UploadedFile
{
    return UploadedFile::fake()->image('me.png', $width, $height);
}

it('stores a valid photo re-encoded as a small JPEG on the private disk under a random name', function () {
    app(UploadProfilePhoto::class)($this->contributor->profile, bigImage(), $this->contributor);

    $path = $this->contributor->profile->refresh()->photo_path;

    expect($path)->toStartWith('profile-photos/')->toEndWith('.jpg')->not->toContain('me.png');

    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect(max($width, $height))->toBeLessThanOrEqual(800);
    expect(getimagesizefromstring(Storage::disk('local')->get($path))['mime'])->toBe('image/jpeg');
});

it('rejects photos that are too big, not images or the wrong type', function (UploadedFile $file) {
    expect(fn () => app(UploadProfilePhoto::class)($this->contributor->profile, $file, $this->contributor))
        ->toThrow(ValidationException::class);

    expect($this->contributor->profile->refresh()->photo_path)->toBeNull();
})->with([
    'over 2 MB' => fn () => UploadedFile::fake()->image('big.jpg')->size(2049),
    'a php file' => fn () => UploadedFile::fake()->create('shell.php', 10, 'application/x-httpd-php'),
    'a php file named as an image' => fn () => UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1;'),
    'a pdf' => fn () => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
    'an svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'a gif' => fn () => UploadedFile::fake()->image('anim.gif'),
]);

it('replaces the old photo file when a new one is uploaded and removes it on request', function () {
    $action = app(UploadProfilePhoto::class);

    $action($this->contributor->profile, bigImage(), $this->contributor);
    $first = $this->contributor->profile->refresh()->photo_path;

    $action($this->contributor->profile, bigImage(400, 400), $this->contributor);
    $second = $this->contributor->profile->refresh()->photo_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);

    $action->remove($this->contributor->profile, $this->contributor);

    Storage::disk('local')->assertMissing($second);
    expect($this->contributor->profile->refresh()->photo_path)->toBeNull();
});

it('does not let one contributor change another contributor photo', function () {
    $other = contributorIn(OnboardingState::Active);

    expect(fn () => app(UploadProfilePhoto::class)($this->contributor->profile, bigImage(), $other))
        ->toThrow(AuthorizationException::class);
});

it('rejects an oversized upload through the profile page too', function () {
    Livewire::actingAs($this->contributor)->test(EditProfile::class)
        ->set('photo', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
        ->call('uploadPhoto')
        ->assertHasErrors('photo');
});

it('serves the owner photo only to the owner', function () {
    app(UploadProfilePhoto::class)($this->contributor->profile, bigImage(), $this->contributor);

    $this->actingAs($this->contributor)->get('/app/contributor/profile/photo')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs(contributorIn(OnboardingState::Active))->get('/app/contributor/profile/photo')->assertNotFound();
});

it('creates the slug on first publish and never changes it afterwards', function () {
    $action = app(SetProfileVisibility::class);

    expect($this->contributor->profile->slug)->toBeNull();

    $action($this->contributor->profile, true, $this->contributor);
    $slug = $this->contributor->profile->refresh()->slug;

    expect($slug)->toStartWith('priya-nair-')->and($this->contributor->profile->is_public)->toBeTrue();

    $action($this->contributor->profile, false, $this->contributor);
    $action($this->contributor->profile, true, $this->contributor);

    expect($this->contributor->profile->refresh()->slug)->toBe($slug);
});

it('generates distinct slugs for contributors with the same name', function () {
    $other = contributorIn(OnboardingState::Active, [], ['name' => 'Priya Nair']);

    app(SetProfileVisibility::class)($this->contributor->profile, true, $this->contributor);
    app(SetProfileVisibility::class)($other->profile, true, $other);

    expect($this->contributor->profile->refresh()->slug)->not->toBe($other->profile->refresh()->slug);
});

/** A valid PNG header claiming the given size and nothing else, so no memory is needed to build or to refuse it. */
function pngClaiming(int $width, int $height): UploadedFile
{
    $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

    return UploadedFile::fake()->createWithContent('huge.png', "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header)));
}

it('refuses a small file that claims far too many pixels, before decoding it', function () {
    foreach ([[15000, 15000], [100000, 1000], [5001, 5000]] as [$width, $height]) {
        expect(fn () => app(UploadProfilePhoto::class)($this->contributor->profile, pngClaiming($width, $height), $this->contributor))
            ->toThrow(ValidationException::class, 'too large in pixels');
    }

    expect($this->contributor->profile->refresh()->photo_path)->toBeNull();
});

it('still accepts a large but reasonable photo', function () {
    app(UploadProfilePhoto::class)($this->contributor->profile, bigImage(2000, 1500), $this->contributor);

    expect($this->contributor->profile->refresh()->photo_path)->not->toBeNull();
});
