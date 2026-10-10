<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Actions\Admin\StartImpersonation;
use App\Actions\Onboarding\SendInvite;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Permission sets')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Pending => 'warning',
                        UserStatus::Suspended => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_login_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')->options(UserRole::class),
                SelectFilter::make('status')->options(UserStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('sendInvite')
                    ->label('Send invite')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalDescription('Email this user a signed activation link that expires in 7 days.')
                    ->visible(fn (User $record): bool => $record->status === UserStatus::Pending
                        && $record->password === null
                        && auth()->user()?->can('update', $record))
                    ->action(function (User $record): void {
                        app(SendInvite::class)($record, auth()->user());

                        Notification::make()->title('Invite sent')->success()->send();
                    }),
                Action::make('impersonate')
                    ->icon('heroicon-o-user-circle')
                    ->requiresConfirmation()
                    ->modalDescription('You will browse as this user until you stop impersonating. This is logged.')
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('impersonate', $record))
                    ->action(function (User $record) {
                        app(StartImpersonation::class)(auth()->user(), $record);

                        return redirect()->route('dashboard');
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
