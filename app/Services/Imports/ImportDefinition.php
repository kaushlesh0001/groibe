<?php

namespace App\Services\Imports;

use App\Enums\ImportType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One kind of import file (spec 15.1). A definition knows its columns and what one row does. It is built fresh for each
 * run, so what it remembers between rows (emails and organizations already seen in a dry run) never leaks into another run.
 */
abstract class ImportDefinition
{
    /**
     * @param  bool  $commit  false for a dry run: validate and report, write nothing
     * @param  User|null  $actor  the admin who owns the import
     * @param  int|null  $importId  stamped on every record created, so the people from one file can be found again
     */
    public function __construct(
        protected readonly bool $commit,
        protected readonly ?User $actor = null,
        protected readonly ?int $importId = null,
    ) {}

    public static function for(ImportType $type, bool $commit, ?User $actor = null, ?int $importId = null): self
    {
        return match ($type) {
            ImportType::Contributors => new ContributorImport($commit, $actor, $importId),
            ImportType::Clients => new ClientImport($commit, $actor, $importId),
        };
    }

    abstract public function type(): ImportType;

    /** @return array<string, array{label: string, required: bool}> field name => settings, in the order the sample file lists them */
    abstract public function fields(): array;

    /**
     * @param  array<string, string>  $row  field name => trimmed cell
     * @param  int  $line  the row number to quote in reports
     */
    abstract public function process(array $row, int $line): RowResult;

    /** @return list<string> */
    public function requiredFields(): array
    {
        return array_keys(array_filter($this->fields(), fn (array $field): bool => $field['required']));
    }

    /**
     * Guesses which file header holds each field, by comparing names without case, spaces or punctuation.
     *
     * @param  list<string>  $headers
     * @return array<string, string|null> field name => header
     */
    public function suggestMapping(array $headers): array
    {
        $byKey = [];

        foreach ($headers as $header) {
            $byKey[$this->key($header)] ??= $header;
        }

        $mapping = [];

        foreach (array_keys($this->fields()) as $field) {
            $mapping[$field] = $byKey[$this->key($field)] ?? null;
        }

        return $mapping;
    }

    private function key(string $name): string
    {
        return Str::of($name)->lower()->replaceMatches('/[^a-z0-9]/', '')->toString();
    }

    protected function actorId(): ?int
    {
        return $this->actor?->getKey();
    }

    /** Stamps a created record in the audit log with the import it came from. */
    protected function logCreated(object $subject, int $line): void
    {
        activity('import')
            ->causedBy($this->actor)
            ->performedOn($subject)
            ->event('imported')
            ->withProperties(['import_id' => $this->importId, 'row' => $line])
            ->log('Created by import');
    }
}
