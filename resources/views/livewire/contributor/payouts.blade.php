<div class="mx-auto flex max-w-5xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Payouts</flux:heading>
        <flux:text>Payments Groibe has approved for your work. Groibe pays outside this platform, so use the transaction reference to match the credit in your bank.</flux:text>
    </div>

    @if ($payouts->isEmpty())
        <flux:text>No payouts yet. They appear here once Groibe approves them.</flux:text>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <caption class="sr-only">Your payouts</caption>
                <thead class="bg-zinc-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Project</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Milestone</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">TDS</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Net</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Paid on</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Reference</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payouts as $payout)
                        <tr wire:key="payout-{{ $payout->id }}" class="border-t border-zinc-200">
                            <td class="px-4 py-3">{{ $payout->project->title }} <span class="block text-xs text-zinc-600">{{ $payout->project->code }}</span></td>
                            <td class="px-4 py-3">{{ $payout->milestone?->title ?? '-' }}</td>
                            <td class="px-4 py-3 text-right">{{ $payout->currency }} {{ number_format((float) $payout->amount, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ $payout->tds_amount === null ? '-' : number_format((float) $payout->tds_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format((float) $payout->net(), 2) }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$payout->status" /></td>
                            <td class="px-4 py-3">{{ $payout->paid_at?->format('j M Y') ?? '-' }}</td>
                            <td class="px-4 py-3">{{ $payout->reference ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{ $payouts->links() }}
</div>
