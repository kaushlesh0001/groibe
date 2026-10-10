<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                // Only holders of admin_users.* may create admins or change anyone's role (no privilege escalation).
                Select::make('role')
                    ->options(function (): array {
                        $roles = self::manageAdmins() ? UserRole::cases() : [UserRole::Contributor, UserRole::Client];

                        return collect($roles)
                            ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->getLabel()])
                            ->all();
                    })
                    ->required()
                    ->disabled(fn (?object $record): bool => $record !== null && ! self::manageAdmins())
                    ->dehydrated()
                    ->live(),
                Select::make('roles')
                    ->label('Admin permission sets')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get): bool => self::manageAdmins() && self::isAdminRole($get('role'))),
                Select::make('status')
                    ->options(UserStatus::class)
                    ->default(UserStatus::Pending->value)
                    ->required(),
                TextInput::make('timezone')
                    ->required()
                    ->default('Asia/Kolkata'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->helperText('Leave blank to send an invite so the user sets their own password.')
                    ->minLength(10)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
            ]);
    }

    private static function manageAdmins(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('admin_users.update');
    }

    private static function isAdminRole(mixed $role): bool
    {
        return $role === UserRole::Admin || $role === UserRole::Admin->value;
    }
}
