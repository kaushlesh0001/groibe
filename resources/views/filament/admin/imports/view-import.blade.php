<x-filament-panels::page>
    <div @if ($polling) wire:poll.5s @endif style="display:grid;gap:1.5rem">
        <x-filament::section heading="Where this import stands">
            <dl style="margin:0">
                <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                    <dt>Status</dt>
                    <dd style="margin:0;font-weight:600">{{ $import->status->getLabel() }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                    <dt>Rows in the file</dt>
                    <dd style="margin:0;font-weight:600">{{ number_format($import->total_rows) }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                    <dt>{{ $import->dry_run ? 'Would be created' : 'Created' }}</dt>
                    <dd style="margin:0;font-weight:600">{{ number_format($import->success_rows) }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                    <dt>Skipped (already there)</dt>
                    <dd style="margin:0;font-weight:600">{{ number_format($import->skipped_rows) }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                    <dt>Refused (see the report)</dt>
                    <dd style="margin:0;font-weight:600">{{ number_format($import->failed_rows) }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Column matching" collapsible collapsed>
            <dl style="margin:0">
                @foreach ($fields as $field => $settings)
                    <div style="display:flex;justify-content:space-between;padding:0.25rem 0">
                        <dt>{{ $settings['label'] }}@if ($settings['required']) (required)@endif</dt>
                        <dd style="margin:0">{{ $mapping[$field] ?? 'Not in my file' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        @if (count($preview) > 0)
            <x-filament::section heading="First rows, as the check sees them">
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr>
                                <th style="text-align:left;padding:0.25rem 0.5rem">Row</th>
                                <th style="text-align:left;padding:0.25rem 0.5rem">Result</th>
                                @foreach ($previewColumns as $field => $settings)
                                    <th style="text-align:left;padding:0.25rem 0.5rem">{{ $settings['label'] }}</th>
                                @endforeach
                                <th style="text-align:left;padding:0.25rem 0.5rem">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview as $row)
                                <tr>
                                    <td style="padding:0.25rem 0.5rem">{{ $row['line'] }}</td>
                                    <td style="padding:0.25rem 0.5rem">
                                        {{ match ($row['outcome']) { 'created' => 'Would create', 'skipped' => 'Skipped', default => 'Refused' } }}
                                    </td>
                                    @foreach ($previewColumns as $field => $settings)
                                        <td style="padding:0.25rem 0.5rem">{{ $row['cells'][$mapping[$field] ?? ''] ?? '' }}</td>
                                    @endforeach
                                    <td style="padding:0.25rem 0.5rem">{{ implode(' ', $row['messages']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
