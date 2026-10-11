<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Notifications" subheading="Choose how Groibe tells you about each kind of update">
        <form wire:submit="save" class="flex flex-col gap-5">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Notification channels by category</caption>
                <thead>
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-semibold">Category</th>
                        <th scope="col" class="px-3 py-2 font-semibold">In the app</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Email</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($choices as $category => $choice)
                        @php($label = $labels[$category] ?? ['name' => ucfirst($category), 'hint' => ''])
                        <tr wire:key="pref-{{ $category }}" class="border-t border-zinc-200 align-top">
                            <th scope="row" class="py-3 pr-3 font-medium">
                                {{ $label['name'] }}
                                <span class="block text-xs font-normal text-zinc-600">{{ $label['hint'] }}</span>
                                @if (isset($locked[$category])) <span class="block text-xs font-normal text-zinc-600">Always on. This cannot be turned off.</span> @endif
                            </th>
                            <td class="px-3 py-3">
                                <input type="checkbox" class="size-4" aria-label="{{ $label['name'] }} in the app"
                                    wire:model="choices.{{ $category }}.database" @disabled(isset($locked[$category])) />
                            </td>
                            <td class="px-3 py-3">
                                <input type="checkbox" class="size-4" aria-label="{{ $label['name'] }} by email"
                                    wire:model="choices.{{ $category }}.mail" @disabled(isset($locked[$category])) />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save preferences</flux:button>
                @if ($saved) <span role="status" class="text-sm">Saved.</span> @endif
            </div>
        </form>
    </x-settings.layout>
</div>
