<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client quotation requests keep everything the client sent, in a form staff can review:
 * - request_details: project type, timeline, brands (with the client's notes), where it came from.
 * - source_file_sha256: fingerprint of the material-list file, so the same file is never
 *   imported twice into separate quotations.
 * - quotation_files: extra product-list files (PDF, Excel, CSV, images) sent with the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'request_details')) {
                $table->json('request_details')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('quotations', 'source_file_sha256')) {
                $table->string('source_file_sha256', 64)->nullable()->index()->after('material_file_name');
            }
        });

        if (! Schema::hasTable('quotation_files')) {
            Schema::create('quotation_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 30); // product_list
                $table->string('path');
                $table->string('original_name');
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size');
                $table->string('sha256', 64)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_files');

        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'source_file_sha256')) {
                $table->dropIndex(['source_file_sha256']);
                $table->dropColumn('source_file_sha256');
            }
            if (Schema::hasColumn('quotations', 'request_details')) {
                $table->dropColumn('request_details');
            }
        });
    }
};
