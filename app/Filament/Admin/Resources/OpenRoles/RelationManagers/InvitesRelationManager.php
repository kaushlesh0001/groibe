<?php

namespace App\Filament\Admin\Resources\OpenRoles\RelationManagers;

use App\Actions\OpenRoles\InviteToOpenRole;
use App\Actions\OpenRoles\RemoveOpenRoleInvite;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleVisibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\OpenRole;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The invite list of an invite-only role (SCR-018). Only listed contributors see the role and can apply. */
class InvitesRelationManager extends RelationManager
{
    protected static string $relationship = 'invitedUsers';

    protected static ?string $title = 'Invites';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return $ownerRecord instanceof OpenRole && $ownerRecord->visibility === OpenRoleVisibility::InviteOnly;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Contributor'),
                TextColumn::make('pivot.created_at')->label('Invited')->dateTime(),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label('Invite contributor')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manageInvites', $this->role()))
                    ->schema([
                        Select::make('user_id')
                            ->label('Contributor')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('role', UserRole::Contributor->value)
                                ->where('status', UserStatus::Active->value)
                                ->whereHas('profile', fn (Builder $p) => $p->where('onboarding_state', OnboardingState::Active->value))
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')->limit(25)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('name')),
                    ])
                    ->action(function (array $data): void {
                        $contributor = User::query()->findOrFail($data['user_id']);

                        DomainActionRunner::run(fn () => app(InviteToOpenRole::class)($this->role(), $contributor, auth()->user()), 'Contributor invited');
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manageInvites', $this->role()))
                    ->action(fn (User $record) => DomainActionRunner::run(
                        fn () => app(RemoveOpenRoleInvite::class)($this->role(), $record, auth()->user()),
                        'Invite removed',
                    )),
            ]);
    }

    private function role(): OpenRole
    {
        /** @var OpenRole */
        return $this->getOwnerRecord();
    }
}
