{{-- Tabs by audience, switched with radio inputs so they work without scripts. The class names are written out in full so Tailwind can see them. --}}
@php
    $tabs = [
        'general' => ['label' => 'General', 'label_classes' => 'peer-checked/general:border-brand-navy peer-checked/general:bg-brand-navy peer-checked/general:text-white peer-focus-visible/general:outline-2 peer-focus-visible/general:outline-offset-2 peer-focus-visible/general:outline-brand-navy', 'peer' => 'peer/general', 'panel' => 'peer-checked/general:block'],
        'contributor' => ['label' => 'Contributors', 'label_classes' => 'peer-checked/contributor:border-brand-navy peer-checked/contributor:bg-brand-navy peer-checked/contributor:text-white peer-focus-visible/contributor:outline-2 peer-focus-visible/contributor:outline-offset-2 peer-focus-visible/contributor:outline-brand-navy', 'peer' => 'peer/contributor', 'panel' => 'peer-checked/contributor:block'],
        'client' => ['label' => 'Clients', 'label_classes' => 'peer-checked/client:border-brand-navy peer-checked/client:bg-brand-navy peer-checked/client:text-white peer-focus-visible/client:outline-2 peer-focus-visible/client:outline-offset-2 peer-focus-visible/client:outline-brand-navy', 'peer' => 'peer/client', 'panel' => 'peer-checked/client:block'],
    ];
    $available = collect($tabs)->only(array_keys($d['groups']));
    $first = $available->keys()->first();
@endphp

<section aria-labelledby="faq-heading" class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h2 id="faq-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
        @if (! empty($c['intro']))
            <p class="max-w-2xl text-zinc-700">{{ $c['intro'] }}</p>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        @foreach ($available as $audience => $tab)
            <input id="faq-{{ $audience }}" name="faq-audience" type="radio" class="{{ $tab['peer'] }} sr-only" @checked($audience === $first)>
        @endforeach
        @foreach ($available as $audience => $tab)
            <label for="faq-{{ $audience }}" class="cursor-pointer rounded-lg border border-zinc-300 px-4 py-2 font-medium {{ $tab['label_classes'] }}">{{ $tab['label'] }}</label>
        @endforeach
        @foreach ($available as $audience => $tab)
            <div class="hidden w-full flex-col gap-3 {{ $tab['panel'] }}">
                @foreach ($d['groups'][$audience] as $faq)
                    <details class="rounded-xl border border-zinc-200 p-4">
                        <summary class="cursor-pointer font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">{{ $faq->question }}</summary>
                        <p class="mt-2 whitespace-pre-line text-zinc-700">{{ $faq->answer }}</p>
                    </details>
                @endforeach
            </div>
        @endforeach
    </div>
</section>
