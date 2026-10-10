<x-layouts.app title="Contributor dashboard">
    <div class="flex flex-col gap-4">
        <flux:heading size="xl" level="1">Welcome, {{ auth()->user()->name }}</flux:heading>
        <flux:text>Your contributor workspace will appear here. Complete your profile when onboarding opens.</flux:text>
    </div>
</x-layouts.app>
