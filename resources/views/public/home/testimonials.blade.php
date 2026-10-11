<section aria-labelledby="testimonials-heading" class="flex flex-col gap-6">
    <h2 id="testimonials-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($d['testimonials'] as $testimonial)
            <li class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-6">
                <blockquote class="text-zinc-800">{{ $testimonial->quote }}</blockquote>
                <p class="text-sm text-zinc-700">
                    <span class="font-semibold">{{ $testimonial->author_name }}</span>
                    @if ($testimonial->author_title || $testimonial->organization_name)
                        <br>{{ collect([$testimonial->author_title, $testimonial->organization_name])->filter()->implode(', ') }}
                    @endif
                </p>
            </li>
        @endforeach
    </ul>
</section>
