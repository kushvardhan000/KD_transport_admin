<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Index the two consignment-detail columns the log sheet screens filter and
 * search on, so a period or invoice lookup does not table-scan every detail row
 * of a large import.
 *
 * Idempotent: each index is only attempted when the table, the column and the
 * index itself are all confirmed missing, and a database that refuses the
 * statement for any other reason is logged rather than failing the deployment.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{column: string, index: string}>
     */
    private array $indexes = [
        ['column' => 'invoice_no', 'index' => 'logsheet_details_invoice_no_index'],
        ['column' => 'inv_date', 'index' => 'logsheet_details_inv_date_index'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('logsheet_details')) {
            return;
        }

        foreach ($this->indexes as $index) {
            if (! Schema::hasColumn('logsheet_details', $index['column'])) {
                continue;
            }

            // A different index on the same column already serves the purpose.
            if ($this->hasIndexOn('logsheet_details', $index['column'], $index['index'])) {
                continue;
            }

            try {
                Schema::table('logsheet_details', function (Blueprint $table) use ($index) {
                    $table->index($index['column'], $index['index']);
                });
            } catch (Throwable $e) {
                Log::warning('Could not add logsheet_details index, continuing', [
                    'column' => $index['column'],
                    'index' => $index['index'],
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('logsheet_details')) {
            return;
        }

        foreach ($this->indexes as $index) {
            if (! $this->hasIndexOn('logsheet_details', $index['column'], $index['index'])) {
                continue;
            }

            try {
                Schema::table('logsheet_details', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['index']);
                });
            } catch (Throwable $e) {
                Log::warning('Could not drop logsheet_details index, continuing', [
                    'index' => $index['index'],
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * True when the named index exists, or when any index already covers the
     * column — either way adding another one would be redundant.
     */
    private function hasIndexOn(string $table, string $column, string $indexName): bool
    {
        try {
            return Schema::hasIndex($table, $indexName) || Schema::hasIndex($table, [$column]);
        } catch (Throwable $e) {
            // A driver that cannot report index metadata must not fail the
            // deployment; the guarded add below decides instead.
            return false;
        }
    }
};
