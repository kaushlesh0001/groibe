<?php

namespace App\Filament\Admin\Resources\Contributors\Pages;

use App\Actions\Contributors\VerifyContributorSkill;
use App\Filament\Admin\Resources\Contributors\Actions\ContributorActions;
use App\Filament\Admin\Resources\Contributors\ContributorResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Profile;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Pages\ViewRecord;

/** Contributor review page: state actions and skill verification (spec 6.10, 7.2, 13). */
class ViewContributor extends ViewRecord
{
    protected static string $resource = ContributorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ContributorActions::approve(),
            ContributorActions::reject(),
            ContributorActions::suspend(),
            ContributorActions::reinstate(),
            $this->verifySkillsAction(),
        ];
    }

    private function verifySkillsAction(): Action
    {
        return Action::make('verifySkills')
            ->label('Verify skills')
            ->visible(fn (Profile $record): bool => auth()->user()?->can('verifySkills', $record) ?? false)
            ->fillForm(fn (Profile $record): array => [
                'verified' => $record->user->skills()->wherePivot('is_verified', true)->pluck('skills.id')->all(),
            ])
            ->schema(fn (Profile $record): array => [
                CheckboxList::make('verified')
                    ->label('Skills this contributor has shown evidence of')
                    ->options($record->user->skills()->orderBy('name')->pluck('skills.name', 'skills.id')->all())
                    ->bulkToggleable(),
            ])
            ->action(function (Profile $record, array $data): void {
                $verified = array_map('intval', $data['verified'] ?? []);

                DomainActionRunner::run(function () use ($record, $verified): void {
                    $current = $record->user->skills()->wherePivot('is_verified', true)->pluck('skills.id')->map(fn ($id): int => (int) $id)->all();

                    // Only touch skills whose verification actually changes, so the verifier and date of an earlier check stay put.
                    foreach ($record->user->skills()->pluck('skills.id') as $skillId) {
                        $skillId = (int) $skillId;
                        $now = in_array($skillId, $verified, true);

                        if ($now !== in_array($skillId, $current, true)) {
                            app(VerifyContributorSkill::class)($record, $skillId, auth()->user(), $now);
                        }
                    }
                }, 'Skill verification saved');
            });
    }
}
