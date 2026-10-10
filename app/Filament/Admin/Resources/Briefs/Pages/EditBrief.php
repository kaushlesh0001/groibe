<?php

namespace App\Filament\Admin\Resources\Briefs\Pages;

use App\Actions\Briefs\ConvertBriefToProject;
use App\Actions\Briefs\DeclineBrief;
use App\Actions\Briefs\MarkBriefInReview;
use App\Actions\Briefs\MarkBriefScoped;
use App\Actions\Briefs\RequestBriefInfo;
use App\Actions\Briefs\ResumeBrief;
use App\Enums\BriefStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Briefs\BriefResource;
use App\Filament\Admin\Resources\Briefs\Pages\Concerns\FillsBriefDisplayData;
use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Brief;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;

class EditBrief extends EditRecord
{
    use FillsBriefDisplayData;

    protected static string $resource = BriefResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->simpleAction('markInReview', 'Mark in review', BriefStatus::Submitted)
                ->action(fn () => $this->runAction(fn () => app(MarkBriefInReview::class)($this->currentBrief(), auth()->user()), 'Brief is now in review')),

            Action::make('requestInfo')
                ->label('Request information')
                ->color('warning')
                ->visible(fn (): bool => $this->mayDo('requestInfo', BriefStatus::InReview))
                ->schema([Textarea::make('note')->label('Question for the client')->required()->rows(4)])
                ->action(fn (array $data) => $this->runAction(fn () => app(RequestBriefInfo::class)($this->currentBrief(), auth()->user(), $data['note']), 'Question sent to the client')),

            Action::make('resume')
                ->label('Resume review')
                ->visible(fn (): bool => $this->mayDo('resume', BriefStatus::NeedsInfo))
                ->requiresConfirmation()
                ->action(fn () => $this->runAction(fn () => app(ResumeBrief::class)($this->currentBrief(), auth()->user()), 'Review resumed')),

            $this->simpleAction('markScoped', 'Mark scoped', BriefStatus::InReview)
                ->action(fn () => $this->runAction(fn () => app(MarkBriefScoped::class)($this->currentBrief(), auth()->user()), 'Brief marked as scoped')),

            Action::make('decline')
                ->label('Decline')
                ->color('danger')
                ->visible(fn (): bool => $this->mayDo('decline', BriefStatus::InReview))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Reason (shared with the client)')->rows(3)])
                ->action(fn (array $data) => $this->runAction(fn () => app(DeclineBrief::class)($this->currentBrief(), auth()->user(), $data['reason'] ?? null), 'Brief declined')),

            Action::make('convert')
                ->label('Convert to project')
                ->color('success')
                ->visible(fn (): bool => $this->mayDo('convert', BriefStatus::Scoped))
                ->modalDescription('Creates a draft project from this brief and copies its skills. You can send the SOW from the project.')
                ->fillForm(fn (): array => [
                    'title' => $this->currentBrief()->title,
                    'delivery_manager_id' => $this->currentBrief()->assigned_admin_id ?? auth()->id(),
                    'start_date' => $this->currentBrief()->target_start?->toDateString(),
                    'due_date' => $this->currentBrief()->deadline?->toDateString(),
                ])
                ->schema([
                    TextInput::make('title')->required()->maxLength(255),
                    Select::make('delivery_manager_id')
                        ->label('Delivery manager')
                        ->options(fn (): array => User::query()->where('role', UserRole::Admin->value)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('contract_value')->numeric()->minValue(0)->prefix('INR'),
                    DatePicker::make('start_date'),
                    DatePicker::make('due_date')->afterOrEqual('start_date'),
                ])
                ->action(function (array $data) {
                    $project = null;

                    $done = DomainActionRunner::run(function () use ($data, &$project): void {
                        $project = app(ConvertBriefToProject::class)($this->currentBrief(), auth()->user(), array_filter($data, fn ($value) => filled($value)));
                    }, 'Project created from brief');

                    if ($done && $project instanceof Project) {
                        return redirect(ProjectResource::getUrl('edit', ['record' => $project]));
                    }

                    return null;
                }),
        ];
    }

    private function currentBrief(): Brief
    {
        /** @var Brief */
        return $this->getRecord();
    }

    private function mayDo(string $ability, BriefStatus $from): bool
    {
        return $this->currentBrief()->status === $from && (bool) auth()->user()?->can($ability, $this->currentBrief());
    }

    private function simpleAction(string $ability, string $label, BriefStatus $from): Action
    {
        return Action::make($ability)
            ->label($label)
            ->visible(fn (): bool => $this->mayDo($ability, $from))
            ->requiresConfirmation();
    }

    private function runAction(\Closure $callback, string $success): void
    {
        DomainActionRunner::run($callback, $success);

        $this->getRecord()->refresh();
        $this->fillForm();
    }
}
