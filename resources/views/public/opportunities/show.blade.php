<x-layouts.public :title="$role->title">
    @push('meta')
        <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) ($role->summary ?: $role->description)), 155) }}">
        <link rel="canonical" href="{{ route('opportunities.show', $role->slug) }}">
        <meta property="og:title" content="{{ $role->title }} | Groibe">
        <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $role->summary), 155) }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('opportunities.show', $role->slug) }}">
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Opportunities', 'item' => route('opportunities.index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $role->title, 'item' => route('opportunities.show', $role->slug)],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        <nav aria-label="Breadcrumb" class="text-sm text-zinc-600">
            <a class="text-brand-blue underline" href="{{ route('opportunities.index') }}">Opportunities</a>
        </nav>

        <header class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">{{ $role->title }}</h1>
            @if ($role->summary)
                <p class="text-lg text-zinc-700">{{ $role->summary }}</p>
            @endif
        </header>

        <x-open-role-body :role="$role" />

        <section aria-labelledby="apply-heading" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
            <h2 id="apply-heading" class="text-xl font-semibold">Apply</h2>

            @if (! $role->isOpenForApplications())
                <p>Applications for this role have closed.</p>
            @elseif (auth()->check() && auth()->user()->isActiveContributor())
                <a href="{{ route('contributor.discover.show', $role->slug) }}" class="w-fit rounded-md bg-brand-green px-4 py-2 font-medium text-brand-navy hover:opacity-90">Apply for this role</a>
            @elseif (auth()->check())
                <p>Applying is open to approved Groibe contributors. Visit your dashboard to see where your profile stands.</p>
                <a href="{{ route('dashboard') }}" class="w-fit text-brand-blue underline">Go to dashboard</a>
            @else
                <p>Log in or join Groibe to apply. A Groibe team member reviews every application.</p>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('login') }}" class="rounded-md bg-brand-green px-4 py-2 font-medium text-brand-navy hover:opacity-90">Log in to apply</a>
                    <a href="{{ route('register.contributor') }}" class="rounded-md border border-brand-navy px-4 py-2 font-medium hover:bg-zinc-50">Join Groibe</a>
                </div>
            @endif
        </section>
    </article>
</x-layouts.public>
