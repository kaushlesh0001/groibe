<?php

use App\Models\Sow;
use App\Services\Sows\SowPdf;

it('ships the official Inter Regular and Bold files with their licence', function () {
    foreach (['Inter-Regular.ttf', 'Inter-Bold.ttf'] as $file) {
        $path = resource_path("fonts/pdf/{$file}");

        expect(file_exists($path))->toBeTrue()
            ->and(bin2hex((string) file_get_contents($path, false, null, 0, 4)))->toBe('00010000')   // a TrueType file
            ->and(filesize($path))->toBeGreaterThan(300_000);
    }

    expect(file_get_contents(resource_path('fonts/pdf/Inter-LICENSE.txt')))->toContain('SIL Open Font License');
});

it('sets the SOW PDF in Inter, regular and bold, embedded', function () {
    // A heading and bold text, so the Bold file is used as well as the Regular one.
    $sow = Sow::factory()->create(['body_html' => '<h2>Scope</h2><p>The work, <strong>in full</strong>. Fee: ₹1,50,000.</p>']);

    $pdf = app(SowPdf::class)->render($sow);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(str_contains($pdf, 'Inter-Regular'))->toBeTrue('the Regular file is not embedded')
        ->and(str_contains($pdf, 'Inter-Bold'))->toBeTrue('the Bold file is not embedded')
        // Subsetting keeps the two fonts small: the whole SOW is far below the 800 KB the two full files would add.
        ->and(strlen($pdf) < 400_000)->toBeTrue('the PDF is not subsetted: '.strlen($pdf).' bytes');
});
