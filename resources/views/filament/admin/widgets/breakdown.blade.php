<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        <dl style="margin:0">
            @foreach ($rows as $label => $count)
                <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                    <dt>{{ $label }}</dt>
                    <dd style="margin:0;font-weight:600">{{ number_format($count) }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($url)
            <p style="margin:0.75rem 0 0"><a href="{{ $url }}" class="fi-link">Open the list</a></p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
