<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last number handed out per team and number prefix (Q<mmddyy>-<SKU>), so quotation numbers
 * are reserved atomically and never handed out twice, even after a quotation is archived or
 * permanently deleted. Rows are created on first use from the numbers already in `quotations`;
 * existing quotations are not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 191);
            // 0 is the prefix alone (Q103026-PROD), 1 is -01, 2 is -02, and so on.
            $table->integer('last_value');
            $table->timestamps();

            $table->unique(['team_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_number_sequences');
    }
};
