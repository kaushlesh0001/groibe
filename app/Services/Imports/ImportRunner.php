<?php

namespace App\Services\Imports;

use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Reads an import file through its definition, for a dry run or for one chunk of the real run (spec 15.1).
 * It adds to the import's report as it goes and returns what happened; saving the totals is the caller's job.
 */
class ImportRunner
{
    public function __construct(private readonly ImportErrorReport $report) {}

    /**
     * @return array{seen: int, created: int, skipped: int, failed: int}
     */
    public function run(Import $import, bool $commit, ?User $actor, int $offset = 0, ?int $limit = null): array
    {
        $definition = ImportDefinition::for($import->type, $commit, $actor, $import->getKey());
        $mapping = $import->mapping ?? [];
        $tally = ['seen' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->reader($import)->rows($offset, $limit) as $line => $cells) {
            $result = $definition->process($this->mapped($definition, $mapping, $cells), $line);
            $tally['seen']++;
            $tally[$result->outcome]++;

            $this->record($import, $line, $result, $cells);
        }

        return $tally;
    }

    /**
     * What the first rows would do, for the preview on screen. Nothing is written and no report lines are added.
     *
     * @return list<array{line: int, outcome: string, messages: list<string>, cells: array<string, string>}>
     */
    public function preview(Import $import, int $rows): array
    {
        $definition = ImportDefinition::for($import->type, false, null, $import->getKey());
        $mapping = $import->mapping ?? [];
        $preview = [];

        foreach ($this->reader($import)->rows(0, $rows) as $line => $cells) {
            $result = $definition->process($this->mapped($definition, $mapping, $cells), $line);

            $preview[] = [
                'line' => $line,
                'outcome' => $result->outcome,
                'messages' => [...$result->messages, ...$result->notes],
                'cells' => $cells,
            ];
        }

        return $preview;
    }

    public function reader(Import $import): CsvReader
    {
        return new CsvReader(Storage::disk(ImportErrorReport::DISK)->path($import->file_path));
    }

    /**
     * @param  array<string, string|null>  $mapping  field => file header
     * @param  array<string, string>  $cells  file header => cell
     * @return array<string, string>
     */
    private function mapped(ImportDefinition $definition, array $mapping, array $cells): array
    {
        $row = [];

        foreach (array_keys($definition->fields()) as $field) {
            $header = $mapping[$field] ?? null;
            $row[$field] = $header === null ? '' : ($cells[$header] ?? '');
        }

        return $row;
    }

    /** @param  array<string, string>  $cells */
    private function record(Import $import, int $line, RowResult $result, array $cells): void
    {
        $severity = match ($result->outcome) {
            RowResult::FAILED => 'error',
            RowResult::SKIPPED => 'skipped',
            default => null,
        };

        if ($severity !== null) {
            $this->report->append($import, $line, $severity, implode(' | ', $result->messages), $cells);
        }

        foreach ($result->notes as $note) {
            $this->report->append($import, $line, 'note', $note, $cells);
        }
    }
}
