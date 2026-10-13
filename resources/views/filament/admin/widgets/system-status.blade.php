<x-filament-widgets::widget wire:poll.30s>
    <x-filament::section heading="System status">
        <dl style="margin:0">
            <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                <dt>Cron</dt>
                <dd style="margin:0;font-weight:600">
                    @if ($cronHealthy)
                        Running (last run {{ $lastRun }})
                    @elseif ($lastRun)
                        Stopped (last run {{ $lastRun }})
                    @else
                        No run recorded yet
                    @endif
                </dd>
            </div>
            <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                <dt>Jobs waiting in the queue</dt>
                <dd style="margin:0;font-weight:600">{{ number_format($queue['waiting']) }}</dd>
            </div>
            <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                <dt>Oldest waiting job</dt>
                <dd style="margin:0;font-weight:600">
                    {{ $queue['oldest_minutes'] === null ? 'None' : $queue['oldest_minutes'].' min' }}
                </dd>
            </div>
            <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                <dt>Failed jobs</dt>
                <dd style="margin:0;font-weight:600">{{ number_format($queue['failed']) }}</dd>
            </div>
            <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                <dt>Database size</dt>
                <dd style="margin:0;font-weight:600">
                    @if ($database)
                        {{ number_format($database['mb'], 1) }} MB of {{ number_format($database['limit_mb']) }} MB ({{ $database['percent'] }}%)@if ($database['warn']), over the warning level @endif
                        <span style="font-weight:400">checked {{ $database['checked_at']->diffForHumans() }}</span>
                    @else
                        Not checked yet
                    @endif
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
