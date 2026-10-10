<?php

namespace App\Filament\Admin\Resources\Organizations\RelationManagers;

use App\Enums\OrgRole;
use App\Enums\UserRole;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Links client users to an organization with an org role (spec 7.1 organization_users). */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Team members';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('org_role')
                ->options(OrgRole::class)
                ->default(OrgRole::Viewer->value)
                ->required(),
            TextInput::make('designation')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('org_role')->badge(),
                TextColumn::make('designation'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->where('role', UserRole::Client->value))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('org_role')->options(OrgRole::class)->default(OrgRole::Owner->value)->required(),
                        TextInput::make('designation')->maxLength(255),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
            ]);
    }
}
