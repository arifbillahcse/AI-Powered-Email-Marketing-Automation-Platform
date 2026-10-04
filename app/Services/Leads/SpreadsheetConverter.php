<?php

namespace App\Services\Leads;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Turns the first sheet of an Excel (.xlsx) file into CSV, so lead imports
 * accept spreadsheets as well as CSV. Streams row by row (OpenSpout), so
 * big files don't need much memory.
 */
class SpreadsheetConverter
{
    /**
     * @return resource A rewound php://temp stream of UTF-8 CSV
     */
    public function xlsxToCsv(string $path, int $maxRows = 200_001)
    {
        // Default options (date cells arrive as DateTime and are formatted below).
        $reader = new Reader;
        $reader->open($path);

        $csv = fopen('php://temp', 'r+');
        $rows = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map($this->cell(...), $row->toArray());

                    // Skip blank rows (common at the end of exported sheets).
                    if (implode('', $cells) === '') {
                        continue;
                    }

                    fputcsv($csv, $cells, escape: '');

                    if (++$rows >= $maxRows) {
                        break;
                    }
                }

                break; // First sheet only.
            }
        } finally {
            $reader->close();
        }

        rewind($csv);

        return $csv;
    }

    protected function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            // Phone numbers and IDs stored as numbers: no "1.7E+10".
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => sprintf('%.0f', $value),
            default => trim((string) $value),
        };
    }
}
