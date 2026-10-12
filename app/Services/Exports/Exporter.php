<?php

namespace App\Services\Exports;

use App\Exceptions\ActionBlocked;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs an export definition for an admin (spec 15.2, SCR-033). The permission is checked here, when the file is made, so a
 * hidden button is never the only protection. The caller passes the screen's own filtered query, so an export holds
 * exactly the rows the admin was looking at, and nothing their list would not have shown them.
 */
class Exporter
{
    public function __construct(private readonly CsvExport $csv) {}

    /**
     * @template TModel of Model
     *
     * @param  ExportDefinition<TModel>  $definition
     * @param  Builder<TModel>  $query  the screen's filtered query
     *
     * @throws AuthorizationException when the actor does not hold the export permission
     * @throws ActionBlocked when the result is above the row limit
     */
    public function export(ExportDefinition $definition, Builder $query, User $actor): StreamedResponse
    {
        if (! $actor->isAdmin() || ! $actor->can($definition->permission())) {
            throw new AuthorizationException('You are not allowed to export this list.');
        }

        return $this->csv->download(
            $definition->scope($query->reorder())->with($definition->with()),
            $definition->name(),
            $definition->headings(),
            fn (Model $model): array => $definition->row($model),
            $actor,
            $definition->logName(),
        );
    }
}
