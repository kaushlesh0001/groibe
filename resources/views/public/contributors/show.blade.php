<x-layouts.public :title="$profile->user->name">
    @push('meta')
        @if ($public)
            <meta name="description" content="{{ \Illuminate\Support\Str::limit($profile->headline.'. '.$profile->bio, 155) }}">
            <link rel="canonical" href="{{ route('contributors.show', $profile->slug) }}">
            <meta property="og:title" content="{{ $profile->user->name }} on Groibe">
            <meta property="og:description" content="{{ \Illuminate\Support\Str::limit((string) $profile->headline, 155) }}">
            <meta property="og:type" content="profile">
        @else
            <meta name="robots" content="noindex, nofollow">
        @endif
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        @unless ($public)
            <p role="status" class="rounded-lg border border-amber-400 bg-amber-50 p-3">This profile is not public. Only you and Groibe staff can see this page.</p>
        @endunless

        <header class="flex items-center gap-5">
            @php($photoUrl = $public ? route('contributors.photo', $profile->slug) : (auth()->id() === $profile->user_id ? route('contributor.profile.photo') : null))
            @if ($profile->photo_path && $photoUrl)
                <img src="{{ $photoUrl }}" alt="{{ $profile->user->name }}" class="size-24 rounded-full object-cover" width="96" height="96">
            @endif
            <div class="flex flex-col gap-1">
                <h1 class="text-3xl font-semibold">{{ $profile->user->name }}</h1>
                <p class="text-lg text-zinc-700">{{ $profile->headline }}</p>
                <p class="text-sm text-zinc-600">{{ collect([$profile->city, $profile->state, $profile->country])->filter()->implode(', ') }}</p>
            </div>
        </header>

        @if ($profile->bio)
            <section aria-labelledby="about-heading" class="flex flex-col gap-2">
                <h2 id="about-heading" class="text-xl font-semibold">About</h2>
                <p class="whitespace-pre-line">{{ $profile->bio }}</p>
            </section>
        @endif

        <section aria-labelledby="skills-heading" class="flex flex-col gap-2">
            <h2 id="skills-heading" class="text-xl font-semibold">Skills</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($profile->user->skills as $skill)
                    <li class="rounded border border-zinc-200 px-3 py-1 text-sm">
                        {{ $skill->name }}
                        <span class="text-zinc-600">({{ $skill->pivot->level->getLabel() }})</span>
                        @if ($skill->pivot->is_verified)
                            <span class="font-medium text-green-700">Verified</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <dl class="grid gap-3 sm:grid-cols-2">
            @if ($profile->availability)
                <div><dt class="text-sm text-zinc-600">Availability</dt><dd>{{ $profile->availability->getLabel() }}</dd></div>
            @endif
            @if ($profile->experience_years !== null)
                <div><dt class="text-sm text-zinc-600">Experience</dt><dd>{{ $profile->experience_years }} years</dd></div>
            @endif
            @if ($profile->languages)
                <div><dt class="text-sm text-zinc-600">Languages</dt><dd>{{ implode(', ', $profile->languages) }}</dd></div>
            @endif
        </dl>

        @if ($profile->linkedin_url || $profile->portfolio_url)
            <p class="flex gap-4 text-brand-blue">
                @if ($profile->linkedin_url)
                    <a class="underline" href="{{ $profile->linkedin_url }}" rel="nofollow noopener noreferrer" target="_blank">LinkedIn</a>
                @endif
                @if ($profile->portfolio_url)
                    <a class="underline" href="{{ $profile->portfolio_url }}" rel="nofollow noopener noreferrer" target="_blank">Portfolio</a>
                @endif
            </p>
        @endif
    </article>
</x-layouts.public>
