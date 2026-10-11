@props(['item'])

@php
    $by = $item->user?->profile?->isPubliclyVisible() ? $item->user->name : null;
@endphp

<li class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-5">
    <h2 class="text-lg font-semibold">
        <a href="{{ route('work.show', $item->slug) }}" class="rounded-md hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">{{ $item->title }}</a>
    </h2>
    <p class="text-sm text-zinc-600">{{ $item->clientLabel() }}</p>
    @if ($item->summary)
        <p>{{ \Illuminate\Support\Str::limit($item->summary, 140) }}</p>
    @endif
    <p class="text-sm text-zinc-600">{{ $item->role_title }}{{ $by ? ' · '.$by : '' }}</p>
</li>
