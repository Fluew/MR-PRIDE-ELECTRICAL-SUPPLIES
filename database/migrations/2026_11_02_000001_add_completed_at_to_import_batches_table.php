<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import batches record when they were completed: the duplicate-file check reads completed_at,
 * but the original migration created only imported_at, so every upload failed with "Unknown
 * column 'completed_at'". Completed batches get their completion time from imported_at (or
 * updated_at). Adds the index the duplicate-file lookup (team, module, file hash) uses.
 * Safe to run on a database that already has the column or the index; no data is changed
 * other than filling the new column.
 */
return new class extends Migration
{
    private const INDEX = 'import_batches_file_lookup_index';

    public function up(): void
    {
        if (! Schema::hasColumn('import_batches', 'completed_at')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dateTime('completed_at')->nullable()->after('imported_at');
            });
        }

        DB::table('import_batches')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('COALESCE(imported_at, updated_at)')]);

        if (! Schema::hasIndex('import_batches', self::INDEX) && ! Schema::hasIndex('import_batches', ['team_id', 'module', 'file_hash'])) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->index(['team_id', 'module', 'file_hash'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('import_batches', self::INDEX)) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        if (Schema::hasColumn('import_batches', 'completed_at')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropColumn('completed_at');
            });
        }
    }
};
