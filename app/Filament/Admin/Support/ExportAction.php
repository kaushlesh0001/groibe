<?php

namespace App\Filament\Admin\Support;

use App\Exceptions\ActionBlocked;
use App\Models\User;
use App\Services\Exports\ExportDefinition;
use App\Services\Exports\Exporter;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The "Export CSV" button on an admin list (spec 15.2, SCR-033). It shows only to admins who hold the export's permission,
 * and the permission is checked again when the file is made. The list page passes its own filtered query, so the file holds
 * what the admin was looking at. A refusal (too many rows, no permission) becomes a message instead of an error page.
 */
class ExportAction
{
    /** @param  Closure(): mixed  $query  returns the list's filtered query, for example `fn () => $this->getFilteredTableQuery()` */
    public static function make(ExportDefinition $definition, Closure $query, string $label = 'Export CSV'): Action
    {
        return Action::make('export')
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(function () use ($definition): bool {
                $user = auth()->user();

                return $user instanceof User && $user->isAdmin() && $user->can($definition->permission());
            })
            ->action(function () use ($definition, $query) {
                try {
                    return app(Exporter::class)->export($definition, $query(), auth()->user());
                } catch (ActionBlocked $e) {
                    Notification::make()->title('Export not possible')->body($e->getMessage())->danger()->send();
                } catch (AuthorizationException) {
                    Notification::make()->title('You are not allowed to export this list')->danger()->send();
                }

                return null;
            });
    }
}
