<?php

namespace App\Filament\Admin\Resources\OpenRoles\Pages;

use App\Actions\OpenRoles\ArchiveOpenRole;
use App\Actions\OpenRoles\CloseOpenRole;
use App\Actions\OpenRoles\PublishOpenRole;
use App\Actions\OpenRoles\SaveOpenRole;
use App\Enums\OpenRoleStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\OpenRoles\OpenRoleResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\OpenRole;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOpenRole extends EditRecord
{
    protected static string $resource = OpenRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->step('publish', 'Publish', OpenRoleStatus::Draft, PublishOpenRole::class, 'success', 'The role goes live for everyone its visibility allows.'),
            $this->step('close', 'Close', OpenRoleStatus::Published, CloseOpenRole::class, 'warning', 'No more applications are taken. Existing applications stay as they are.'),
            $this->step('archive', 'Archive', OpenRoleStatus::Published, ArchiveOpenRole::class, 'gray', 'The role leaves the board without being marked as filled.'),
            DeleteAction::make(),
        ];
    }

    /** @return array<string, mixed> */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['skill_ids'] = $this->role()->skills()->pluck('skills.id')->map(fn ($id): int => (int) $id)->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $skillIds = array_map('intval', $data['skill_ids'] ?? []);
        unset($data['skill_ids']);

        try {
            /** @var OpenRole $record */
            return app(SaveOpenRole::class)(auth()->user(), $data, $record, $skillIds);
        } catch (ActionBlocked $e) {
            // A closed, filled or archived role is history. Say so instead of showing an error page.
            Notification::make()->title('That role can no longer be changed')->body($e->getMessage())->danger()->send();

            $this->halt();

            return $record;
        }
    }

    /** @param  class-string  $action */
    private function step(string $name, string $label, OpenRoleStatus $from, string $action, string $color, string $description): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->visible(fn (): bool => $this->role()->status === $from && (bool) auth()->user()?->can('update', $this->role()))
            ->action(fn () => $this->run(fn () => app($action)($this->role(), auth()->user()), $label.' done'));
    }

    private function role(): OpenRole
    {
        /** @var OpenRole */
        return $this->getRecord();
    }

    private function run(Closure $callback, string $success): void
    {
        DomainActionRunner::run($callback, $success);

        $this->getRecord()->refresh();
        $this->fillForm();
    }
}
