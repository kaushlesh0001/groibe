<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        <p style="margin:0 0 0.5rem;font-weight:600">{{ number_format($total) }} waiting</p>

        @forelse ($items as $item)
            <div style="padding:0.25rem 0">
                <div>{{ $item['title'] }}</div>
                <div style="font-size:0.875rem;opacity:0.75">{{ $item['meta'] }}</div>
            </div>
        @empty
            <p style="margin:0;opacity:0.75">{{ $empty }}</p>
        @endforelse

        @if ($url)
            <p style="margin:0.75rem 0 0"><a href="{{ $url }}" class="fi-link">Open the list</a></p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
