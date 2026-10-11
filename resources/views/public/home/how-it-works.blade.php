{{-- Two views of the same five steps, switched with radio inputs so it works without scripts. The inputs must stay before the labels and panels, because the styles follow the checked input. --}}
<section aria-labelledby="how-heading" class="flex flex-col gap-8">
    <div class="flex flex-col gap-3">
        <h2 id="how-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
        <p class="max-w-2xl text-zinc-700">{{ $c['subheading'] }}</p>
    </div>

    <div class="flex flex-wrap gap-3">
        <input id="how-contributors" name="how-view" type="radio" class="peer/contributors sr-only" checked>
        <input id="how-clients" name="how-view" type="radio" class="peer/clients sr-only">
        <label for="how-contributors" class="cursor-pointer rounded-lg border border-zinc-300 px-4 py-2 font-medium peer-checked/contributors:border-brand-navy peer-checked/contributors:bg-brand-navy peer-checked/contributors:text-white peer-focus-visible/contributors:outline-2 peer-focus-visible/contributors:outline-offset-2 peer-focus-visible/contributors:outline-brand-navy">For contributors</label>
        <label for="how-clients" class="cursor-pointer rounded-lg border border-zinc-300 px-4 py-2 font-medium peer-checked/clients:border-brand-navy peer-checked/clients:bg-brand-navy peer-checked/clients:text-white peer-focus-visible/clients:outline-2 peer-focus-visible/clients:outline-offset-2 peer-focus-visible/clients:outline-brand-navy">For clients</label>

        <ol class="hidden w-full gap-4 peer-checked/contributors:grid sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($c['contributor_steps'] as $step)
                <li class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-4">
                    <span class="text-sm font-semibold text-zinc-600">Step {{ $loop->iteration }}</span>
                    <span class="text-lg font-semibold">{{ $step['title'] }}</span>
                    <span class="text-zinc-700">{{ $step['text'] ?? '' }}</span>
                </li>
            @endforeach
        </ol>
        <ol class="hidden w-full gap-4 peer-checked/clients:grid sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($c['client_steps'] as $step)
                <li class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-4">
                    <span class="text-sm font-semibold text-zinc-600">Step {{ $loop->iteration }}</span>
                    <span class="text-lg font-semibold">{{ $step['title'] }}</span>
                    <span class="text-zinc-700">{{ $step['text'] ?? '' }}</span>
                </li>
            @endforeach
        </ol>
    </div>
</section>
