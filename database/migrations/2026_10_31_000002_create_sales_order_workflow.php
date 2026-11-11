<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Order workflow on top of the existing tables:
 *  - sales            = the Sales Order (order number, payment terms, confirmation, completion)
 *  - sale_items       = ordered lines, with reserved / stock-deducted / released / delivered quantities
 *  - payments         = every payment submission and its verification (history is kept)
 *  - payment_receipts = one acknowledgment receipt per verified payment
 *  - sales_invoices   = issued (and cancelled) sales invoices
 *  - order_fulfillments + delivery_attempt_logs = the delivery record and its status history
 *  - delivery_releases (+ items) = each delivery trip or pickup, with the quantities in it
 *  - stock_movements  = linked to the order and release, with a key so a release is deducted once
 *  - notification_logs = every customer email attempt, unique per event
 *
 * Existing sales are mapped: their status values, one payment record per sale, and an issued
 * invoice for their existing invoice number. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('order_number', 50)->nullable()->after('team_id');
            $table->string('channel', 20)->default('quotation')->after('order_number');
            $table->string('fulfillment_type', 20)->default('delivery')->after('channel');
            $table->string('payment_terms', 20)->default('full')->after('payment_status');
            $table->decimal('required_down_payment', 12, 2)->nullable()->after('payment_terms');
            $table->unsignedSmallInteger('credit_days')->nullable()->after('required_down_payment');
            $table->date('credit_due_date')->nullable()->after('credit_days');
            $table->foreignId('terms_approved_by_id')->nullable()->after('credit_due_date')->constrained('users')->nullOnDelete();
            $table->timestamp('terms_approved_at')->nullable()->after('terms_approved_by_id');
            $table->timestamp('confirmed_at')->nullable()->after('terms_approved_at');
            $table->foreignId('confirmed_by_id')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('confirmed_by_id');
            $table->foreignId('completed_by_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('completed_by_id');
            $table->foreignId('cancelled_by_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by_id');
            $table->string('hold_reason', 500)->nullable()->after('cancellation_reason');
            $table->text('delivery_instructions')->nullable()->after('address');
            $table->unique(['team_id', 'order_number']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'payment_status']);
        });
        Schema::table('sales', function (Blueprint $table) {
            // The current invoice number; new orders get one only when an invoice is issued.
            $table->string('invoice_number')->nullable()->change();
            $table->string('status')->default('pending_payment')->change();
            $table->string('payment_status', 50)->default('unpaid')->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('quotation_item_id')->nullable()->after('sale_id')->constrained('quotation_items')->nullOnDelete();
            $table->decimal('discount', 12, 2)->default(0)->after('unit_price');
            $table->unsignedInteger('reserved_quantity')->default(0)->after('subtotal');
            $table->unsignedInteger('deducted_quantity')->default(0)->after('reserved_quantity');
            $table->unsignedInteger('released_quantity')->default(0)->after('deducted_quantity');
            $table->unsignedInteger('delivered_quantity')->default(0)->after('released_quantity');
            $table->unsignedInteger('cancelled_quantity')->default(0)->after('delivered_quantity');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 30);
            $table->decimal('submitted_amount', 12, 2);
            $table->string('submitted_reference', 100)->nullable();
            $table->date('payment_date');
            $table->string('proof_path')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('submitted');
            $table->string('verified_reference', 100)->nullable();
            // method + normalised reference of verified payments: the same transaction cannot be counted twice.
            $table->string('verified_reference_key', 150)->nullable();
            $table->decimal('verified_amount', 12, 2)->nullable();
            $table->date('verified_payment_date')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_remarks')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('refunded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'verified_reference_key']);
            $table->unique(['team_id', 'idempotency_key']);
            $table->index(['sale_id', 'status']);
        });

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 50);
            $table->decimal('amount_received', 12, 2);
            $table->decimal('order_total', 12, 2);
            $table->decimal('previously_paid', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->date('payment_date');
            $table->string('pdf_path')->nullable();
            $table->foreignId('generated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['team_id', 'receipt_number']);
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 50);
            $table->string('status', 20)->default('issued');
            // The invoicing event, e.g. "confirmation:12": one invoice per event.
            $table->string('issue_key', 100);
            $table->json('customer');
            $table->json('items');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->timestamp('issued_at');
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('replaces_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'invoice_number']);
            $table->unique(['team_id', 'issue_key']);
        });

        Schema::table('order_fulfillments', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('delivered_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
        Schema::table('order_fulfillments', function (Blueprint $table) {
            $table->string('status', 50)->default('order_confirmed')->change();
            $table->string('fulfillment_type', 50)->default('delivery')->change();
            $table->string('contact_number', 50)->nullable()->change();
        });

        Schema::create('delivery_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('release_number', 50);
            $table->string('type', 20)->default('delivery');
            // preparing → ready → dispatched → delivered | returned, or cancelled before it leaves
            $table->string('status', 20)->default('preparing');
            $table->foreignId('prepared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('released_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('received_by_name')->nullable();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proof_path', 500)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'release_number']);
            $table->index(['order_fulfillment_id', 'status']);
        });

        Schema::create('delivery_release_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('delivered_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            // Taken out of inventory for this release (0 when the order line was already deducted).
            $table->unsignedInteger('deducted_quantity')->default(0);
            $table->timestamps();
        });

        Schema::table('delivery_attempt_logs', function (Blueprint $table) {
            $table->foreignId('delivery_release_id')->nullable()->after('order_fulfillment_id')->constrained()->nullOnDelete();
        });
        Schema::table('delivery_attempt_logs', function (Blueprint $table) {
            // Status history entries made by the system (e.g. on order confirmation) have no staff.
            $table->unsignedBigInteger('staff_id')->nullable()->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            $table->foreignId('delivery_release_id')->nullable()->after('sale_id')->constrained()->nullOnDelete();
            // Identifies the business event, so the same release or return is never applied twice.
            $table->string('movement_key', 150)->nullable()->after('delivery_release_id');
            $table->unique('movement_key');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->string('type', 60);
            $table->string('notifiable_type', 60);
            $table->unsignedBigInteger('notifiable_id');
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('idempotency_key', 191)->unique();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['category', 'status']);
        });

        $this->mapExistingRecords();
    }

    private function mapExistingRecords(): void
    {
        $orderStatus = ['Completed' => 'completed', 'Pending Payment' => 'pending_payment', 'Cancelled' => 'cancelled', 'Pending' => 'pending_payment'];
        $fulfillmentStatus = [
            'Pending' => 'order_confirmed', 'Preparing' => 'preparing_order', 'Ready for Pickup' => 'ready_for_dispatch',
            'Dispatched' => 'out_for_delivery', 'Out for Delivery' => 'out_for_delivery', 'Delivered' => 'delivered',
            'Delivery Attempt Failed' => 'delivery_failed', 'Maximum Attempts Reached' => 'delivery_failed',
            'Return to Warehouse' => 'returned', 'Cancelled' => 'cancelled',
        ];
        foreach ($fulfillmentStatus as $old => $new) {
            DB::table('order_fulfillments')->where('status', $old)->update(['status' => $new]);
            DB::table('delivery_attempt_logs')->where('new_status', $old)->update(['new_status' => $new]);
            DB::table('delivery_attempt_logs')->where('previous_status', $old)->update(['previous_status' => $new]);
        }
        DB::table('order_fulfillments')->where('fulfillment_type', 'Delivery')->update(['fulfillment_type' => 'delivery']);
        DB::table('order_fulfillments')->whereIn('fulfillment_type', ['Pickup', 'Pick-up', 'Pick Up'])->update(['fulfillment_type' => 'pickup']);

        $perYear = [];
        foreach (DB::table('sales')->orderBy('id')->get() as $sale) {
            $created = Carbon::parse($sale->created_at ?? $sale->sale_date ?? now());
            $key = $sale->team_id.'-'.$created->year;
            $perYear[$key] = ($perYear[$key] ?? 0) + 1;
            $orderNumber = sprintf('SO-%d-%04d', $created->year, $perYear[$key]);

            $status = $orderStatus[$sale->status] ?? 'pending_payment';
            $total = (float) $sale->grand_total;
            $legacyPayment = (string) ($sale->payment_status ?? '');
            $verified = $legacyPayment === 'Verified';
            // Older sales recorded no amount: a completed, verified sale was paid in full.
            $paid = $sale->amount_paid === null && $verified && $sale->status === 'Completed'
                ? $total
                : (float) ($sale->amount_paid ?? 0);
            $paymentStatus = match (true) {
                $verified && $paid + 0.001 >= $total => 'paid',
                $verified && $paid > 0 => 'partially_paid',
                $legacyPayment === 'Rejected' => 'rejected',
                $legacyPayment === 'Pending Verification' => 'under_verification',
                default => 'unpaid',
            };
            $fulfillment = DB::table('order_fulfillments')->where('sale_id', $sale->id)->first();
            $isCounter = $sale->quotation_id === null;

            DB::table('sales')->where('id', $sale->id)->update([
                'order_number' => $orderNumber,
                'channel' => $isCounter ? 'counter' : 'quotation',
                'fulfillment_type' => $fulfillment?->fulfillment_type === 'pickup' || ($isCounter && ! $fulfillment) ? 'pickup' : 'delivery',
                'status' => $status,
                'payment_status' => $paymentStatus,
                // Only verified money counts as paid.
                'amount_paid' => $verified ? $paid : 0,
                'outstanding_balance' => max(0, round($total - ($verified ? $paid : 0), 2)),
                'confirmed_at' => in_array($status, ['completed'], true) ? ($sale->verified_at ?? $sale->sale_date) : null,
                'completed_at' => $status === 'completed' ? ($sale->updated_at ?? $sale->sale_date) : null,
            ]);

            // Stock for these sales was deducted when they were created.
            DB::table('sale_items')->where('sale_id', $sale->id)->update([
                'deducted_quantity' => DB::raw('quantity'),
                'released_quantity' => $status === 'completed' ? DB::raw('quantity') : 0,
                'delivered_quantity' => $status === 'completed' ? DB::raw('quantity') : 0,
            ]);

            if ($paid > 0 || $legacyPayment !== '') {
                DB::table('payments')->insert([
                    'team_id' => $sale->team_id,
                    'sale_id' => $sale->id,
                    'method' => $sale->payment_method ?: 'Bank Transfer',
                    'submitted_amount' => $paid,
                    'submitted_reference' => $sale->payment_reference,
                    'payment_date' => Carbon::parse($sale->payment_date ?? $sale->sale_date)->toDateString(),
                    'proof_path' => $sale->proof_of_payment,
                    'remarks' => 'Recorded before payment records were kept separately.',
                    'recorded_by_id' => $sale->processed_by_id,
                    'status' => match ($legacyPayment) {
                        'Verified' => 'verified',
                        'Rejected' => 'rejected',
                        default => 'submitted',
                    },
                    'verified_reference' => $verified ? $sale->payment_reference : null,
                    'verified_amount' => $verified ? $paid : null,
                    'verified_payment_date' => $verified ? Carbon::parse($sale->payment_date ?? $sale->sale_date)->toDateString() : null,
                    'verified_by_id' => $verified ? $sale->verified_by_id : null,
                    'verified_at' => $verified ? ($sale->verified_at ?? $sale->sale_date) : null,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->updated_at,
                ]);
            }

            if ($sale->invoice_number && $status !== 'pending_payment') {
                $items = DB::table('sale_items')->where('sale_id', $sale->id)->get(['sku', 'product_name', 'quantity', 'unit_price', 'subtotal']);
                DB::table('sales_invoices')->insert([
                    'team_id' => $sale->team_id,
                    'sale_id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'status' => 'issued',
                    'issue_key' => 'legacy:'.$sale->id,
                    'customer' => json_encode(['name' => $sale->client_name, 'contact_person' => $sale->contact_person, 'email' => $sale->email, 'phone' => $sale->phone, 'address' => $sale->address]),
                    'items' => json_encode($items),
                    'subtotal' => $sale->subtotal,
                    'discount' => $sale->discount,
                    'tax_amount' => $sale->tax_amount,
                    'grand_total' => $sale->grand_total,
                    'issued_at' => $sale->sale_date,
                    'issued_by_id' => $sale->processed_by_id,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        $orderStatus = ['completed' => 'Completed', 'pending_payment' => 'Pending Payment', 'cancelled' => 'Cancelled', 'confirmed' => 'Completed', 'processing' => 'Completed', 'on_hold' => 'Pending Payment'];
        foreach ($orderStatus as $new => $old) {
            DB::table('sales')->where('status', $new)->update(['status' => $old]);
        }
        $paymentStatus = ['paid' => 'Verified', 'partially_paid' => 'Verified', 'rejected' => 'Rejected', 'under_verification' => 'Pending Verification', 'payment_submitted' => 'Pending Verification', 'unpaid' => 'Pending Verification', 'refunded' => 'Rejected'];
        foreach ($paymentStatus as $new => $old) {
            DB::table('sales')->where('payment_status', $new)->update(['payment_status' => $old]);
        }
        foreach (DB::table('sales')->whereNull('invoice_number')->get(['id', 'order_number']) as $sale) {
            DB::table('sales')->where('id', $sale->id)->update(['invoice_number' => $sale->order_number ?? 'SO-'.$sale->id]);
        }
        $fulfillmentStatus = ['order_confirmed' => 'Pending', 'preparing_order' => 'Preparing', 'ready_for_dispatch' => 'Ready for Pickup', 'out_for_delivery' => 'Out for Delivery', 'partially_delivered' => 'Out for Delivery', 'delivered' => 'Delivered', 'completed' => 'Delivered', 'delivery_failed' => 'Delivery Attempt Failed', 'delivery_rescheduled' => 'Pending', 'returned' => 'Return to Warehouse', 'cancelled' => 'Cancelled'];
        foreach ($fulfillmentStatus as $new => $old) {
            DB::table('order_fulfillments')->where('status', $new)->update(['status' => $old]);
        }
        DB::table('delivery_attempt_logs')->whereNull('staff_id')->delete();

        Schema::dropIfExists('notification_logs');
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['movement_key']);
            $table->dropConstrainedForeignId('delivery_release_id');
            $table->dropConstrainedForeignId('sale_id');
            $table->dropColumn('movement_key');
        });
        Schema::table('delivery_attempt_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_release_id');
        });
        Schema::dropIfExists('delivery_release_items');
        Schema::dropIfExists('delivery_releases');
        Schema::table('order_fulfillments', function (Blueprint $table) {
            $table->dropColumn(['completed_at', 'cancelled_at']);
        });
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payments');
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quotation_item_id');
            $table->dropColumn(['discount', 'reserved_quantity', 'deducted_quantity', 'released_quantity', 'delivered_quantity', 'cancelled_quantity']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'order_number']);
            $table->dropIndex(['team_id', 'status']);
            $table->dropIndex(['team_id', 'payment_status']);
            foreach (['terms_approved_by_id', 'confirmed_by_id', 'completed_by_id', 'cancelled_by_id'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn([
                'order_number', 'channel', 'fulfillment_type', 'payment_terms', 'required_down_payment', 'credit_days',
                'credit_due_date', 'terms_approved_at', 'confirmed_at', 'completed_at', 'cancelled_at',
                'cancellation_reason', 'hold_reason', 'delivery_instructions',
            ]);
        });
    }
};
