<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that support recoverable deletion (Archive / Recycle Bin).
     *
     * @var array<string>
     */
    protected array $tables = [
        'products',
        'suppliers',
        'clients',
        'quotations',
        'purchase_orders',
        'purchase_requests',
        'commercial_invoices',
        'deliveries',
        'inquiries',
        'sales',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'deleted_at')) {
                        $table->softDeletes();
                    }
                    if (! Schema::hasColumn($tableName, 'deleted_by_id')) {
                        $table->foreignId('deleted_by_id')
                            ->nullable()
                            ->after('deleted_at')
                            ->constrained('users')
                            ->nullOnDelete();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'deleted_by_id')) {
                        $table->dropForeign([$tableName . '_deleted_by_id_foreign']);
                        $table->dropColumn('deleted_by_id');
                    }
                    if (Schema::hasColumn($tableName, 'deleted_at')) {
                        $table->dropSoftDeletes();
                    }
                });
            }
        }
    }
};
