<?php

use App\Services\LogsheetValueParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize every existing log sheet number to the single canonical form that
 * LogsheetValueParser::logSheetNo() produces, so a stored number and a
 * cleared number are always the same string.
 *
 * `logsheets` is done first because it is the only one of the three tables
 * whose column carries a UNIQUE constraint. A value that cannot be normalized
 * without colliding with another row is left exactly as it was, and the detail
 * and raw-row tables are then told to leave that value alone too, so the three
 * tables can never disagree about which log sheet a row belongs to.
 *
 * Idempotent: a second run finds nothing left to change and touches no rows.
 * Old migrations are never edited; this is additive and irreversible by design
 * (see down()).
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<int, array{value: string, target: string, logsheet_id: int}> $skipped */
        $skipped = [];

        DB::transaction(function () use (&$skipped) {
            $skipped = $this->backfillLogsheets();

            // A log sheet number that had to keep its old value keeps its rows.
            $frozen = array_column($skipped, 'value');

            $this->backfillChildren('logsheet_details', $frozen);
            $this->backfillChildren('logsheet_raw_rows', $frozen);
        });

        if ($skipped !== []) {
            Log::warning('logsheet log_sheet_no backfill skipped values that would have collided', [
                'skipped' => $skipped,
            ]);
        }
    }

    public function down(): void
    {
        // A leading zero cannot be recovered: "0045350959" and "45350959" are
        // the same log sheet, so restoring the old string is not possible.
        // The normalized form is the one the import and clearing services both
        // produce, so down() is intentionally a no-op.
    }

    /**
     * Normalize logsheets.log_sheet_no, skipping any row whose target value is
     * already taken by a row that is not itself moving out of the way.
     *
     * @return array<int, array{value: string, target: string, logsheet_id: int}>
     */
    private function backfillLogsheets(): array
    {
        if (! Schema::hasTable('logsheets') || ! Schema::hasColumn('logsheets', 'log_sheet_no')) {
            return [];
        }

        $changes = $this->collectChanges('logsheets');
        if ($changes === []) {
            return [];
        }

        $movingIds = array_column($changes, 'id');

        $taken = array_flip(DB::table('logsheets')
            ->whereIn('log_sheet_no', array_values(array_unique(array_column($changes, 'to'))))
            ->whereNotIn('id', $movingIds)
            ->pluck('log_sheet_no')
            ->all());

        $skipped = [];
        $written = [];

        foreach ($changes as $change) {
            if (isset($taken[$change['to']])) {
                $skipped[] = [
                    'value' => $change['from'],
                    'target' => $change['to'],
                    'logsheet_id' => $change['id'],
                ];

                continue;
            }

            // Claim the value so two rows competing for the same target cannot
            // both be written; the loser is reported above.
            $taken[$change['to']] = true;
            $written[] = $change;
        }

        $this->applyChanges('logsheets', $written);

        return $skipped;
    }

    /**
     * Normalize log_sheet_no on a child table, leaving frozen values alone.
     *
     * @param  array<int, string>  $frozen
     */
    private function backfillChildren(string $table, array $frozen): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'log_sheet_no')) {
            return;
        }

        $changes = array_values(array_filter(
            $this->collectChanges($table),
            fn (array $change) => ! in_array($change['from'], $frozen, true)
        ));

        $this->applyChanges($table, $changes);
    }

    /**
     * Every row whose value is not already canonical. A second run of this
     * migration therefore returns an empty set and writes nothing.
     *
     * @return array<int, array{id: int, from: string, to: string}>
     */
    private function collectChanges(string $table): array
    {
        $rows = DB::table($table)
            ->select('id', 'log_sheet_no')
            ->whereNotNull('log_sheet_no')
            ->orderBy('id')
            ->get();

        $changes = [];
        foreach ($rows as $row) {
            $from = (string) $row->log_sheet_no;
            $to = LogsheetValueParser::logSheetNo($from);

            if ($to === null || $to === '' || $to === $from) {
                continue;
            }

            $changes[] = ['id' => $row->id, 'from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * One UPDATE per distinct source value rather than per row: the mapping is a
     * pure function of the stored value, so every row holding `from` wants the
     * same `to`, and a large import backfills in a handful of statements.
     *
     * @param  array<int, array{id: int, from: string, to: string}>  $changes
     */
    private function applyChanges(string $table, array $changes): void
    {
        $targets = [];
        foreach ($changes as $change) {
            $targets[$change['from']] = $change['to'];
        }

        foreach ($targets as $from => $to) {
            DB::table($table)
                ->where('log_sheet_no', $from)
                ->update(['log_sheet_no' => $to]);
        }
    }
};
