<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment codes (OTPs) are now stored as SHA-256 hashes (64 hex characters) instead of plain
 * text, so the column needs to be 64 wide. Existing rows are kept as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->string('enrollment_code', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing back could truncate stored hashes, so leave the column at 64.
    }
};
