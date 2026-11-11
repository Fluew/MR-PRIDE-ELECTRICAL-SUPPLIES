<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add individual index on user_id first so MySQL FK requirement is satisfied
        try {
            Schema::table('trusted_devices', function (Blueprint $table) {
                $table->index('user_id', 'trusted_devices_user_id_index');
            });
        } catch (\Throwable) {}

        // 2. Drop unique constraint and adjust columns
        Schema::table('trusted_devices', function (Blueprint $table) {
            try {
                $table->dropUnique('trusted_devices_user_id_device_uuid_unique');
            } catch (\Throwable) {}

            if (Schema::hasColumn('trusted_devices', 'user_id')) {
                $table->foreignId('user_id')->nullable()->change();
            }

            if (! Schema::hasColumn('trusted_devices', 'credential_hash')) {
                $table->string('credential_hash', 64)->nullable()->index()->after('device_uuid');
            }

            if (! Schema::hasColumn('trusted_devices', 'enrollment_code')) {
                $table->string('enrollment_code', 32)->nullable()->index()->after('credential_hash');
            }

            if (! Schema::hasColumn('trusted_devices', 'enrollment_code_expires_at')) {
                $table->timestamp('enrollment_code_expires_at')->nullable()->after('enrollment_code');
            }

            if (! Schema::hasColumn('trusted_devices', 'is_shared')) {
                $table->boolean('is_shared')->default(false)->after('status');
            }

            if (! Schema::hasColumn('trusted_devices', 'authorized_user_ids')) {
                $table->json('authorized_user_ids')->nullable()->after('is_shared');
            }

            if (! Schema::hasColumn('trusted_devices', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('authorized_user_ids');
            }

            if (! Schema::hasColumn('trusted_devices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropColumn([
                'credential_hash',
                'enrollment_code',
                'enrollment_code_expires_at',
                'is_shared',
                'authorized_user_ids',
                'approved_by',
                'approved_at',
            ]);
        });
    }
};
