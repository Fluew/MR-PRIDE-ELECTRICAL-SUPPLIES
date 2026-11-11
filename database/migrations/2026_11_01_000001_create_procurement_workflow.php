<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connects the existing procurement records into one workflow:
 * Purchase Request (approval) → Purchase Order → Inbound Delivery → Inspection
 * → Commercial Invoice verification → inventory posting.
 * No new duplicate tables: the existing ones get their links and control columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // AHP / forecast snapshot taken by the server when the request is submitted.
            $table->integer('current_stock')->nullable()->after('quantity');
            $table->integer('forecast_quantity')->nullable()->after('current_stock');
            $table->decimal('ahp_score', 6, 2)->nullable()->after('forecast_quantity');
            $table->string('ahp_classification', 40)->nullable()->after('ahp_score');
            $table->integer('recommended_quantity')->nullable()->after('ahp_classification');
            $table->text('justification')->nullable()->after('notes');
            $table->dateTime('submitted_at')->nullable()->after('requested_at');
            $table->foreignId('reviewed_by_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->text('review_remarks')->nullable()->after('reviewed_at');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('purchase_request_id')->nullable()->after('team_id')->constrained('purchase_requests')->nullOnDelete();
            $table->unique('purchase_request_id');
            $table->foreignId('prepared_by_id')->nullable()->after('supplier_id')->constrained('users')->nullOnDelete();
            $table->string('payment_terms', 100)->nullable()->after('total_cost');
            $table->text('quantity_adjustment_reason')->nullable()->after('payment_terms');
            $table->dateTime('sent_at')->nullable()->after('status');
            $table->dateTime('closed_at')->nullable()->after('sent_at');
            $table->text('cancellation_reason')->nullable()->after('closed_at');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('supplier_document_number', 100)->nullable()->after('delivery_number');
            $table->text('over_receipt_reason')->nullable()->after('quantity');
            $table->foreignId('over_receipt_approved_by_id')->nullable()->after('over_receipt_reason')->constrained('users')->nullOnDelete();
            $table->string('inspection_number', 50)->nullable()->after('inspection_result');
            $table->string('rejection_category', 30)->nullable()->after('rejection_reason');
            $table->string('resolution_status', 30)->nullable()->after('rejection_category');
            $table->text('resolution_remarks')->nullable()->after('resolution_status');
            $table->dateTime('resolved_at')->nullable()->after('resolution_remarks');
            $table->unsignedInteger('posted_quantity')->nullable()->after('resolved_at');
            $table->dateTime('posted_at')->nullable()->after('posted_quantity');
            $table->foreignId('posted_by_id')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->after('posted_by_id')->constrained('stock_movements')->nullOnDelete();
        });

        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('team_id')->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->after('purchase_order_id')->constrained('deliveries')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->after('delivery_id')->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 12, 2)->default(0)->after('total_cost');
            $table->string('payment_terms', 100)->nullable()->after('tax_amount');
            $table->foreignId('recorded_by_id')->nullable()->after('supplier_id')->constrained('users')->nullOnDelete();
            $table->json('discrepancies')->nullable()->after('status');
            $table->foreignId('verified_by_id')->nullable()->after('discrepancies')->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable()->after('verified_by_id');
            $table->text('verification_remarks')->nullable()->after('verified_at');
            $table->index(['team_id', 'supplier_id', 'invoice_number']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        // Existing records move onto the new statuses. Old requests that were already ordered
        // have no PO link, so they become "Converted" (closed) rather than convertible again.
        DB::table('purchase_requests')->where('status', 'Requested')->update(['status' => 'Pending Approval', 'submitted_at' => DB::raw('requested_at')]);
        DB::table('purchase_requests')->whereIn('status', ['Ordered', 'Delivered'])->update(['status' => 'Converted to Purchase Order']);
        DB::table('purchase_requests')->where('status', 'Cancelled')->update(['status' => 'Rejected', 'review_remarks' => 'Cancelled before the approval workflow existed.']);

        DB::table('purchase_orders')->whereIn('status', ['Issued', 'Pending'])->update(['status' => 'Sent to Supplier']);
        DB::table('purchase_orders')->where('status', 'Received')->update(['status' => 'Fully Delivered']);

        DB::table('commercial_invoices')->where('status', '!=', 'Cancelled')->update(['status' => 'Pending Verification']);
    }

    public function down(): void
    {
        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'supplier_id', 'invoice_number']);
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropConstrainedForeignId('delivery_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('recorded_by_id');
            $table->dropConstrainedForeignId('verified_by_id');
            $table->dropColumn(['tax_amount', 'payment_terms', 'discrepancies', 'verified_at', 'verification_remarks']);
        });
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('over_receipt_approved_by_id');
            $table->dropConstrainedForeignId('posted_by_id');
            $table->dropConstrainedForeignId('stock_movement_id');
            $table->dropColumn(['supplier_document_number', 'over_receipt_reason', 'inspection_number', 'rejection_category', 'resolution_status', 'resolution_remarks', 'resolved_at', 'posted_quantity', 'posted_at']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['purchase_request_id']);
            $table->dropConstrainedForeignId('purchase_request_id');
            $table->dropConstrainedForeignId('prepared_by_id');
            $table->dropColumn(['payment_terms', 'quantity_adjustment_reason', 'sent_at', 'closed_at', 'cancellation_reason']);
        });
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['current_stock', 'forecast_quantity', 'ahp_score', 'ahp_classification', 'recommended_quantity', 'justification', 'submitted_at', 'reviewed_at', 'review_remarks']);
        });
    }
};
