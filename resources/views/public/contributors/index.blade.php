<x-layouts.public title="Contributors">
    @push('meta')
        <meta name="description" content="Meet the skilled professionals who deliver work through Groibe.">
        <link rel="canonical" href="{{ route('contributors.index') }}">
    @endpush

    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Contributors</h1>
            <p class="text-zinc-600">Skilled professionals who have joined the Groibe community and chosen to be listed.</p>
        </div>

        <form method="GET" action="{{ route('contributors.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end" role="search">
            <div class="flex flex-1 flex-col gap-1">
                <label for="q" class="text-sm font-medium">Search headline and bio</label>
                <input id="q" name="q" type="search" value="{{ $term }}" maxlength="100" class="rounded-md border border-zinc-300 px-3 py-2">
            </div>
            <div class="flex flex-col gap-1">
                <label for="skill" class="text-sm font-medium">Skill</label>
                <select id="skill" name="skill" class="rounded-md border border-zinc-300 px-3 py-2">
                    <option value="">All skills</option>
                    @foreach ($skills as $option)
                        <option value="{{ $option->slug }}" @selected($skill === $option->slug)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-md bg-brand-green px-4 py-2 font-medium text-navy hover:opacity-90">Search</button>
        </form>

        @if ($profiles->isEmpty())
            <p class="text-zinc-600">No contributors match that search.</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($profiles as $profile)
                    <li class="rounded-lg border border-zinc-200 p-5 hover:border-brand-blue">
                        <a href="{{ route('contributors.show', $profile->slug) }}" class="flex flex-col gap-2">
                            <span class="flex items-center gap-3">
                                @if ($profile->photo_path)
                                    <img src="{{ route('contributors.photo', $profile->slug) }}" alt="" class="size-12 rounded-full object-cover" width="48" height="48" loading="lazy">
                                @endif
                                <span class="text-lg font-semibold">{{ $profile->user->name }}</span>
                            </span>
                            <span class="text-zinc-700">{{ $profile->headline }}</span>
                            <span class="text-sm text-zinc-600">{{ collect([$profile->city, $profile->state])->filter()->implode(', ') }}</span>
                            <span class="flex flex-wrap gap-1">
                                @foreach ($profile->user->skills->take(4) as $profileSkill)
                                    <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs">{{ $profileSkill->name }}</span>
                                @endforeach
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $profiles->links() }}
        @endif
    </div>
</x-layouts.public>
