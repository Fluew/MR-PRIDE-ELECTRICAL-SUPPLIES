<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Administrators can archive (soft-delete) a bidding event; it then lives in the Recycle Bin like other procurement records. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bidding_events', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bidding_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by_id');
            $table->dropSoftDeletes();
        });
    }
};
