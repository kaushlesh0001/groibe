<?php

use App\Actions\Contributors\UploadProfilePhoto;
use App\Actions\Files\AttachPrivateFile;
use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Profile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * A file with the given name and exact content, as a browser would send it. It is a real uploaded file, not Laravel's fake:
 * the fake takes its type from the name, but the rules under test read the type from the content, as they do on a real upload.
 */
function upload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function samplePdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

$pdf = samplePdf();
$php = '<?php system($_GET["c"]); ?>';

describe('documents (briefs, projects, submissions, messages)', function () use ($pdf, $php) {
    it('refuses executable, script and markup files by name', function (string $name, string $content) {
        expect(fn () => app(AttachPrivateFile::class)->validate(upload($name, $content), 20480))->toThrow(ValidationException::class);
    })->with([
        'php' => ['shell.php', $php],
        'phtml' => ['shell.phtml', $php],
        'php5' => ['shell.php5', $php],
        'phar' => ['tool.phar', $php],
        'exe' => ['run.exe', "MZ\x90\x00\x03\x00\x00\x00"],
        'dll' => ['lib.dll', "MZ\x90\x00"],
        'shell script' => ['run.sh', "#!/bin/sh\nrm -rf /\n"],
        'batch file' => ['run.bat', "@echo off\r\n"],
        'html' => ['page.html', '<html><script>alert(1)</script></html>'],
        'htm' => ['page.htm', '<html></html>'],
        'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'javascript' => ['app.js', 'alert(1)'],
        'jar' => ['app.jar', "PK\x03\x04"],
        'htaccess' => ['.htaccess', 'AddType application/x-httpd-php .png'],
        'no extension' => ['README', 'plain text'],
        'macro workbook' => ['book.xlsm', "PK\x03\x04"],
        'macro document' => ['doc.docm', "PK\x03\x04"],
    ]);

    it('refuses a file whose content does not match its name', function (string $name, string $content) {
        expect(fn () => app(AttachPrivateFile::class)->validate(upload($name, $content), 20480))->toThrow(ValidationException::class);
    })->with([
        'php renamed to pdf' => ['invoice.pdf', $php],
        'php renamed to png' => ['photo.png', $php],
        'php renamed to jpg' => ['photo.jpg', $php],
        'html renamed to pdf' => ['brief.pdf', '<html><body onload="alert(1)"></body></html>'],
        'double extension, php last' => ['report.pdf.php', $php],
        'double extension, php first' => ['shell.php.pdf', $php],
        'exe renamed to docx' => ['proposal.docx', "MZ\x90\x00\x03\x00\x00\x00\x04\x00"],
    ]);

    it('accepts the allowlisted types with matching content', function (string $name, string $content) {
        app(AttachPrivateFile::class)->validate(upload($name, $content), 20480);

        expect(true)->toBeTrue();
    })->with([
        'pdf' => ['brief.pdf', $pdf],
        'text' => ['notes.txt', "Some plain notes.\n"],
        'csv' => ['data.csv', "a,b\n1,2\n"],
    ]);

    it('accepts a real image', function () {
        app(AttachPrivateFile::class)->validate(UploadedFile::fake()->image('chart.png', 60, 40), 20480);

        expect(true)->toBeTrue();
    });

    it('refuses a file above the cap and says so', function () {
        try {
            app(AttachPrivateFile::class)->validate(UploadedFile::fake()->create('big.pdf', 20481, 'application/pdf'), 20480);
            $this->fail('A file above the cap was accepted.');
        } catch (ValidationException $e) {
            expect(implode(' ', $e->errors()['file']))->toContain('greater than');
        }
    });

    it('keeps the caps in line with spec 17.2', function () {
        expect(config('groibe.uploads.document_max_kb'))->toBe(20 * 1024)
            ->and(config('groibe.uploads.submission_max_kb'))->toBe(25 * 1024)
            ->and(config('groibe.uploads.photo_max_kb'))->toBe(2 * 1024)
            ->and(config('livewire.temporary_file_upload.rules'))->toContain('max:25600');
    });

    it('lists only types that cannot run on their own', function () {
        $executable = ['php', 'phtml', 'phar', 'exe', 'dll', 'sh', 'bat', 'cmd', 'js', 'jar', 'html', 'htm', 'svg', 'xlsm', 'docm', 'pptm', 'msi', 'com', 'vbs', 'ps1', 'py', 'rb', 'pl', 'asp', 'aspx', 'jsp', 'cgi', 'htaccess'];

        expect(array_intersect($executable, config('groibe.uploads.document_extensions')))->toBe([]);
    });
});

describe('storage and download', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->organization = Organization::factory()->verified()->create();
        $this->owner = clientUser($this->organization);
        $this->brief = Brief::factory()->status(BriefStatus::Draft)->create(['organization_id' => $this->organization->id, 'submitted_by' => $this->owner->id]);
    });

    it('stores a file under a random name on the private disk, never under the name it came with', function () {
        $media = app(AttachPrivateFile::class)($this->brief, upload('Quarterly <report> ../../x.pdf', samplePdf()), $this->owner);

        expect($media->disk)->toBe('local')
            ->and($media->file_name)->toMatch('~^[0-9a-z]{26}\.pdf$~')
            ->and($media->getPathRelativeToRoot())->not->toContain('Quarterly')->not->toContain('..')
            ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeTrue()
            ->and(Storage::disk('public')->exists($media->getPathRelativeToRoot()))->toBeFalse();
    });

    it('serves a file only through a signed, authorized route, as an attachment that the browser may not reinterpret', function () {
        $media = app(AttachPrivateFile::class)($this->brief, upload('data.csv', "name,formula\nAsha,=HYPERLINK(\"http://evil.example\")\n"), $this->owner);
        $signed = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $media->uuid]);

        // No signature, no file.
        $this->actingAs($this->owner)->get(route('files.show', ['media' => $media->uuid]))->assertForbidden();

        $response = $this->actingAs($this->owner)->get($signed)->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Content-Type'))->not->toContain('text/html');
    });

    it('does not let another organization or a guest open it, even with a valid signature', function () {
        $media = app(AttachPrivateFile::class)($this->brief, upload('notes.txt', "Private.\n"), $this->owner);
        $signed = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $media->uuid]);

        $outsider = clientUser();
        $this->actingAs($outsider)->get($signed)->assertForbidden();
        asUser(contributorUser())->get($signed)->assertForbidden();

        test()->flushSession();
        auth()->forgetGuards();
        $this->get($signed)->assertRedirect();
    });

    it('does not serve the storage folder directly', function () {
        $media = app(AttachPrivateFile::class)($this->brief, upload('notes.txt', "Private.\n"), $this->owner);

        $this->get('/storage/'.$media->getPathRelativeToRoot())->assertNotFound();
        $this->get('/storage/'.$media->file_name)->assertNotFound();
    });
});

describe('profile photos', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->user = contributorUser();
        $this->profile = Profile::factory()->create(['user_id' => $this->user->id]);
    });

    it('refuses svg, php and mismatched files', function (string $name, string $content) {
        expect(fn () => app(UploadProfilePhoto::class)($this->profile, upload($name, $content), $this->user))->toThrow(ValidationException::class);
    })->with([
        'svg' => ['me.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'php' => ['me.php', '<?php echo 1;'],
        'php renamed to jpg' => ['me.jpg', '<?php echo 1;'],
        'pdf' => ['me.pdf', '%PDF-1.4'],
        'gif' => ['me.gif', 'GIF89a'],
    ]);

    it('refuses a photo above 2 MB', function () {
        expect(fn () => app(UploadProfilePhoto::class)($this->profile, UploadedFile::fake()->image('me.jpg', 600, 600)->size(2049), $this->user))
            ->toThrow(ValidationException::class);
    });

    it('re-encodes the image, so anything hidden in it is dropped', function () {
        $image = UploadedFile::fake()->image('me.jpg', 200, 200);
        file_put_contents($image->getRealPath(), '<?php system($_GET["c"]); ?>', FILE_APPEND);

        $profile = app(UploadProfilePhoto::class)($this->profile, $image, $this->user);
        $stored = Storage::disk('local')->get($profile->photo_path);

        expect($stored)->not->toContain('<?php')->not->toContain('system(')
            ->and(substr($stored, 0, 3))->toBe("\xFF\xD8\xFF")
            ->and($profile->photo_path)->toMatch('~^profile-photos/[0-9a-z]{26}\.jpg$~');
    });

    it('limits the size in pixels', function () {
        $profile = app(UploadProfilePhoto::class)($this->profile, UploadedFile::fake()->image('big.png', 2400, 1800), $this->user);

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($profile->photo_path));

        expect(max($width, $height))->toBeLessThanOrEqual((int) config('groibe.uploads.photo_max_px'));
    });

    it('lets only the owner change a photo', function () {
        $other = contributorUser();

        expect(fn () => app(UploadProfilePhoto::class)($this->profile, UploadedFile::fake()->image('me.jpg', 100, 100), $other))
            ->toThrow(AuthorizationException::class);
    });
});
