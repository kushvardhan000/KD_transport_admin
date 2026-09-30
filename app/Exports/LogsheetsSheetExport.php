<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LogsheetsSheetExport implements FromQuery, WithHeadings, WithMapping, WithTitle, WithColumnFormatting, ShouldAutoSize, WithStyles, WithEvents
{
    private const TEXT_COLUMNS = [1, 4, 7, 10];

    private Builder $query;

    private string $status;

    public function __construct(Builder $query, string $status)
    {
        $this->query = $query;
        $this->status = $status;
    }

    public function title(): string
    {
        return $this->status === 'cleared' ? 'Cleared' : 'Pending';
    }

    public function query()
    {
        return $this->query
            ->withoutEagerLoads()
            ->with(['lastImport', 'clearer'])
            ->where('status', $this->status)
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return [
            'Log Sheet No',
            'Date',
            'Vehicle No',
            'TPRT Code',
            'TPRT Name',
            'Destination',
            'SAP Invoice No',
            'Posting Date',
            'Bill Date',
            'Vendor Inv No',
            'Consignments',
            'Gross Wt',
            'Booked Amount',
            'Total Amount',
            'Diff',
            'Status',
            'Cleared At',
            'Cleared By',
            'Import File',
            'Import Period',
        ];
    }

    public function map($row): array
    {
        $import = $row->lastImport;

        $period = '';
        if ($import) {
            $from = $import->date_from?->format('Y-m-d') ?? '';
            $to = $import->date_to?->format('Y-m-d') ?? '';
            $period = $from !== '' && $to !== '' ? $from.' to '.$to : ($from !== '' ? $from : $to);
        }

        return [
            (string) ($row->log_sheet_no ?? ''),
            $this->formatDate($row->date),
            (string) ($row->vehicle_no ?? ''),
            (string) ($row->tprt_code ?? ''),
            (string) ($row->tprt_name ?? ''),
            (string) ($row->destination ?? ''),
            (string) ($row->sap_invoice_no ?? ''),
            $this->formatDate($row->posting_date),
            $this->formatDate($row->bill_date),
            (string) ($row->vendor_inv_no ?? ''),
            (int) ($row->consignment_count ?? 0),
            (float) ($row->total_gross_wt ?? 0),
            (float) ($row->total_booked_amount ?? 0),
            (float) ($row->total_actual_amount ?? 0),
            (float) ($row->total_diff ?? 0),
            (string) ($row->status ?? ''),
            $this->formatDateTime($row->cleared_at),
            (string) ($row->clearer?->name ?? ''),
            (string) ($import?->original_filename ?? ''),
            $period,
        ];
    }

    private function formatDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private function formatDateTime($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();

                foreach (self::TEXT_COLUMNS as $index => $column) {
                    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);

                    for ($row = 2; $row <= $lastRow; $row++) {
                        $coordinate = $letter.$row;
                        $value = $sheet->getCell($coordinate)->getValue();

                        if ($value === null) {
                            continue;
                        }

                        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                    }
                }
            },
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'D' => NumberFormat::FORMAT_TEXT,
            'G' => NumberFormat::FORMAT_TEXT,
            'J' => NumberFormat::FORMAT_TEXT,
            'L' => '0.000',
            'M' => '0.00',
            'N' => '0.00',
            'O' => '0.00',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F4F4F5'],
                ],
            ],
        ];
    }
}
