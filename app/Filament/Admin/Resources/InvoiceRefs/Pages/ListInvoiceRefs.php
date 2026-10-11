<?php

namespace App\Filament\Admin\Resources\InvoiceRefs\Pages;

use App\Actions\Invoices\SaveInvoiceRef;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\InvoiceRefs\InvoiceRefResource;
use App\Filament\Admin\Resources\InvoiceRefs\Tables\InvoiceRefsTable;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\InvoiceRef;
use App\Models\Project;
use App\Services\Finance\CsvExport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListInvoiceRefs extends ListRecords
{
    protected static string $resource = InvoiceRefResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add invoice reference')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', InvoiceRef::class))
                ->schema([
                    Select::make('project_id')
                        ->label('Project')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Project::query()
                            ->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%"))
                            ->orderByDesc('id')->limit(50)->get(['id', 'code', 'title'])
                            ->mapWithKeys(fn (Project $p): array => [$p->id => "{$p->code} · {$p->title}"])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($p = Project::query()->find($value, ['id', 'code', 'title'])) ? "{$p->code} · {$p->title}" : null),
                    ...InvoiceRefsTable::fields(),
                ])
                ->action(function (array $data): void {
                    $project = Project::query()->findOrFail($data['project_id']);

                    $ok = DomainActionRunner::run(
                        fn () => app(SaveInvoiceRef::class)(auth()->user(), $project, $data),
                        'Invoice reference added',
                    );

                    // Warn, never block: part invoices and change requests are normal (SCR-024).
                    if ($ok && SaveInvoiceRef::exceedsContractValue($project)) {
                        Notification::make()->title('Invoices now exceed the contract value')->body("{$project->code} has invoice references adding up to more than its contract value.")->warning()->send();
                    }
                }),
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('export', InvoiceRef::class))
                ->action(function () {
                    try {
                        return app(CsvExport::class)->download(
                            $this->getFilteredTableQuery()->reorder()->with('project:id,code,title'),
                            'invoice-references',
                            ['Project code', 'Project title', 'Zoho invoice', 'Amount', 'Currency', 'Status', 'Issued', 'Due'],
                            $this->exportRow(...),
                            auth()->user(),
                            'invoice_export',
                        );
                    } catch (ActionBlocked $e) {
                        Notification::make()->title('Export not possible')->body($e->getMessage())->danger()->send();

                        return null;
                    }
                }),
        ];
    }

    /** @return list<scalar|null> */
    private function exportRow(Model $model): array
    {
        if (! $model instanceof InvoiceRef) {
            return [];
        }

        return [
            $model->project->code, $model->project->title, $model->zoho_invoice_ref, $model->amount, $model->currency,
            $model->status->value, $model->issued_at?->toDateString(), $model->due_at?->toDateString(),
        ];
    }
}
