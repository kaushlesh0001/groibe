<div class="text-sm">
    <ul class="list-disc ps-5">
        @forelse ($files as $file)
            <li><a href="{{ $file['url'] }}" class="text-primary-600 underline" target="_blank" rel="noopener">{{ $file['name'] }}</a> ({{ $file['size'] }})</li>
        @empty
            <li class="list-none ps-0">No files attached.</li>
        @endforelse
    </ul>
</div>
