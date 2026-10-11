@props(['profile'])

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
