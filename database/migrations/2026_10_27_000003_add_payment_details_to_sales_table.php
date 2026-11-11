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
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->decimal('amount_paid', 12, 2)->nullable()->after('payment_reference');
            $table->dateTime('payment_date')->nullable()->after('amount_paid');
            $table->string('proof_of_payment')->nullable()->after('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'payment_reference',
                'amount_paid',
                'payment_date',
                'proof_of_payment',
            ]);
        });
    }
};
