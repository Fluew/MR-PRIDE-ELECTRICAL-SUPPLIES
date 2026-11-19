<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-managed supplier bidding (RFQ): suppliers never log in. Staff invite suppliers to quote on an
 * approved purchase request, record the quotations suppliers send by email or paper, compare them, and
 * the owner awards one. A purchase request is a single product line, so a bid carries one price and
 * there is no separate bid-items table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bidding_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->string('reference', 30);
            $table->string('status', 30)->default('Draft');
            $table->dateTime('submission_deadline');
            $table->text('rfq_notes')->nullable();
            $table->dateTime('rfq_issued_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('awarded_bid_id')->nullable();
            $table->foreignId('awarded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('awarded_at')->nullable();
            $table->text('award_remarks')->nullable();
            $table->json('award_snapshot')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'reference']);
            $table->index(['team_id', 'status']);
            $table->index('purchase_request_id');
        });

        Schema::create('bidding_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidding_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rfq_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['bidding_event_id', 'supplier_id']);
        });

        Schema::create('supplier_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidding_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->unsignedSmallInteger('lead_time_days');
            $table->date('quotation_date');
            $table->dateTime('received_at');
            $table->date('valid_until')->nullable();
            $table->string('quotation_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('status', 20)->default('Submitted');
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One quotation per invited supplier; a changed quotation is a revision of the same row.
            $table->unique(['bidding_event_id', 'supplier_id']);
        });

        Schema::table('bidding_events', function (Blueprint $table) {
            $table->foreign('awarded_bid_id')->references('id')->on('supplier_bids')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bidding_events', function (Blueprint $table) {
            $table->dropForeign(['awarded_bid_id']);
        });
        Schema::dropIfExists('supplier_bids');
        Schema::dropIfExists('bidding_suppliers');
        Schema::dropIfExists('bidding_events');
    }
};
