<?php

namespace App\Jobs;

use App\Actions\Imports\BeginImport;
use App\Actions\Imports\CompleteImport;
use App\Actions\Imports\FailImport;
use App\Enums\ImportStatus;
use App\Enums\UserStatus;
use App\Models\Import;
use App\Services\Imports\ImportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Runs one chunk of an import (spec 15.1: 200 rows) and queues the next. One try only: a chunk that half-ran must not
 * run again and double-count, although running it again would create nothing twice, because every row is idempotent.
 * Before each chunk it checks that the person who started the import is still an active admin allowed to run it.
 */
class ProcessImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $importId, public int $offset) {}

    public function handle(ImportRunner $runner, BeginImport $begin, CompleteImport $complete, FailImport $fail): void
    {
        $import = Import::query()->with('creator')->find($this->importId);

        if ($import === null || ! in_array($import->status, [ImportStatus::Queued, ImportStatus::Running], true)) {
            return;
        }

        $creator = $import->creator;

        if ($creator->status !== UserStatus::Active || ! Gate::forUser($creator)->allows('update', $import)) {
            $fail($import, 'The person who started this import can no longer run it.');

            return;
        }

        if ($import->status === ImportStatus::Queued) {
            $begin($import);
        }

        $size = (int) config('groibe.imports.chunk_size');
        $tally = $runner->run($import, true, $creator, $this->offset, $size);

        $import->success_rows += $tally['created'];
        $import->skipped_rows += $tally['skipped'];
        $import->failed_rows += $tally['failed'];
        $import->save();

        if ($tally['seen'] < $size) {
            $complete($import);

            return;
        }

        self::dispatch($import->getKey(), $this->offset + $size);
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::query()->with('creator')->find($this->importId);

        if ($import !== null && in_array($import->status, [ImportStatus::Queued, ImportStatus::Running], true)) {
            app(FailImport::class)($import, $exception::class.': '.$exception->getMessage());
        }
    }
}
