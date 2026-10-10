<?php

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Enums\SubmissionStatus;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /** The queue of work awaiting review is the default tab (spec 13). */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'awaiting';
    }

    /** @return array<string|int, Tab> */
    public function getTabs(): array
    {
        return [
            'awaiting' => Tab::make('Awaiting review')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubmissionStatus::Submitted->value)),
            'changes' => Tab::make('Changes requested')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubmissionStatus::QaChangesRequested->value)),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SubmissionStatus::QaApproved->value)),
            'all' => Tab::make('All'),
        ];
    }
}
