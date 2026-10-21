<section aria-labelledby="community-heading" class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h2 id="community-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
        @if (! empty($c['intro']))
            <p class="max-w-2xl text-zinc-700">{{ $c['intro'] }}</p>
        @endif
    </div>

    {{-- A slow row of contributor cards. With motion allowed it loops left, and the second copy is hidden from everyone
         (inert) so each person is reachable once. With reduced motion it is a plain row that scrolls by hand. It pauses on
         hover and keyboard focus, and the checkbox pauses it for good. --}}
    <div class="marquee-scope flex flex-col gap-3">
        <label for="community-pause" class="motion-control inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg border border-brand-navy px-3 py-1.5 text-sm text-brand-navy has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brand-navy">
            <input id="community-pause" type="checkbox" class="motion-toggle size-4 accent-brand-navy"> Pause motion
        </label>
        <div class="marquee" data-cards="{{ count($d['profiles']) }}" role="region" aria-label="Contributors">
            <div class="marquee-track">
                @foreach ([false, true] as $duplicate)
                    <ul class="marquee-list {{ $duplicate ? 'marquee-dup' : '' }}" @if ($duplicate) aria-hidden="true" inert @endif>
                        @foreach ($d['profiles'] as $card)
                            <li class="w-64 shrink-0 rounded-lg border border-zinc-200 hover:border-brand-blue">
                                <a href="{{ route('contributors.show', $card['slug']) }}" class="flex h-full flex-col gap-2 rounded-lg p-5 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-navy">
                                    <span class="flex items-center gap-3">
                                        @if ($card['photo'])
                                            <img src="{{ route('contributors.photo', $card['slug']) }}" alt="" class="size-12 shrink-0 rounded-full object-cover" width="48" height="48" loading="lazy">
                                        @endif
                                        <span class="text-lg font-semibold">{{ $card['name'] }}</span>
                                    </span>
                                    @if ($card['demo'])
                                        <span class="w-fit rounded border border-zinc-600 px-2 py-0.5 text-xs font-semibold text-zinc-800">Demo profile</span>
                                    @endif
                                    <span class="line-clamp-2 text-zinc-700">{{ $card['headline'] }}</span>
                                    <span class="flex flex-wrap gap-1">
                                        @foreach ($card['skills'] as $skill)
                                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs text-zinc-800">{{ $skill }}</span>
                                        @endforeach
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </div>
    </div>

    <a href="{{ route('contributors.index') }}" class="w-fit rounded-md font-medium text-brand-blue underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-navy">{{ $c['cta_label'] }}</a>
</section>
