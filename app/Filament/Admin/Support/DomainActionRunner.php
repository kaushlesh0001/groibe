<?php

namespace App\Filament\Admin\Support;

use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/** Runs a domain Action from the admin panel and turns refusals into a readable notification instead of an error page. */
class DomainActionRunner
{
    /** @return bool true when the action ran */
    public static function run(Closure $callback, string $successTitle): bool
    {
        try {
            $callback();
        } catch (IllegalTransition|ActionBlocked $e) {
            Notification::make()->title('That did not go through')->body($e->getMessage())->danger()->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->title('You are not allowed to do that')->danger()->send();

            return false;
        }

        Notification::make()->title($successTitle)->success()->send();

        return true;
    }
}
