<?php

namespace App\Filament\Admin\Resources\Payouts\Pages;

use App\Actions\Payouts\CreatePayout;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Payout;
use App\Services\Finance\CsvExport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->planAction(),
            $this->exportAction(),
        ];
    }

    private function planAction(): Action
    {
        return Action::make('plan')
            ->label('Plan payout')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', Payout::class))
            ->schema([
                Select::make('assignment_id')
                    ->label('Contributor and project')
                    ->required()
                    ->live()
                    ->searchable()
                    ->helperText('Only active or completed seats with an agreed amount on a project past the draft stage. Set the amount on the project Team tab.')
                    ->getSearchResultsUsing(fn (string $search): array => $this->seats($search))
                    ->options(fn (): array => $this->seats(''))
                    ->getOptionLabelUsing(fn ($value): ?string => $this->label(Assignment::query()->with('project:id,code', 'user:id,name')->find($value))),
                TextInput::make('amount')->numeric()->prefix('INR')->helperText('Leave empty to use the agreed amount of the seat.'),
                Select::make('milestone_id')
                    ->label('Milestone (optional)')
                    ->placeholder('None')
                    ->options(fn (Get $get): array => $get('assignment_id')
                        ? Milestone::query()
                            ->where('project_id', Assignment::query()->whereKey($get('assignment_id'))->value('project_id'))
                            ->orderBy('sort')->pluck('title', 'id')->all()
                        : []),
                Textarea::make('notes')->rows(3)->helperText('Internal. Never shown to the contributor.'),
            ])
            ->action(function (array $data): void {
                $seat = Assignment::query()->findOrFail($data['assignment_id']);

                DomainActionRunner::run(
                    fn () => app(CreatePayout::class)($seat, auth()->user(), array_filter(
                        ['amount' => $data['amount'] ?? null, 'milestone_id' => $data['milestone_id'] ?? null, 'notes' => $data['notes'] ?? null],
                        fn ($value) => filled($value),
                    )),
                    'Payout planned',
                );
            });
    }

    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('export', Payout::class))
            ->action(function () {
                try {
                    return app(CsvExport::class)->download(
                        $this->getFilteredTableQuery()->reorder()->with(['project:id,code,title', 'user:id,name', 'milestone:id,title']),
                        'payouts',
                        ['Payout ID', 'Project code', 'Project title', 'Contributor', 'Milestone', 'Amount', 'Currency', 'TDS', 'Net', 'Status', 'UTR reference', 'Paid on', 'Created'],
                        $this->exportRow(...),
                        auth()->user(),
                        'payout_export',
                    );
                } catch (ActionBlocked $e) {
                    Notification::make()->title('Export not possible')->body($e->getMessage())->danger()->send();

                    return null;
                }
            });
    }

    /**
     * One CSV line. No contributor email or phone and no internal notes (SCR-024).
     *
     * @return list<scalar|null>
     */
    private function exportRow(Model $model): array
    {
        if (! $model instanceof Payout) {
            return [];
        }

        return [
            $model->getKey(), $model->project->code, $model->project->title, $model->user->name, $model->milestone?->title,
            $model->amount, $model->currency, $model->tds_amount, $model->net(), $model->status->value, $model->reference,
            $model->paid_at?->toDateString(), $model->created_at->toDateString(),
        ];
    }

    /** @return array<int, string> */
    private function seats(string $search): array
    {
        return Assignment::query()
            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])
            ->whereNotNull('agreed_amount')
            ->whereHas('project', fn (Builder $project) => $project->where('status', '!=', ProjectStatus::Draft->value))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('role_title', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhereHas('project', fn (Builder $p) => $p->where('code', 'like', "%{$search}%"))))
            ->with('project:id,code', 'user:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Assignment $seat): array => [$seat->id => (string) $this->label($seat)])
            ->all();
    }

    private function label(?Assignment $seat): ?string
    {
        return $seat === null ? null : "{$seat->project->code} · {$seat->user->name} · {$seat->role_title}";
    }
}
