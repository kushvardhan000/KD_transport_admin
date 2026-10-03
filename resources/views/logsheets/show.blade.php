@extends('layouts.app')

@section('content')
@php
    // Every value printed below comes from a user-supplied workbook, so the
    // only safe way to render it is Blade's escaped {{ }}. Cell values are
    // always cast to string first (and JSON-encoded when they are arrays) so an
    // "Array to string conversion" can never reach the page.
    $cell = function ($value) {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_array($value)) {
            return \App\Services\LogsheetValueParser::jsonSafe($value);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    };

    $rawCell = function ($value) use ($cell) {
        return $cell($value);
    };

    $formatColumnValue = function ($value, string $type) use ($cell) {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($type === 'date') {
            return $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : (string) $value;
        }

        if (str_starts_with($type, 'number:')) {
            $decimals = (int) substr($type, 7);
            $text = (string) $value;
            $numeric = is_numeric($text) ? $text : null;

            if ($numeric === null) {
                return $text;
            }

            return number_format((float) $numeric, $decimals);
        }

        return $cell($value);
    };

    $rawColumnCount = count($rawColumns) + 3;
@endphp
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Logsheet Detail</h1>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $logsheet->log_sheet_no }}
                @if($logsheet->fully_out_of_requested_range)
                    <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span>
                        Fully Out of Range
                    </span>
                @endif
            </div>
        </div>
        <span class="rounded-full @if($logsheet->status === 'cleared') bg-emerald-100 text-emerald-800 @else bg-amber-100 text-amber-800 @endif px-3 py-1 font-semibold text-xs">
            {{ ucfirst($logsheet->status ?? 'pending') }}
        </span>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <h3 class="mb-4 font-semibold text-zinc-900 dark:text-zinc-100">Consolidated Summary</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4 text-sm">
            {{-- Date, Status, Total Amount and Consignments are always shown;
                 everything else is hidden while it is still null. --}}
            <div><span class="font-semibold">Date:</span> {{ $logsheet->date?->format('Y-m-d') ?? '—' }}</div>
            <div><span class="font-semibold">Status:</span> {{ ucfirst($logsheet->status ?? 'pending') }}</div>
            <div><span class="font-semibold">Total Amount:</span> ₹{{ number_format($logsheet->total_actual_amount ?? 0, 2) }}</div>
            <div><span class="font-semibold">Consignments:</span> {{ $logsheet->consignment_count ?? 0 }}</div>
            @if($logsheet->vehicle_no)
                <div><span class="font-semibold">Vehicle:</span> {{ $logsheet->vehicle_no }}</div>
            @endif
            @if($logsheet->tprt_code)
                <div><span class="font-semibold">TPRT Code:</span> {{ $logsheet->tprt_code }}</div>
            @endif
            @if($logsheet->tprt_name)
                <div><span class="font-semibold">TPRT Name:</span> {{ $logsheet->tprt_name }}</div>
            @endif
            @if($logsheet->destination)
                <div><span class="font-semibold">Destination:</span> {{ $logsheet->destination }}</div>
            @endif
            @if($logsheet->sap_invoice_no)
                <div><span class="font-semibold">SAP Invoice No:</span> {{ $logsheet->sap_invoice_no }}</div>
            @endif
            @if($logsheet->posting_date)
                <div><span class="font-semibold">Posting:</span> {{ $logsheet->posting_date?->format('Y-m-d') }}</div>
            @endif
            @if($logsheet->bill_date)
                <div><span class="font-semibold">Bill:</span> {{ $logsheet->bill_date?->format('Y-m-d') }}</div>
            @endif
            @if($logsheet->vendor_inv_no)
                <div><span class="font-semibold">Vendor Inv No:</span> {{ $logsheet->vendor_inv_no }}</div>
            @endif
            @if($logsheet->total_gross_wt)
                <div><span class="font-semibold">Gross Wt:</span> {{ number_format($logsheet->total_gross_wt, 3) }}</div>
            @endif
            @if($logsheet->total_booked_amount)
                <div><span class="font-semibold">Booked:</span> {{ number_format($logsheet->total_booked_amount, 2) }}</div>
            @endif
            @if($logsheet->total_diff)
                <div><span class="font-semibold">Diff:</span> {{ number_format($logsheet->total_diff, 2) }}</div>
            @endif
            <div><span class="font-semibold">Cleared At:</span> {{ $logsheet->cleared_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span class="font-semibold">Cleared By:</span> {{ $logsheet->clearer?->name ?? '—' }}</div>
            <div><span class="font-semibold">Last Import:</span> {{ $logsheet->lastImport?->original_filename ?? '—' }}</div>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b px-4 py-3 font-semibold">Consignment Details ({{ $details->total() }} rows)</div>
        @if($details->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-950">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold">Invoice No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold">Inv Date</th>
                            @foreach($visibleColumns as $column)
                                <th class="px-4 py-3 {{ str_starts_with($column['type'], 'number:') ? 'text-right' : 'text-left' }} text-xs font-semibold">{{ $column['label'] }}</th>
                            @endforeach
                            @foreach($extraFieldKeys as $key)
                                <th class="px-4 py-3 text-left text-xs font-semibold">{{ $key }}</th>
                            @endforeach
                            <th class="px-4 py-3 text-center text-xs font-semibold">Cleared</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($details as $detail)
                            <tr class="@if($detail->cleared) bg-emerald-50 dark:bg-emerald-900/20 @endif">
                                <td class="px-4 py-2">{{ ($details->firstItem() ?? 1) + $loop->index }}</td>
                                <td class="px-4 py-2">{{ $detail->date?->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $cell($detail->invoice_no) }}</td>
                                <td class="px-4 py-2">{{ $detail->inv_date?->format('Y-m-d') ?? '—' }}</td>
                                @foreach($visibleColumns as $column)
                                    @php $isNumber = str_starts_with($column['type'], 'number:'); @endphp
                                    <td class="px-4 py-2 {{ $isNumber ? 'text-right font-mono' : '' }} @if($column['key'] === 'diff' && (float) $detail->diff > 0) text-red-600 dark:text-red-400 @elseif($column['key'] === 'diff') text-emerald-600 dark:text-emerald-400 @endif">{{ $formatColumnValue($detail->{$column['key']}, $column['type']) }}</td>
                                @endforeach
                                @foreach($extraFieldKeys as $key)
                                    <td class="px-4 py-2">{{ $cell(is_array($detail->extra_fields) ? ($detail->extra_fields[$key] ?? null) : null) }}</td>
                                @endforeach
                                <td class="px-4 py-2 text-center">
                                    @if($detail->cleared)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                                            <span class="h-1 w-1 rounded-full bg-emerald-600"></span>
                                            Yes
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                                            <span class="h-1 w-1 rounded-full bg-amber-600"></span>
                                            No
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col gap-2 border-t border-zinc-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Showing {{ $details->firstItem() ?? 0 }}-{{ $details->lastItem() ?? 0 }} of {{ $details->total() }}
                </p>
                {{ $details->links() }}
            </div>
        @else
            <div class="px-4 py-16 text-center text-sm text-zinc-500 dark:text-zinc-400">
                No consignment details for this log sheet.
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b px-4 py-3 font-semibold">Raw Imported Rows ({{ $rawRows->total() }} rows)</div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-950">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold">Row #</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold">Log Sheet No</th>
                        @foreach($rawColumns as $key => $label)
                            <th class="px-4 py-3 text-left text-xs font-semibold">{{ $label }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-center text-xs font-semibold">Valid</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold">Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($rawRows as $raw)
                        <tr class="@if(!$raw->is_valid) bg-red-50 dark:bg-red-900/20 @endif">
                            <td class="px-4 py-2">{{ $raw->row_number_in_file }}</td>
                            <td class="px-4 py-2">{{ $cell($raw->log_sheet_no) }}</td>
                            @foreach($rawColumns as $key => $label)
                                <td class="px-4 py-2">{{ $rawCell(is_array($raw->raw_data) ? ($raw->raw_data[$key] ?? null) : null) }}</td>
                            @endforeach
                            <td class="px-4 py-2 text-center">
                                @if($raw->is_valid)
                                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500" title="Valid"></span>
                                @else
                                    <span class="inline-flex h-2 w-2 rounded-full bg-red-500" title="Invalid"></span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-red-600 dark:text-red-400">{{ $cell($raw->validation_error) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $rawColumnCount }}" class="px-4 py-8 text-center text-zinc-500">No raw rows available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rawRows->count() > 0)
            <div class="flex flex-col gap-2 border-t border-zinc-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Showing {{ $rawRows->firstItem() ?? 0 }}-{{ $rawRows->lastItem() ?? 0 }} of {{ $rawRows->total() }}
                </p>
                {{ $rawRows->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
