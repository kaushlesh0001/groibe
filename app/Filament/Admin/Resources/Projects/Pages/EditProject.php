<?php

namespace App\Filament\Admin\Resources\Projects\Pages;

use App\Actions\Projects\AcceptProject;
use App\Actions\Projects\ActivateProject;
use App\Actions\Projects\CancelProject;
use App\Actions\Projects\CloseProject;
use App\Actions\Projects\MarkProjectDelivered;
use App\Actions\Projects\MoveProjectToQa;
use App\Actions\Projects\PutProjectOnHold;
use App\Actions\Projects\ResumeProject;
use App\Actions\Projects\StartStaffing;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Project;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->stepAction('startStaffing', 'Start staffing', ProjectStatus::SowAccepted, StartStaffing::class),
            $this->stepAction('activate', 'Activate', ProjectStatus::Staffing, ActivateProject::class),
            $this->stepAction('moveToQa', 'Move to QA', ProjectStatus::Active, MoveProjectToQa::class),
            $this->stepAction('markDelivered', 'Mark delivered', ProjectStatus::InQa, MarkProjectDelivered::class),
            $this->stepAction('accept', 'Mark accepted', ProjectStatus::Delivered, AcceptProject::class),
            $this->stepAction('close', 'Close project', ProjectStatus::Accepted, CloseProject::class),

            Action::make('hold')
                ->label('Put on hold')
                ->color('warning')
                ->visible(fn (): bool => $this->mayMove([ProjectStatus::Staffing, ProjectStatus::Active]))
                ->schema([Textarea::make('reason')->rows(2)])
                ->action(fn (array $data) => $this->runAction(fn () => app(PutProjectOnHold::class)($this->currentProject(), auth()->user(), $data['reason'] ?? null), 'Project put on hold')),

            $this->stepAction('resume', 'Resume project', ProjectStatus::OnHold, ResumeProject::class),

            Action::make('cancel')
                ->label('Cancel project')
                ->color('danger')
                ->visible(fn (): bool => $this->mayMove(array_filter(ProjectStatus::cases(), fn (ProjectStatus $s) => $s->canTransitionTo(ProjectStatus::Cancelled))))
                ->requiresConfirmation()
                ->modalDescription('This cannot be undone. The reason is kept in the audit log.')
                ->schema([Textarea::make('reason')->required()->rows(3)])
                ->action(fn (array $data) => $this->runAction(fn () => app(CancelProject::class)($this->currentProject(), auth()->user(), $data['reason']), 'Project cancelled')),
        ];
    }

    /** @param class-string $action */
    private function stepAction(string $name, string $label, ProjectStatus $from, string $action): Action
    {
        return Action::make($name)
            ->label($label)
            ->visible(fn (): bool => $this->mayMove([$from]))
            ->requiresConfirmation()
            ->action(fn () => $this->runAction(fn () => app($action)($this->currentProject(), auth()->user()), $label.' done'));
    }

    private function currentProject(): Project
    {
        /** @var Project */
        return $this->getRecord();
    }

    /** @param  list<ProjectStatus>  $from */
    private function mayMove(array $from): bool
    {
        return in_array($this->currentProject()->status, $from, true) && (bool) auth()->user()?->can('transition', $this->currentProject());
    }

    private function runAction(Closure $callback, string $success): void
    {
        DomainActionRunner::run($callback, $success);

        $this->getRecord()->refresh();
        $this->fillForm();
    }
}
