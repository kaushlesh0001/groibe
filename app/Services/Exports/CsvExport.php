<?php

namespace App\Services\Exports;

use App\Exceptions\ActionBlocked;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads (spec 15.2, SCR-024, SCR-025, SCR-026). Synchronous up to 5,000 rows; above that the admin is asked to narrow
 * the filters, since the queued, emailed-link path is not built. The file starts with a UTF-8 byte order mark. Every export is
 * written to the audit log. Text cells that a spreadsheet could read as a formula are prefixed with an apostrophe.
 */
class CsvExport
{
    public const MAX_ROWS = 5000;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already filtered and permission-checked by the caller
     * @param  list<string>  $headings
     * @param  callable(TModel): list<scalar|null>  $row
     *
     * @throws ActionBlocked when the filtered result is above the row limit
     */
    public function download(Builder $query, string $name, array $headings, callable $row, User $actor, string $logName): StreamedResponse
    {
        $count = (clone $query)->count();

        if ($count > self::MAX_ROWS) {
            throw ActionBlocked::guardFailed('That is '.number_format($count).' rows. Exports are limited to '.number_format(self::MAX_ROWS).'. Narrow the filters and try again.');
        }

        activity($logName)->causedBy($actor)->event('exported')
            ->withProperties(['rows' => $count, 'file' => $name])->log("{$name} exported");

        $filename = $name.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $headings, $row): void {
            $out = fopen('php://output', 'w');
            // The byte order mark lets Excel read names with accents as UTF-8 (SCR-025).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings);

            foreach ($query->lazyById(500) as $model) {
                fputcsv($out, array_map($this->safe(...), $row($model)));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @param  scalar|null  $value */
    public function safe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
