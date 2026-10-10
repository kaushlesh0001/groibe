<x-layouts.app title="Client dashboard">
    <div class="flex flex-col gap-4">
        <flux:heading size="xl" level="1">Welcome, {{ auth()->user()->name }}</flux:heading>
        <flux:text>Your briefs and projects will appear here.</flux:text>
    </div>
</x-layouts.app>
