<?php

namespace App\Filament\Admin\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Model;

/**
 * The "needs review" marker for seeded website content whose wording the founder has not approved (SCR-021).
 * A flagged row can never be active (see GatedByReview), so nothing flagged reaches a public page. The admin clears
 * the flag with "Approve wording", then switches the row on.
 */
class NeedsReview
{
    public static function column(): TextColumn
    {
        return TextColumn::make('needs_review')
            ->label('Review')
            ->badge()
            ->state(fn (Model $record): string => $record->getAttribute('needs_review') ? 'Needs review' : '')
            ->color('warning');
    }

    public static function filter(): TernaryFilter
    {
        return TernaryFilter::make('needs_review')->label('Needs review')->trueLabel('Needs review')->falseLabel('Approved');
    }

    /** The two toggles of a form: the flag, and the visibility switch that stays off while the flag is on. @return list<Toggle> */
    public static function toggles(string $activeLabel = 'Shown on the site'): array
    {
        return [
            Toggle::make('needs_review')
                ->label('Needs review')
                ->live()
                ->helperText('Seeded wording that has not been approved. While this is on, the item stays hidden from the public site. Turn it off once you have read and approved the text.'),
            Toggle::make('is_active')
                ->label($activeLabel)
                ->disabled(fn (Get $get): bool => (bool) $get('needs_review'))
                ->helperText('Switched off automatically while "Needs review" is on.'),
        ];
    }

    public static function approveAction(): Action
    {
        return Action::make('approveWording')
            ->label('Approve wording')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Confirm you have read this text and it is ready for the public site. It stays hidden until you switch it on.')
            ->visible(fn (Model $record): bool => (bool) $record->getAttribute('needs_review') && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (Model $record): void {
                $record->forceFill(['needs_review' => false])->save();

                Notification::make()->title('Wording approved. Switch it on when you are ready.')->success()->send();
            });
    }
}
