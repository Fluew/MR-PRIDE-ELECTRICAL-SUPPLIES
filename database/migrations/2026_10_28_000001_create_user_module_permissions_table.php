<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module-level permissions for staff accounts.
 *
 * `users.role` keeps describing the account type (admin / staff_* / user) exactly as before.
 * This table lists the modules a staff member may access. Staff with NO rows here fall back
 * to the single module implied by their existing `staff_*` role, so no data migration is
 * required and existing accounts keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_module_permissions')) {
            return;
        }

        Schema::create('user_module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 32);
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_permissions');
    }
};
