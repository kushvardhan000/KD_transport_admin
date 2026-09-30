<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LogsheetsExport implements WithMultipleSheets
{
    public function __construct(
        private $query,
        private string $scope = 'all'
    ) {}

    public function sheets(): array
    {
        if ($this->scope === 'pending') {
            return [new LogsheetsSheetExport(clone $this->query, 'pending')];
        }

        if ($this->scope === 'cleared') {
            return [new LogsheetsSheetExport(clone $this->query, 'cleared')];
        }

        return [
            new LogsheetsSheetExport(clone $this->query, 'pending'),
            new LogsheetsSheetExport(clone $this->query, 'cleared'),
        ];
    }
}
