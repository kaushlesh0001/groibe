<?php

namespace App\Filament\Admin\Resources\Contributors\Pages;

use App\Enums\OnboardingState;
use App\Filament\Admin\Resources\Contributors\ContributorResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\ContributorExport;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContributors extends ListRecords
{
    protected static string $resource = ContributorResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(new ContributorExport, fn () => $this->getFilteredTableQuery())];
    }

    /** The review queue is the default tab (spec 13). */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'queue';
    }

    /** @return array<string|int, Tab> */
    public function getTabs(): array
    {
        return [
            'queue' => Tab::make('Review queue')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('onboarding_state', OnboardingState::PendingReview->value)),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('onboarding_state', OnboardingState::Active->value)),
            'suspended' => Tab::make('Suspended')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('onboarding_state', OnboardingState::Suspended->value)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('onboarding_state', OnboardingState::Rejected->value)),
            'all' => Tab::make('All'),
        ];
    }
}
