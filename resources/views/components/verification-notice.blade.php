@props(['organization' => null])

@if ($organization?->isAwaitingVerification())
    <section role="status" class="rounded-lg border border-amber-400 bg-amber-50 p-4">
        <flux:heading size="lg" level="2">Waiting for verification</flux:heading>
        <p class="mt-1">Groibe is checking the details of {{ $organization->name }}. You can prepare a draft brief now, but you cannot submit it until your organization is verified. This message goes away once it is. Contact Groibe if it takes longer than you expect.</p>
    </section>
@endif
