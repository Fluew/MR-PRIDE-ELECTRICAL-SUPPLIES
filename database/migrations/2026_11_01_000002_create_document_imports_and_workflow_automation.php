<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universal document import (inventory products, supplier quotations) with a stored, editable
 * preview and audit trail; inspection damage/defect breakdown; invoice attachments; and
 * evaluation snapshots kept each time the workflow recalculates AHP / Supplier Evaluation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('module', 30); // inventory | supplier_quotation
            $table->string('status', 30)->default('queued'); // queued, processing, ready_for_review, importing, completed, failed, cancelled
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type', 100)->nullable();
            $table->string('file_type', 10)->nullable(); // csv, xlsx, xls, pdf, image
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->string('extraction_method', 30)->nullable(); // csv, spreadsheet, pdf-text, pdf-ocr, ocr
            $table->unsignedInteger('page_count')->nullable();
            $table->json('meta')->nullable(); // supplier quotation: supplier, date, currency, tax basis, reference
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('imported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('imported_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'module', 'status']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->unsignedInteger('page')->nullable();
            $table->text('raw')->nullable();
            $table->json('extracted'); // values as read from the document
            $table->json('values');    // current values (after staff edits)
            $table->json('sources')->nullable(); // field => document | calculated | edited | generated
            $table->json('issues')->nullable();
            $table->string('status', 20)->default('ready'); // ready, needs_review, duplicate, existing, skipped, imported
            $table->boolean('skipped')->default(false);
            $table->foreignId('matched_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('suggestions')->nullable();
            $table->string('created_type', 50)->nullable();
            $table->unsignedBigInteger('created_id')->nullable();
            $table->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['import_batch_id', 'status']);
        });

        // Products may arrive incomplete from an import: nothing is invented, the product stays
        // inactive until staff complete the missing fields.
        Schema::table('products', function (Blueprint $table) {
            // Defaults stay for the other forms; imports write null explicitly when a value is missing.
            $table->decimal('price', 12, 2)->nullable()->default(0)->change();
            $table->decimal('cost_price', 12, 2)->nullable()->default(0)->change();
            $table->string('unit')->nullable()->default('pcs')->change();
            $table->json('missing_fields')->nullable()->after('is_active');
            $table->foreignId('import_batch_id')->nullable()->after('missing_fields')->constrained('import_batches')->nullOnDelete();
        });

        Schema::table('supplier_quotes', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 4)->change();
            $table->decimal('quantity', 14, 3)->nullable()->after('unit_price');
            $table->decimal('amount', 14, 2)->nullable()->after('quantity');
            $table->string('unit_price_source', 20)->default('supplier')->after('amount'); // supplier | calculated
            $table->string('amount_source', 20)->nullable()->after('unit_price_source');
            $table->text('description_as_quoted')->nullable()->after('amount_source');
            $table->foreignId('import_batch_id')->nullable()->after('description_as_quoted')->constrained('import_batches')->nullOnDelete();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedInteger('damaged_quantity')->nullable()->after('rejected_quantity');
            $table->unsignedInteger('defective_quantity')->nullable()->after('damaged_quantity');
        });

        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('verification_remarks');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });

        Schema::create('evaluation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // product_priority | supplier_evaluation
            $table->unsignedBigInteger('subject_id');
            $table->decimal('score', 6, 2)->nullable();
            $table->string('classification', 40)->nullable();
            $table->boolean('complete')->default(false);
            $table->json('payload');
            $table->string('trigger', 60);
            $table->string('trigger_reference', 100)->nullable();
            $table->dateTime('calculated_at');
            $table->timestamps();
            $table->index(['team_id', 'kind', 'subject_id', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_snapshots');
        Schema::table('commercial_invoices', fn (Blueprint $t) => $t->dropColumn(['attachment_path', 'attachment_name']));
        Schema::table('deliveries', fn (Blueprint $t) => $t->dropColumn(['damaged_quantity', 'defective_quantity']));
        Schema::table('supplier_quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['quantity', 'amount', 'unit_price_source', 'amount_source', 'description_as_quoted']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('missing_fields');
        });
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
