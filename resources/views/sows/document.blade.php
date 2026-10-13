<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sow->project->code }} Statement of Work v{{ $sow->version }}</title>
    <style>
        @if ($forPdf ?? false)
            @include('pdf.fonts')
        @endif
        body { font-family: Inter, DejaVu Sans, Helvetica, Arial, sans-serif; color: #042B65; font-size: 12px; line-height: 1.5; margin: 0; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        h2 { font-size: 15px; margin: 18px 0 6px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #c9d1e0; padding: 6px 8px; text-align: left; }
        .meta { color: #4a5a7c; margin-bottom: 16px; }
        .acceptance { margin-top: 28px; padding: 10px 12px; border: 1px solid #0EAE44; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid #042B65; font-size: 11px; text-transform: uppercase; }
        .brand { margin-bottom: 18px; }
        .noprint { margin-bottom: 16px; }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="noprint">
            <button type="button" onclick="window.print()">Print</button>
        </div>
    @endunless

    {{-- PDF: a small PNG from disk (dompdf has no remote access, and the full-size file exhausts 128 MB of memory). Print view: the SVG. Ratio kept: width only. --}}
    <div class="brand">
        @if ($forPdf ?? false)
            <img src="{{ public_path('brand/groibe-logo-small.png') }}" alt="Groibe" width="140">
        @else
            <img src="{{ asset('brand/groibe-logo.svg') }}" alt="Groibe" width="140">
        @endif
    </div>

    <div class="meta">
        {{ $sow->project->code }} &middot; Version {{ $sow->version }}
        <span class="status">{{ $sow->status->getLabel() }}</span>
    </div>

    {{-- Body is admin-authored template output; substituted values were escaped by SowRenderer. --}}
    {!! $sow->body_html !!}

    @if ($sow->accepted_at)
        <div class="acceptance">
            Accepted by {{ $sow->accepter?->name ?? 'client representative' }} for {{ $sow->project->organization->name }}
            on {{ $sow->accepted_at->format('j M Y, H:i') }} (version {{ $sow->version }}).
        </div>
    @endif
</body>
</html>
