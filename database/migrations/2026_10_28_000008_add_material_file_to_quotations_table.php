<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the client's original material-list file (PDF/Excel/CSV/image) with the quotation
 * request, next to the existing general attachment, so staff can check the extracted lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'material_file_path')) {
                $table->string('material_file_path')->nullable()->after('attachment_path');
                $table->string('material_file_name')->nullable()->after('material_file_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'material_file_path')) {
                $table->dropColumn(['material_file_path', 'material_file_name']);
            }
        });
    }
};
