<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Actions\Assignments\InviteContributor;
use App\Actions\Assignments\RemoveAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\OnboardingState;
use App\Enums\RateType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Staffing for a project (spec 6.6, 13). Invites and removals go through the Assignment Actions. */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Team';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('role_title')
            ->columns([
                TextColumn::make('user.name')->label('Contributor'),
                TextColumn::make('role_title')->label('Role'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AssignmentStatus $state): string => $state->color()),
                TextColumn::make('rate_type')->badge()->toggleable(),
                TextColumn::make('agreed_amount')->money('INR')->placeholder('-')->toggleable(),
                TextColumn::make('invited_at')->dateTime()->toggleable(),
                TextColumn::make('accepted_at')->dateTime()->placeholder('Not accepted')->toggleable(),
                TextColumn::make('removed_reason')->limit(40)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('invited_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name'))
            ->headerActions([
                Action::make('invite')
                    ->label('Invite contributor')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('invite', [Assignment::class, $this->ownerProject()]))
                    ->schema([
                        Select::make('user_id')
                            ->label('Contributor')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->activeContributors()
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(25)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('name')),
                        TextInput::make('role_title')->label('Role')->required()->maxLength(255),
                        Select::make('rate_type')->options(RateType::class)->default(RateType::Fixed->value)->required(),
                        TextInput::make('agreed_amount')->numeric()->minValue(0)->prefix('INR'),
                        DatePicker::make('start_date'),
                        DatePicker::make('end_date')->afterOrEqual('start_date'),
                    ])
                    ->action(function (array $data): void {
                        $contributor = User::query()->findOrFail($data['user_id']);

                        DomainActionRunner::run(
                            fn () => app(InviteContributor::class)($this->ownerProject(), $contributor, auth()->user(), array_filter(
                                $data,
                                fn ($value, $key) => $key !== 'user_id' && filled($value),
                                ARRAY_FILTER_USE_BOTH,
                            )),
                            'Invite sent',
                        );
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Their unfinished tasks return to unassigned and the delivery manager is notified.')
                    ->visible(fn (Assignment $record): bool => $record->status === AssignmentStatus::Active
                        && auth()->user()?->can('remove', $record))
                    ->schema([
                        Textarea::make('reason')->required()->rows(3),
                    ])
                    ->action(fn (Assignment $record, array $data) => DomainActionRunner::run(
                        fn () => app(RemoveAssignment::class)($record, auth()->user(), $data['reason']),
                        'Contributor removed from the project',
                    )),
            ]);
    }

    /** @return Builder<User> */
    private function activeContributors(): Builder
    {
        return User::query()
            ->where('role', UserRole::Contributor->value)
            ->where('status', UserStatus::Active->value)
            ->whereHas('profile', fn (Builder $profile) => $profile->where('onboarding_state', OnboardingState::Active->value));
    }

    private function ownerProject(): Project
    {
        /** @var Project */
        return $this->getOwnerRecord();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', Assignment::class);
    }
}
