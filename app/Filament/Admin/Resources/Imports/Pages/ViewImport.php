<?php

namespace App\Filament\Admin\Resources\Imports\Pages;

use App\Actions\Imports\QueueImportInvites;
use App\Actions\Imports\RunImportDryRun;
use App\Actions\Imports\SaveImportMapping;
use App\Actions\Imports\StartImport;
use App\Enums\ImportStatus;
use App\Filament\Admin\Resources\Imports\ImportResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Import;
use App\Services\Imports\ImportDefinition;
use App\Services\Imports\ImportErrorReport;
use App\Services\Imports\ImportRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One import from upload to report (spec 15.1): match columns, check without saving, start, download the report, send invites.
 * The record is found through the resource query, so another admin's import answers 404.
 */
class ViewImport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ImportResource::class;

    protected string $view = 'filament.admin.imports.view-import';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->record), 404);
    }

    public function getTitle(): string
    {
        return $this->record instanceof Import ? 'Import '.$this->record->type->value.': '.$this->record->original_name : 'Import';
    }

    private function import(): Import
    {
        /** @var Import $import */
        $import = $this->record;

        return $import;
    }

    /**
     * Runs a domain Action, then reloads the import. A run that finished while the page was open (a sync queue,
     * or a quick file) shows its final state at once, instead of the one the Action left it in.
     */
    private function run(\Closure $callback, string $successTitle): bool
    {
        $ok = DomainActionRunner::run($callback, $successTitle);

        $this->import()->refresh();

        return $ok;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->mapAction(),
            $this->dryRunAction(),
            $this->startAction(),
            $this->reportAction(),
            $this->invitesAction(),
        ];
    }

    private function canEditSetup(): bool
    {
        return in_array($this->import()->status, [ImportStatus::Uploaded, ImportStatus::Validated], true);
    }

    private function mapAction(): Action
    {
        return Action::make('map')
            ->label('Match columns')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (): bool => $this->canEditSetup())
            ->fillForm(fn (): array => $this->import()->mapping ?? [])
            ->schema(function (): array {
                $headers = $this->headers();
                $options = array_combine($headers, $headers);

                return array_map(
                    fn (array $settings, string $field): Select => Select::make($field)
                        ->label($settings['label'])
                        ->options($options)
                        ->required($settings['required'])
                        ->placeholder('Not in my file'),
                    $this->definition()->fields(),
                    array_keys($this->definition()->fields()),
                );
            })
            ->action(fn (array $data) => $this->run(
                fn () => app(SaveImportMapping::class)($this->import(), auth()->user(), $data),
                'Columns saved. Check the file next.',
            ));
    }

    private function dryRunAction(): Action
    {
        return Action::make('dryRun')
            ->label('Check without saving')
            ->icon('heroicon-o-clipboard-document-check')
            ->visible(fn (): bool => $this->canEditSetup())
            ->action(fn () => $this->run(
                fn () => app(RunImportDryRun::class)($this->import(), auth()->user()),
                'Check finished. Nothing was saved.',
            ));
    }

    private function startAction(): Action
    {
        return Action::make('start')
            ->label('Start import')
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'This creates '.number_format($this->import()->success_rows).' records. People are created without a password; you send their invites afterwards.')
            ->visible(fn (): bool => $this->import()->status === ImportStatus::Validated)
            ->disabled(fn (): bool => $this->import()->success_rows < 1)
            ->action(fn () => $this->run(
                fn () => app(StartImport::class)($this->import(), auth()->user()),
                'Import queued. It runs in the background.',
            ));
    }

    private function reportAction(): Action
    {
        return Action::make('report')
            ->label('Download report')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => $this->import()->error_report_path !== null)
            ->action(function (): StreamedResponse {
                $import = $this->import();
                abort_unless(auth()->user()?->can('view', $import), 403);

                activity('import')->causedBy(auth()->user())->performedOn($import)->event('report_downloaded')->log('Import report downloaded');

                return response()->streamDownload(function () use ($import): void {
                    readfile(Storage::disk(ImportErrorReport::DISK)->path((string) $import->error_report_path));
                }, "import-{$import->getKey()}-report.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
            });
    }

    private function invitesAction(): Action
    {
        return Action::make('invites')
            ->label('Send invites')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->modalDescription('Queues an activation link for everyone this import created who has not signed up yet. Emails are spread out over time.')
            ->visible(fn (): bool => $this->import()->status === ImportStatus::Completed && $this->import()->success_rows > 0)
            ->action(function (): void {
                $result = null;

                $ok = $this->run(function () use (&$result): void {
                    $result = app(QueueImportInvites::class)($this->import(), auth()->user());
                }, 'Invites queued');

                if ($ok && is_array($result) && $result['skipped'] > 0) {
                    Notification::make()->title($result['skipped'].' people were skipped')
                        ->body('They have already signed up, or you may not invite them.')->warning()->send();
                }
            });
    }

    private function definition(): ImportDefinition
    {
        return ImportDefinition::for($this->import()->type, false);
    }

    /** @return list<string> */
    private function headers(): array
    {
        try {
            return app(ImportRunner::class)->reader($this->import())->headers();
        } catch (RuntimeException) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $import = $this->import();
        $definition = $this->definition();
        $mapping = $import->mapping ?? [];
        $columns = array_intersect_key($definition->fields(), array_flip($definition->requiredFields()));

        $preview = [];

        if ($this->canEditSetup() && collect($definition->requiredFields())->every(fn (string $field): bool => filled($mapping[$field] ?? null))) {
            try {
                $preview = app(ImportRunner::class)->preview($import, (int) config('groibe.imports.preview_rows'));
            } catch (RuntimeException) {
                $preview = [];
            }
        }

        return [
            'import' => $import,
            'fields' => $definition->fields(),
            'mapping' => $mapping,
            'previewColumns' => $columns,
            'preview' => $preview,
            'polling' => in_array($import->status, [ImportStatus::Queued, ImportStatus::Running], true),
        ];
    }
}
