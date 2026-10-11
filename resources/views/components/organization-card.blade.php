@props(['organization'])

<li class="rounded-lg border border-zinc-200 p-5 hover:border-brand-blue">
    <a href="{{ route('organizations.show', $organization->slug) }}" class="flex flex-col gap-1">
        <span class="text-lg font-semibold">{{ $organization->name }}</span>
        @if ($organization->industry)
            <span class="text-sm text-zinc-700">{{ $organization->industry }}</span>
        @endif
        @if ($organization->city || $organization->state)
            <span class="text-sm text-zinc-600">{{ collect([$organization->city, $organization->state])->filter()->implode(', ') }}</span>
        @endif
    </a>
</li>
