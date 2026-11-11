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
        if (Schema::hasTable('quotations') && ! Schema::hasColumn('quotations', 'attachment_path')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->string('attachment_path')->nullable()->after('terms_and_conditions');
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (! Schema::hasColumn('sales', 'payment_status')) {
                    $table->string('payment_status', 50)->default('Verified')->after('status');
                }
                if (! Schema::hasColumn('sales', 'outstanding_balance')) {
                    $table->decimal('outstanding_balance', 12, 2)->default(0.00)->after('amount_paid');
                }
                if (! Schema::hasColumn('sales', 'verified_by_id')) {
                    $table->foreignId('verified_by_id')->nullable()->after('proof_of_payment')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('sales', 'verified_at')) {
                    $table->dateTime('verified_at')->nullable()->after('verified_by_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('quotations') && Schema::hasColumn('quotations', 'attachment_path')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropColumn('attachment_path');
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('sales', 'verified_by_id')) {
                    $table->dropForeign(['verified_by_id']);
                    $columnsToDrop[] = 'verified_by_id';
                }
                if (Schema::hasColumn('sales', 'payment_status')) {
                    $columnsToDrop[] = 'payment_status';
                }
                if (Schema::hasColumn('sales', 'outstanding_balance')) {
                    $columnsToDrop[] = 'outstanding_balance';
                }
                if (Schema::hasColumn('sales', 'verified_at')) {
                    $columnsToDrop[] = 'verified_at';
                }
                if (! empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
