<?php

namespace App\Filament\Admin\Resources\Submissions;

use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Delivery > QA Queue: submissions awaiting internal review. Read-only apart from the two QA
 * Actions (approve, request changes); submissions are created by contributors, never here.
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static ?string $modelLabel = 'submission';

    protected static ?string $navigationLabel = 'QA Queue';

    protected static ?string $slug = 'qa-queue';

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && $user->can('viewAny', Submission::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['task:id,title,description,milestone_id,project_id', 'task.milestone:id,title,project_id', 'task.milestone.project:id,code,title', 'user:id,name', 'media'])
            ->withCount('media');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
        ];
    }
}
