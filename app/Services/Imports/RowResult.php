<?php

namespace App\Services\Imports;

/** What happened to one import row: something was (or would be) created, nothing needed doing, or the row was refused. */
final class RowResult
{
    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * @param  list<string>  $messages  why the row was skipped or refused
     * @param  list<string>  $notes  things worth reporting on a row that went through, such as a skill created inactive
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $messages = [],
        public readonly array $notes = [],
    ) {}

    /** @param  list<string>  $notes */
    public static function created(array $notes = []): self
    {
        return new self(self::CREATED, [], $notes);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, [$reason]);
    }

    /** @param  list<string>  $messages */
    public static function failed(array $messages): self
    {
        return new self(self::FAILED, $messages);
    }
}
