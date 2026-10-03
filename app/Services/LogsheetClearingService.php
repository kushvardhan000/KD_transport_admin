<?php

namespace App\Services;

use App\Models\Logsheet;
use App\Models\LogsheetDetail;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

class LogsheetClearingService
{
    public const MAX_NUMBERS = 500;

    public function __construct(
        private ActivityLogger $activityLogger
    ) {}

    /**
     * Turn whatever the user typed into canonical Log Sheet No tokens.
     *
     * The per-token rules are NOT re-implemented here: they are
     * LogsheetValueParser::logSheetNo(), the same function the importer uses
     * when it stores a log sheet number. That is what makes a clearing always
     * find the row its import created (root cause 14 in PROJECT_ANALYSIS).
     * Splitting on separators, de-duplicating and the 500 cap are this
     * service's own behaviour and are kept.
     *
     * @param  array<int, mixed>|string  $input
     * @return array<int, string>
     */
    public function normalize(array|string $input): array
    {
        $numbers = is_string($input) ? [$input] : $input;
        $normalized = [];

        foreach ($numbers as $num) {
            $token = LogsheetValueParser::logSheetNo($num);
            if ($token === null) {
                continue;
            }

            $parts = preg_split('/[\s,;|]+/', $token, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $part) {
                // A separator can split a number in half ("45350 959"), so the
                // part is normalized again rather than trusted.
                $part = LogsheetValueParser::logSheetNo($part);
                if ($part !== null && $part !== '') {
                    $normalized[] = $part;
                }
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($normalized as $num) {
            if (!isset($seen[$num])) {
                $seen[$num] = true;
                $deduped[] = $num;
            }
        }

        if (count($deduped) > self::MAX_NUMBERS) {
            $deduped = array_slice($deduped, 0, self::MAX_NUMBERS);
        }

        return $deduped;
    }

    public function preview(array $numbers, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $normalized = $this->normalize($numbers);

        if (empty($normalized)) {
            return [
                'items' => [],
                'counts' => [
                    'total' => 0,
                    'pending' => 0,
                    'cleared' => 0,
                    'not_found' => 0,
                    'out_of_range' => 0,
                ],
                'total_pending_amount' => '0.00',
                'total_details_pending' => 0,
            ];
        }

        $logsheetsQuery = Logsheet::whereIn('log_sheet_no', $normalized)
            ->select('log_sheet_no', 'status', 'total_actual_amount', 'date');
        $this->applyDateRange($logsheetsQuery, $dateFrom, $dateTo);
        $logsheets = $logsheetsQuery->get()->keyBy('log_sheet_no');
        $existingNumbers = Logsheet::whereIn('log_sheet_no', $normalized)
            ->pluck('log_sheet_no')
            ->flip();

        $items = [];
        $pendingAmount = '0.00';
        $totalDetailsPending = 0;

        foreach ($normalized as $num) {
            $logsheet = $logsheets->get($num);
            $status = 'not_found';
            $amount = '0.00';
            $detailsCount = 0;

            if ($logsheet) {
                $status = $logsheet->status;
                $amount = (string) $logsheet->total_actual_amount;

                // Count detail rows for this log_sheet_no within the period (from ALL imports).
                // A NULL date behaves exactly like the log sheet's: excluded when a
                // period is set, counted when it is not.
                $detailQuery = LogsheetDetail::where('log_sheet_no', $num);
                if ($dateFrom) {
                    $detailQuery->whereDate('date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $detailQuery->whereDate('date', '<=', $dateTo);
                }
                $detailsCount = $detailQuery->where('cleared', false)->count();

                if ($status === 'pending') {
                    $pendingAmount = bcadd($pendingAmount, $amount, 2);
                    $totalDetailsPending += $detailsCount;
                }
            } elseif ($existingNumbers->has($num)) {
                $status = 'out_of_range';
            }

            $items[] = [
                'input' => $num,
                'normalized' => $num,
                'status' => $status,
                'amount' => $amount,
                'details_count' => $detailsCount,
            ];
        }

        $counts = [
            'total' => count($items),
            'pending' => collect($items)->where('status', 'pending')->count(),
            'cleared' => collect($items)->where('status', 'cleared')->count(),
            'not_found' => collect($items)->where('status', 'not_found')->count(),
            'out_of_range' => collect($items)->where('status', 'out_of_range')->count(),
        ];

        return [
            'items' => $items,
            'counts' => $counts,
            'total_pending_amount' => $pendingAmount,
            'total_details_pending' => $totalDetailsPending,
        ];
    }

    public function clear(array $numbers, ?string $dateFrom, ?string $dateTo, ?User $user): array
    {
        $normalized = $this->normalize($numbers);

        if (empty($normalized)) {
            return [
                'items' => [],
                'counts' => [
                    'total' => 0,
                    'cleared' => 0,
                    'already_cleared' => 0,
                    'not_found' => 0,
                    'out_of_range' => 0,
                ],
                'total_cleared_amount' => '0.00',
                'total_records_cleared' => 0,
            ];
        }

        return DB::transaction(function () use ($normalized, $dateFrom, $dateTo, $user) {
            $logsheets = Logsheet::whereIn('log_sheet_no', $normalized)
                ->lockForUpdate()
                ->get()
                ->keyBy('log_sheet_no');

            $inRangeIdsQuery = Logsheet::whereIn('log_sheet_no', $normalized)
                ->select('id', 'log_sheet_no');
            $this->applyDateRange($inRangeIdsQuery, $dateFrom, $dateTo);
            $inRangeIds = $inRangeIdsQuery->pluck('id', 'log_sheet_no');

            $items = [];
            $clearedAmount = '0.00';
            $clearedCount = 0;
            $alreadyClearedCount = 0;
            $notFoundCount = 0;
            $outOfRangeCount = 0;
            $totalRecordsCleared = 0;

            foreach ($normalized as $num) {
                $logsheet = $logsheets->get($num);

                if (!$logsheet) {
                    $items[] = ['input' => $num, 'normalized' => $num, 'status' => 'not_found', 'amount' => '0.00', 'records_cleared' => 0, 'rows_cleared_total' => 0];
                    $notFoundCount++;
                    continue;
                }

                if (!$inRangeIds->has($num)) {
                    $items[] = ['input' => $num, 'normalized' => $num, 'status' => 'out_of_range', 'amount' => '0.00', 'records_cleared' => 0, 'rows_cleared_total' => 0];
                    $outOfRangeCount++;
                    continue;
                }

                $amount = (string) $logsheet->total_actual_amount;
                $status = $logsheet->status === 'cleared' ? 'already_cleared' : 'cleared';

                if ($status === 'cleared') {
                    $logsheet->update([
                        'status' => 'cleared',
                        'cleared_at' => now(),
                        'cleared_by' => $user?->id,
                    ]);
                }

                if (!$logsheet->clearings()->exists()) {
                    $logsheet->clearings()->create([
                        'cleared_by' => $user?->id,
                        'cleared_at' => now(),
                    ]);
                }

                // Clear ALL detail rows with this log_sheet_no inside the period (from ALL imports).
                // A range-less clear therefore also clears the null-dated rows of a
                // minimal import, which is what the user asked for by not giving a range.
                $detailQuery = LogsheetDetail::where('log_sheet_no', $num);
                if ($dateFrom) {
                    $detailQuery->whereDate('date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $detailQuery->whereDate('date', '<=', $dateTo);
                }
                $recordsCleared = $detailQuery->where('cleared', false)->update(['cleared' => true]);

                $rowsClearedTotal = $recordsCleared;

                $items[] = [
                    'input' => $num,
                    'normalized' => $num,
                    'status' => $status,
                    'amount' => $amount,
                    'records_cleared' => $recordsCleared,
                    'rows_cleared_total' => $rowsClearedTotal,
                    'raw_rows_count' => 0,
                ];

                if ($status === 'cleared') {
                    $clearedAmount = bcadd($clearedAmount, $amount, 2);
                    $clearedCount++;
                } else {
                    $alreadyClearedCount++;
                }
                $totalRecordsCleared += $rowsClearedTotal;
            }

            $report = [
                'items' => $items,
                'counts' => [
                    'total' => count($items),
                    'cleared' => $clearedCount,
                    'already_cleared' => $alreadyClearedCount,
                    'not_found' => $notFoundCount,
                    'out_of_range' => $outOfRangeCount,
                ],
                'total_cleared_amount' => $clearedAmount,
                'total_records_cleared' => $totalRecordsCleared,
            ];

            if ($clearedCount > 0) {
                $this->activityLogger->log(
                    action: 'clear_batch',
                    module: 'logsheets',
                    recordId: null,
                    description: "Cleared {$clearedCount} log sheets ({$totalRecordsCleared} records) in batch",
                    recordSummary: "Total: ₹{$clearedAmount}",
                    changes: [
                        'count' => $clearedCount,
                        'records_cleared' => $totalRecordsCleared,
                        'total_amount' => $clearedAmount,
                        'items' => $items,
                    ],
                    user: $user
                );
            }

            return $report;
        });
    }

    public function clearSingle(string $number, ?string $reference, ?string $notes, ?User $user): array
    {
        return $this->clear([$number], null, null, $user);
    }

    /**
     * Constrain a log sheet query to the requested period.
     *
     * A NULL `date` is deliberately left out of any constrained result: a
     * flexible import accepts a workbook whose Date column is blank or
     * unreadable, so a null-dated log sheet cannot be proven to be inside the
     * period the user asked for. It is therefore reported as `out_of_range`
     * when a range is set, and is completely unconstrained (so it clears
     * normally) when no range is set. This is the SQL comparison's own
     * behaviour on NULL, stated here because the whole module depends on it.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Logsheet>|\Illuminate\Database\Query\Builder  $query
     */
    private function applyDateRange($query, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('date', '<=', $dateTo);
        }
    }
}