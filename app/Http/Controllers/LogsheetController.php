<?php

namespace App\Http\Controllers;

use App\Exports\LogsheetsExport;
use App\Exports\LogsheetsSheetExport;
use App\Models\Logsheet;
use App\Models\LogsheetDetail;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Services\LogsheetClearingService;
use App\Services\LogsheetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class LogsheetController extends Controller
{
    private const IMPORT_PAGE_SIZE = 25;

    public function __construct(
        private LogsheetImportService $importService,
        private LogsheetClearingService $clearingService
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
        ]);

        $query = LogsheetImport::query()
            ->select('logsheet_imports.*')
            ->selectSub(
                LogsheetRawRow::query()
                    ->selectRaw('COUNT(DISTINCT log_sheet_no)')
                    ->whereColumn('import_id', 'logsheet_imports.id')
                    ->where('is_valid', true),
                'log_sheet_numbers_count'
            )
            ->selectSub(
                Logsheet::query()
                    ->selectRaw('COUNT(*)')
                    ->where('status', 'cleared')
                    ->whereExists(function ($subquery) {
                        $subquery->select(DB::raw(1))
                            ->from('logsheet_raw_rows')
                            ->whereColumn('logsheet_raw_rows.log_sheet_no', 'logsheets.log_sheet_no')
                            ->whereColumn('logsheet_raw_rows.import_id', 'logsheet_imports.id')
                            ->where('logsheet_raw_rows.is_valid', true);
                    }),
                'cleared_count'
            )
            ->with(['uploader'])
            ->orderByDesc('date_from')
            ->orderByDesc('id');

        // Period filter: import matches if its range overlaps with the filter range
        if ($request->filled('period_from')) {
            $query->where('date_to', '>=', $request->input('period_from'));
        }
        if ($request->filled('period_to')) {
            $query->where('date_from', '<=', $request->input('period_to'));
        }

        $imports = $query->paginate(15)->withQueryString();

        // Grand total over filtered set
        $summaryQuery = clone $query;
        $summaryQuery->getQuery()->orders = [];
        $summaryQuery->getQuery()->limit = null;
        $summaryQuery->getQuery()->offset = null;
        $grandTotal = $summaryQuery->sum('total_amount');

        return view('logsheets.index', [
            'imports' => $imports,
            'grandTotal' => $grandTotal,
            'filters' => $request->only(['period_from', 'period_to']),
        ]);
    }

    private function filterRules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'posting_date_from' => ['nullable', 'date_format:Y-m-d'],
            'posting_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:posting_date_from'],
            'bill_date_from' => ['nullable', 'date_format:Y-m-d'],
            'bill_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date_from'],
            'min_gross_wt' => ['nullable', 'numeric', 'min:0'],
            'max_gross_wt' => ['nullable', 'numeric', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:pending,cleared'],
        ];
    }

    private function filteredQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Logsheet::query()
            ->with(['lastImport', 'details' => fn ($details) => $details->orderBy('id')]);

        $logSheetNo = trim((string) $request->input('log_sheet_no', ''));
        if ($logSheetNo !== '') {
            $query->where('log_sheet_no', 'like', '%'.$logSheetNo.'%');
        }

        $transport = trim((string) $request->input('transport', ''));
        if ($transport !== '') {
            $search = '%'.$transport.'%';
            $query->where(function ($q) use ($search) {
                $q->where('tprt_code', 'like', $search)
                    ->orWhere('tprt_name', 'like', $search)
                    ->orWhereHas('details', function ($q) use ($search) {
                        $q->where('tprt_code', 'like', $search)
                            ->orWhere('tprt_name', 'like', $search);
                    });
            });
        }

        $town = trim((string) $request->input('town', ''));
        if ($town !== '') {
            $search = '%'.$town.'%';
            $query->whereHas('details', function ($q) use ($search) {
                $q->where('town', 'like', $search)
                    ->orWhere('town_2', 'like', $search);
            });
        }

        $location = trim((string) ($request->input('destination') ?? $request->input('location') ?? ''));
        if ($location !== '') {
            $search = '%'.$location.'%';
            $query->where(function ($q) use ($search) {
                $q->where('destination', 'like', $search)
                    ->orWhereHas('details', function ($q) use ($search) {
                        $q->where('destination', 'like', $search)
                            ->orWhere('route', 'like', $search);
                    });
            });
        }

        $vehicleNo = trim((string) $request->input('vehicle_no', ''));
        if ($vehicleNo !== '') {
            $query->where('vehicle_no', 'like', '%'.$vehicleNo.'%');
        }

        $sapInvoiceNo = trim((string) $request->input('sap_invoice_no', ''));
        if ($sapInvoiceNo !== '') {
            $query->where('sap_invoice_no', 'like', '%'.$sapInvoiceNo.'%');
        }

        $vendorInvoiceNo = trim((string) $request->input('vendor_inv_no', ''));
        if ($vendorInvoiceNo !== '') {
            $query->where('vendor_inv_no', 'like', '%'.$vendorInvoiceNo.'%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('posting_date_from')) {
            $query->whereDate('posting_date', '>=', $request->input('posting_date_from'));
        }

        if ($request->filled('posting_date_to')) {
            $query->whereDate('posting_date', '<=', $request->input('posting_date_to'));
        }

        if ($request->filled('bill_date_from')) {
            $query->whereDate('bill_date', '>=', $request->input('bill_date_from'));
        }

        if ($request->filled('bill_date_to')) {
            $query->whereDate('bill_date', '<=', $request->input('bill_date_to'));
        }

        if ($request->filled('min_gross_wt') && is_numeric($request->input('min_gross_wt'))) {
            $query->where('total_gross_wt', '>=', $request->input('min_gross_wt'));
        }

        if ($request->filled('max_gross_wt') && is_numeric($request->input('max_gross_wt'))) {
            $query->where('total_gross_wt', '<=', $request->input('max_gross_wt'));
        }

        if ($request->filled('min_amount') && is_numeric($request->input('min_amount'))) {
            $query->where('total_actual_amount', '>=', $request->input('min_amount'));
        }

        if ($request->filled('max_amount') && is_numeric($request->input('max_amount'))) {
            $query->where('total_actual_amount', '<=', $request->input('max_amount'));
        }

        return $query;
    }

    public function records(Request $request): View
    {
        $request->validate($this->filterRules());

        $query = $this->filteredQuery($request);

        $sortField = strtolower(trim((string) $request->input('sort', 'date')));
        $sortDirection = strtolower(trim((string) $request->input('direction', 'desc')));
        $allowedSortFields = [
            'log_sheet_no', 'date', 'vehicle_no', 'tprt_code', 'tprt_name', 'town',
            'destination', 'sap_invoice_no', 'posting_date', 'bill_date', 'vendor_inv_no',
            'total_gross_wt', 'total_booked_amount', 'total_actual_amount', 'total_diff',
            'consignment_count', 'status', 'cleared_at', 'created_at',
        ];

        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'date';
        }

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        if ($sortField === 'town') {
            $townSortQuery = LogsheetDetail::query()
                ->selectRaw('logsheet_id, MIN(COALESCE(NULLIF(town, ""), NULLIF(town_2, ""))) AS town_sort')
                ->groupBy('logsheet_id');

            $query->leftJoinSub($townSortQuery, 'logsheet_town_sort', fn ($join) => $join->on('logsheet_town_sort.logsheet_id', '=', 'logsheets.id'));
            $query->orderBy('logsheet_town_sort.town_sort', $sortDirection);
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        $logsheets = $query->paginate(25)->withQueryString();

        return view('logsheets.records', [
            'logsheets' => $logsheets,
            'filters' => $request->all(),
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
        ]);
    }

    public function export(Request $request)
    {
        $validated = $request->validate($this->filterRules() + [
            'scope' => ['nullable', 'in:all,pending,cleared'],
            'force_scope' => ['nullable', 'in:all,pending,cleared'],
        ]);

        // Precedence: explicit override (power users) > applied status filter > legacy scope param.
        $forceScope = trim((string) $request->input('force_scope', ''));
        $hasOverride = in_array($forceScope, ['all', 'pending', 'cleared'], true);
        $status = trim((string) $request->input('status', ''));

        if ($hasOverride) {
            // An override means "ignore my status filter", otherwise it could only return an empty sheet.
            $scope = $forceScope;
            $queryRequest = $request->duplicate();
            $queryRequest->merge(['status' => null]);
        } else {
            $scope = in_array($status, ['pending', 'cleared'], true)
                ? $status
                : ($validated['scope'] ?? 'all');
            $queryRequest = $request;
        }

        if (! in_array($scope, ['all', 'pending', 'cleared'], true)) {
            $scope = 'all';
        }

        try {
            $export = new LogsheetsExport($this->filteredQuery($queryRequest), $scope);

            $filename = 'logsheets_'.$scope.'_'.now()->format('Y-m-d_His').'.xlsx';

            return Excel::download($export, $filename);
        } catch (\Throwable $e) {
            Log::error('Logsheet export failed', [
                'exception' => $e,
                'scope' => $scope,
                'override' => $hasOverride,
                'status' => $status,
            ]);

            return back()->with('error', 'Could not export log sheets. Please try again.');
        }
    }

    public function importShow(Request $request, LogsheetImport $import): View
    {
        $request->validate([
            'status' => ['nullable', 'in:completed,pending'],
        ]);

        $scope = $this->importScope($request);

        $import->load(['uploader']);

        $logsheetsQuery = Logsheet::where('last_import_id', $import->id)
            ->with(['lastImport']);

        if ($scope !== 'all') {
            $logsheetsQuery->where('status', $scope === 'completed' ? 'cleared' : 'pending');
        }

        $logsheets = $logsheetsQuery
            ->orderBy('log_sheet_no')
            ->paginate(self::IMPORT_PAGE_SIZE)
            ->withQueryString();

        $invalidRows = LogsheetRawRow::where('import_id', $import->id)
            ->where('is_valid', false)
            ->orderBy('row_number_in_file')
            ->paginate(self::IMPORT_PAGE_SIZE, ['*'], 'invalid_page')
            ->withQueryString();

        $totalCount = Logsheet::where('last_import_id', $import->id)->count();
        $clearedCount = Logsheet::where('last_import_id', $import->id)->where('status', 'cleared')->count();

        return view('logsheets.imports.show', [
            'import' => $import,
            'logsheets' => $logsheets,
            'invalidRows' => $invalidRows,
            'clearedCount' => $clearedCount,
            'totalCount' => $totalCount,
            'scope' => $scope,
        ]);
    }

    public function importExport(Request $request, LogsheetImport $import)
    {
        $request->validate([
            'status' => ['nullable', 'in:completed,pending'],
            'force_status' => ['nullable', 'in:all,completed,pending'],
        ]);

        // Precedence: explicit manual override > applied status filter > everything.
        $forceStatus = trim((string) $request->input('force_status', ''));

        if (in_array($forceStatus, ['all', 'completed', 'pending'], true)) {
            $scope = $forceStatus;
        } else {
            $scope = $this->importScope($request);
        }

        try {
            $query = Logsheet::query()->where('last_import_id', $import->id);

            if ($scope === 'all') {
                $export = new LogsheetsExport($query, 'all');
            } else {
                $export = new LogsheetsSheetExport(clone $query, $scope === 'completed' ? 'cleared' : 'pending');
            }

            $baseName = pathinfo((string) $import->original_filename, PATHINFO_FILENAME);
            if ($baseName === '') {
                $baseName = 'import-'.$import->id;
            }
            $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseName) ?: ('import-'.$import->id);
            $safeName = trim($safeName, '-');
            if ($safeName === '') {
                $safeName = 'import-'.$import->id;
            }
            if (strlen($safeName) > 40) {
                $safeName = trim(substr($safeName, 0, 40), '-');
            }

            $filename = 'logsheets_import_'.$import->id.'_'.$safeName.'_'.$scope.'_'.now()->format('Y-m-d_His').'.xlsx';

            return Excel::download($export, $filename);
        } catch (\Throwable $e) {
            Log::error('Logsheet import export failed', ['exception' => $e, 'import_id' => $import->id, 'scope' => $scope]);

            return back()->with('error', 'Could not export log sheets. Please try again.');
        }
    }

    private function importScope(Request $request): string
    {
        $scope = (string) $request->input('status', 'all');

        return in_array($scope, ['completed', 'pending'], true) ? $scope : 'all';
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $file = $request->file('file');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        try {
            $summary = $this->importService->import($file, $dateFrom, $dateTo);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Import failed: ' . $e->getMessage());
        }

        \Log::info('Import summary', $summary);

        $rowsImported = $summary['rows_imported'] ?? 0;
        $consolidated = $summary['consolidated'] ?? 0;
        $totalAmount = $summary['total_amount'] ?? '0.00';
        $invalid = $summary['invalid'] ?? 0;
        $outOfRangeRows = $summary['out_of_range_rows'] ?? 0;
        $fullyOutOfRangeGroups = $summary['fully_out_of_range_groups'] ?? 0;
        $userProvidedRange = $summary['user_provided_range'] ?? false;
        $detectedDateFrom = $summary['detected_date_from'] ?? null;
        $detectedDateTo = $summary['detected_date_to'] ?? null;

        $successMsg = "Imported {$rowsImported} rows into {$consolidated} consolidated log sheets. Total ₹{$totalAmount}. Invalid rows: {$invalid}.";

        $skipReason = $summary['skip'] ?? null;
        if ($skipReason) {
            return back()->with('error', $skipReason);
        }

        if ($userProvidedRange && ($outOfRangeRows > 0 || $fullyOutOfRangeGroups > 0)) {
            $detectedRange = '';
            if ($detectedDateFrom && $detectedDateTo) {
                $detectedRange = "Your file contains dates from {$detectedDateFrom} to {$detectedDateTo}, ";
            }
            $requestedRange = "but you selected {$dateFrom} to {$dateTo} — ";
            $outOfRangeDetails = [];
            if ($outOfRangeRows > 0) {
                $outOfRangeDetails[] = "{$outOfRangeRows} rows";
            }
            if ($fullyOutOfRangeGroups > 0) {
                $outOfRangeDetails[] = "{$fullyOutOfRangeGroups} log sheets completely outside the range (flagged)";
            }
            $warningMsg = $detectedRange . $requestedRange . implode(' and ', $outOfRangeDetails) . ' fell outside this range.';
            return back()->with('success', $warningMsg . ' ' . $successMsg);
        }

        return back()->with('success', $successMsg);
    }

    public function show(Logsheet $logsheet): View
    {
        $logsheet->load(['details', 'lastImport', 'clearings.clearer']);

        $rawRows = LogsheetRawRow::where('log_sheet_no', $logsheet->log_sheet_no)
            ->orderBy('row_number_in_file')
            ->get();

        return view('logsheets.show', [
            'logsheet' => $logsheet,
            'rawRows' => $rawRows,
        ]);
    }

    public function clearPreview(Request $request): JsonResponse
    {
        $request->validate([
            'numbers' => ['required', 'array', 'min:1'],
            'numbers.*' => ['string'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        try {
            $preview = $this->clearingService->preview(
                $request->input('numbers', []),
                $request->input('date_from'),
                $request->input('date_to')
            );
        } catch (\Throwable $e) {
            Log::error('Logsheet clear preview failed', ['exception' => $e]);

            return response()->json(['message' => 'Could not check the selected log sheets. Please try again.'], 500);
        }

        return response()->json($preview);
    }

    public function clearBulk(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'numbers' => ['required', 'array', 'min:1'],
            'numbers.*' => ['string'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $numbers = $request->input('numbers', []);
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $user = Auth::user();

        $expectsJson = $request->expectsJson() || $request->boolean('expects_json');

        try {
            $report = $this->clearingService->clear($numbers, $dateFrom, $dateTo, $user);
        } catch (\Throwable $e) {
            Log::error('Logsheet bulk clear failed', ['exception' => $e]);

            if ($expectsJson) {
                return response()->json(['message' => 'Could not clear the selected log sheets. Please try again.'], 500);
            }

            return back()->with('error', 'Could not clear the selected log sheets. Please try again.')->withInput();
        }

        if ($expectsJson) {
            return response()->json($report);
        }

        $cleared = $report['counts']['cleared'];
        $alreadyCleared = $report['counts']['already_cleared'];
        $notFound = $report['counts']['not_found'];
        $outOfRange = $report['counts']['out_of_range'] ?? 0;
        $totalClearedAmount = $report['total_cleared_amount'] ?? '0.00';
        $totalRecordsCleared = $report['total_records_cleared'] ?? 0;

        $summary = "Cleared {$cleared} log sheets ({$totalRecordsCleared} records) · already cleared {$alreadyCleared} · not found {$notFound}";
        if ($outOfRange > 0) {
            $summary .= " · out of range {$outOfRange}";
        }

        return back()
            ->with('success', $summary)
            ->with('clear_report', $report)
            ->withInput();
    }

    public function clearLogsheet(Request $request): RedirectResponse
    {
        $numbers = $this->clearingService->normalize($request->input('log_sheet_no', ''));

        $request->merge(['log_sheet_no' => $numbers[0] ?? '']);

        $request->validate([
            'log_sheet_no' => ['required', 'string', 'exists:logsheets,log_sheet_no'],
        ]);

        $report = $this->clearingService->clearSingle(
            $numbers[0] ?? '',
            null,
            null,
            Auth::user()
        );

        $item = $report['items'][0] ?? null;

        if ($item && $item['status'] === 'cleared') {
            return back()->with('success', 'Log sheet cleared successfully.');
        }

        if ($item && $item['status'] === 'already_cleared') {
            return back()->with('info', 'This log sheet is already cleared.');
        }

        return back()->with('error', 'Log sheet not found.');
    }

    public function destroy(Logsheet $logsheet): \Illuminate\Http\RedirectResponse
    {
        $logsheet->delete();

        return redirect()->route('logsheets.records')->with('success', 'Log sheet deleted successfully.');
    }

    public function destroyImport(LogsheetImport $import): \Illuminate\Http\RedirectResponse
    {
        try {
            DB::transaction(function () use ($import) {
                // Raw rows are only removed by the observer for numbers that became log sheets.
                // Invalid/skipped rows still point at this import, so clear them first —
                // otherwise the foreign key blocks deleting the import row.
                LogsheetRawRow::where('import_id', $import->id)->delete();

                // Delete the stored file
                if ($import->file_path && Storage::disk('public')->exists($import->file_path)) {
                    Storage::disk('public')->delete($import->file_path);
                }

                // Force delete all logsheets associated with this import (cascades to details and clearings via observer)
                $logsheets = Logsheet::withTrashed()->where('last_import_id', $import->id)->get();
                foreach ($logsheets as $logsheet) {
                    $logsheet->forceDelete();
                }

                // Delete the import record
                $import->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Logsheet import delete failed', ['exception' => $e, 'import_id' => $import->id]);

            return back()->with('error', 'Could not delete this import. Please try again.');
        }

        return redirect()->route('logsheets.index')->with('success', 'Import and all associated data deleted successfully.');
    }
}
