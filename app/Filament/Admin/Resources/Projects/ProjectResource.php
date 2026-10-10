<?php

namespace App\Filament\Admin\Resources\Projects;

use App\Filament\Admin\Resources\Projects\Pages\CreateProject;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\Projects\Pages\ProjectWorkspace;
use App\Filament\Admin\Resources\Projects\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\SowsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Admin\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SowsRelationManager::class,
            AssignmentsRelationManager::class,
            MilestonesRelationManager::class,
            TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
            'workspace' => ProjectWorkspace::route('/{record}/workspace'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
