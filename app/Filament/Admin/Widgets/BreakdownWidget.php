<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Admin\DashboardMetrics;
use Filament\Widgets\Widget;

/**
 * A dashboard card with one labelled count per row (spec 13). A widget shows only to admins who hold every
 * permission in `$permissions`, the same abilities the matching resource uses.
 */
abstract class BreakdownWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.breakdown';

    protected static bool $isLazy = false;

    /** @var list<string> */
    protected static array $permissions = [];

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        foreach (static::$permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    abstract protected function heading(): string;

    /** @return array<string, int> */
    abstract protected function rows(DashboardMetrics $metrics): array;

    protected function url(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'heading' => $this->heading(),
            'rows' => $this->rows(app(DashboardMetrics::class)),
            'url' => $this->url(),
        ];
    }
}
