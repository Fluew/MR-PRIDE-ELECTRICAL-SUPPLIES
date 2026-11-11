<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Valid Until" is set when a quotation is approved (approval date + the validity days), so a
 * new Draft has none yet. Existing quotations keep their dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Give undated Drafts a date first, so the column can be required again.
        DB::table('quotations')->whereNull('valid_until')->update(['valid_until' => DB::raw('quotation_date')]);

        Schema::table('quotations', function (Blueprint $table) {
            $table->date('valid_until')->nullable(false)->change();
        });
    }
};
