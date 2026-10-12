<?php

namespace App\Services\Exports;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One CSV export (spec 15.2, SCR-033): the permission it needs, its columns, and what one row holds. Lists of people carry
 * name and email, never phone, and no definition may include notes, bios, comments or other free text.
 *
 * @template TModel of Model
 */
abstract class ExportDefinition
{
    /** The permission the actor must hold when the export runs. */
    abstract public function permission(): string;

    /** The file name before the date, such as `contributors`. */
    abstract public function name(): string;

    /** The audit log name the export is recorded under. */
    abstract public function logName(): string;

    /** @return list<string> */
    abstract public function headings(): array;

    /**
     * @param  TModel  $model
     * @return list<scalar|null>
     */
    abstract public function row(Model $model): array;

    /** Relations loaded with the rows, so an export never makes a query per row. @return list<string> */
    public function with(): array
    {
        return [];
    }

    /**
     * Narrows what the screen's own query returns, for an export that is only part of a list.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query): Builder
    {
        return $query;
    }

    protected function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d') : (is_string($value) && $value !== '' ? substr($value, 0, 10) : null);
    }

    protected function dateTime(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d H:i') : null;
    }

    protected function label(?BackedEnum $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof HasLabel => (string) $value->getLabel(),
            default => (string) $value->value,
        };
    }

    protected function yesNo(?bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
