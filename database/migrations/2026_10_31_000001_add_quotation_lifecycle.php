<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation lifecycle: approval, sending and the customer's decision are recorded separately
 * from the status, supplier cost is kept per line (internal only), and the old statuses are
 * mapped to the new ones. Audit entries can point at the record they are about.
 */
return new class extends Migration
{
    /** old status => new status */
    private const STATUS_MAP = [
        'Draft' => 'under_preparation',
        'draft' => 'under_preparation',
        'Pending' => 'pending_review',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Cancelled' => 'cancelled',
        'Expired' => 'expired',
        'Completed' => 'converted',
    ];

    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('submitted_for_approval_at')->nullable()->after('status');
            $table->foreignId('approved_by_id')->nullable()->after('submitted_for_approval_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->string('email_status', 20)->default('not_sent')->after('approved_at');
            $table->timestamp('sent_at')->nullable()->after('email_status');
            $table->foreignId('sent_by_id')->nullable()->after('sent_at')->constrained('users')->nullOnDelete();
            $table->string('sent_pdf_path')->nullable()->after('sent_by_id');
            $table->timestamp('customer_decision_at')->nullable()->after('sent_pdf_path');
            $table->foreignId('customer_decision_by_id')->nullable()->after('customer_decision_at')->constrained('users')->nullOnDelete();
            $table->string('customer_decision_source', 30)->nullable()->after('customer_decision_by_id');
            $table->text('customer_decision_remarks')->nullable()->after('customer_decision_source');
            $table->timestamp('converted_at')->nullable()->after('converted_to_sale_id');
            $table->foreignId('converted_by_id')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            // Supplier cost when the line was priced. Internal: never on customer documents.
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
            $table->foreignId('supplier_id')->nullable()->after('unit_cost')->constrained('suppliers')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('subject_type', 60)->nullable()->after('target_user_email');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->index(['subject_type', 'subject_id']);
        });

        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('quotations')->where('status', $old)->update(['status' => $new]);
        }
        // Already converted quotations were the ones with a sale.
        DB::table('quotations')->whereNotNull('converted_to_sale_id')->update(['status' => 'converted']);
    }

    public function down(): void
    {
        $back = [
            'pending_review' => 'Pending', 'under_review' => 'Pending', 'awaiting_supplier_pricing' => 'Draft',
            'under_preparation' => 'Draft', 'for_approval' => 'Pending', 'approved' => 'Approved',
            'sent_to_customer' => 'Approved', 'accepted' => 'Approved', 'rejected' => 'Rejected',
            'expired' => 'Expired', 'converted' => 'Completed', 'cancelled' => 'Cancelled',
        ];
        foreach ($back as $new => $old) {
            DB::table('quotations')->where('status', $new)->update(['status' => $old]);
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);
        });
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('unit_cost');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropConstrainedForeignId('sent_by_id');
            $table->dropConstrainedForeignId('customer_decision_by_id');
            $table->dropConstrainedForeignId('converted_by_id');
            $table->dropColumn([
                'submitted_for_approval_at', 'approved_at', 'email_status', 'sent_at', 'sent_pdf_path',
                'customer_decision_at', 'customer_decision_source', 'customer_decision_remarks', 'converted_at',
            ]);
        });
    }
};
