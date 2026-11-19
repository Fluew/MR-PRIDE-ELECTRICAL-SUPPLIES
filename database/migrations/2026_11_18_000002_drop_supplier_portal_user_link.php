<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Suppliers are records managed by staff; they have no user account, so the link column goes. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('suppliers', 'user_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: the supplier login feature was removed.
    }
};
