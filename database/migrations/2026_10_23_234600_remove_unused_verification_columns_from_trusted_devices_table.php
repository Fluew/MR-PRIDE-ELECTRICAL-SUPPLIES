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
        Schema::table('trusted_devices', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('trusted_devices', 'verification_code')) {
                $columnsToDrop[] = 'verification_code';
            }
            if (Schema::hasColumn('trusted_devices', 'verification_code_expires_at')) {
                $columnsToDrop[] = 'verification_code_expires_at';
            }
            if (Schema::hasColumn('trusted_devices', 'verification_attempts')) {
                $columnsToDrop[] = 'verification_attempts';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('trusted_devices', 'verification_code')) {
                $table->string('verification_code', 64)->nullable()->after('enrollment_token_expires_at');
            }
            if (! Schema::hasColumn('trusted_devices', 'verification_code_expires_at')) {
                $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
            }
            if (! Schema::hasColumn('trusted_devices', 'verification_attempts')) {
                $table->unsignedInteger('verification_attempts')->default(0)->after('verification_code_expires_at');
            }
        });
    }
};
