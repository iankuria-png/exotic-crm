<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ChunkReadFilter implements IReadFilter
{
    public function __construct(
        private readonly string $worksheetName,
        private readonly int $startRow,
        private readonly int $chunkSize
    ) {
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ((string) $worksheetName !== $this->worksheetName) {
            return false;
        }

        if ((int) $row === 1) {
            return true;
        }

        return (int) $row >= $this->startRow
            && (int) $row < ($this->startRow + $this->chunkSize);
    }
}
