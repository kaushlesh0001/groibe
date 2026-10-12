<?php

namespace App\Services\Imports;

use Generator;
use RuntimeException;

/**
 * Reads an import CSV (spec 15.1, SCR-025). Strips a UTF-8 byte order mark, reads Excel's "CSV" (Windows-1252) as UTF-8
 * when the file is not valid UTF-8, and skips blank lines. A data row's number is its position among the non-blank
 * rows plus one for the header, which is the row number a spreadsheet shows when the file has no blank lines.
 */
class CsvReader
{
    private ?bool $legacyEncoding = null;

    public function __construct(private readonly string $path) {}

    /** @return list<string> trimmed header names, in file order */
    public function headers(): array
    {
        $handle = $this->open();
        $first = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);

        return $first === false ? [] : array_map(fn (?string $cell): string => $this->clean((string) $cell), $first);
    }

    /** Number of non-blank data rows. */
    public function countRows(): int
    {
        $count = 0;

        foreach ($this->rows() as $_) {
            $count++;
        }

        return $count;
    }

    /**
     * @return Generator<int, array<string, string>> row number => cells keyed by header; rows are cut or padded to the header width
     */
    public function rows(int $offset = 0, ?int $limit = null): Generator
    {
        $headers = $this->headers();
        $handle = $this->open();
        fgetcsv($handle, 0, ',', '"', '');

        $index = 0;
        $yielded = 0;

        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null] || $this->isBlank($cells)) {
                    continue;
                }

                if ($index++ < $offset) {
                    continue;
                }

                if ($limit !== null && $yielded >= $limit) {
                    return;
                }

                $cells = array_map(fn (?string $cell): string => $this->clean((string) $cell), $cells);
                $cells = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));

                yield $index + 1 => array_combine($headers, $cells);
                $yielded++;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The import file cannot be read.');
        }

        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }

    /** @param  list<string|null>  $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function clean(string $cell): string
    {
        if ($this->legacyEncoding()) {
            $cell = mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
        }

        return trim(str_replace("\0", '', $cell));
    }

    private function legacyEncoding(): bool
    {
        return $this->legacyEncoding ??= ! mb_check_encoding((string) file_get_contents($this->path), 'UTF-8');
    }
}
