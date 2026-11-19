<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Superseded and intentionally empty. Supplier portal accounts were removed (suppliers do not log in),
 * so this no longer adds suppliers.user_id. The file stays only so databases that already recorded it
 * keep a consistent history; 2026_11_18_000002 drops the column where it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to do.
    }

    public function down(): void
    {
        // Nothing to do.
    }
};
