<x-layouts.public title="How it works">
    @push('meta')
        <meta name="description" content="{{ $c['subheading'] }}">
        <link rel="canonical" href="{{ route('how-it-works') }}">
        <meta property="og:title" content="How it works | Groibe">
        <meta property="og:description" content="{{ $c['subheading'] }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('how-it-works') }}">
    @endpush

    {{-- The section heading becomes the page's one h1 here. --}}
    @include('public.home.how-it-works', ['c' => $c, 'headingTag' => 'h1'])
</x-layouts.public>
