<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The same checks run again on save in CreateUser and EditUser: a form field being hidden or disabled is not what keeps a
 * request honest (review finding, Pass 9 item 8).
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                // Changing someone's email is a way to take over their account through a password reset, so only the super admin may.
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->disabled(fn (?object $record): bool => $record !== null && ! self::manageCredentials()),
                // Each role is offered only to someone holding that role's create permission; on edit the role is the super admin's.
                Select::make('role')
                    ->options(function (?object $record): array {
                        // On edit the field is read-only, but it still shows (and validates) the person's current role.
                        return collect(UserRole::cases())
                            ->filter(fn (UserRole $role): bool => self::mayCreate($role) || ($record instanceof User && $record->role === $role))
                            ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->getLabel()])
                            ->all();
                    })
                    ->required()
                    ->disabled(fn (?object $record): bool => $record !== null && ! self::manageCredentials())
                    ->dehydrated(fn (?object $record): bool => $record === null || self::manageCredentials())
                    ->live(),
                Select::make('roles')
                    ->label('Admin permission sets')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get): bool => self::manageCredentials() && self::isAdminRole($get('role'))),
                Select::make('status')
                    ->options(UserStatus::class)
                    ->default(UserStatus::Pending->value)
                    ->required()
                    ->visible(fn (): bool => self::manageCredentials()),
                TextInput::make('timezone')
                    ->required()
                    ->default('Asia/Kolkata'),
                // Other staff send an invite, so the person sets their own password.
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->helperText('Leave blank to send an invite so the user sets their own password.')
                    ->minLength(10)
                    ->visible(fn (): bool => self::manageCredentials())
                    ->dehydrated(fn (?string $state): bool => filled($state) && self::manageCredentials()),
            ]);
    }

    private static function manageCredentials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('manageCredentials', User::class);
    }

    private static function mayCreate(UserRole $role): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('createRole', [User::class, $role]);
    }

    private static function isAdminRole(mixed $role): bool
    {
        return $role === UserRole::Admin || $role === UserRole::Admin->value;
    }
}
