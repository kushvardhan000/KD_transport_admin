<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store a compact, per-import diagnostic summary (missing optional columns,
     * counts of unparseable values, blank-cell counts, and the fatal "missing
     * required columns" reason) so a tolerant import can still explain itself.
     */
    public function up(): void
    {
        Schema::table('logsheet_imports', function (Blueprint $table) {
            if (! Schema::hasColumn('logsheet_imports', 'warnings')) {
                $table->json('warnings')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('logsheet_imports', function (Blueprint $table) {
            if (Schema::hasColumn('logsheet_imports', 'warnings')) {
                $table->dropColumn('warnings');
            }
        });
    }
};
