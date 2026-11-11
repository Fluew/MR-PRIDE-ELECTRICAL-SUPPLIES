<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Users table additions: status and last_login_at
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('role');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
            }
        });

        // 2. Audit logs additions: category, action_type, target user, outcome, severity
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'category')) {
                $table->string('category')->default('audit')->index()->after('id');
            }
            if (! Schema::hasColumn('audit_logs', 'action_type')) {
                $table->string('action_type')->nullable()->index()->after('event');
            }
            if (! Schema::hasColumn('audit_logs', 'target_user_id')) {
                $table->foreignId('target_user_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('audit_logs', 'target_user_email')) {
                $table->string('target_user_email')->nullable()->after('user_email');
            }
            if (! Schema::hasColumn('audit_logs', 'outcome')) {
                $table->string('outcome')->default('success')->after('description');
            }
            if (! Schema::hasColumn('audit_logs', 'severity')) {
                $table->string('severity')->default('info')->after('outcome');
            }
        });

        // 3. Trusted devices additions: enrollment token & expiry
        Schema::table('trusted_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('trusted_devices', 'enrollment_token')) {
                $table->string('enrollment_token', 64)->nullable()->unique()->after('status');
            }
            if (! Schema::hasColumn('trusted_devices', 'enrollment_token_expires_at')) {
                $table->timestamp('enrollment_token_expires_at')->nullable()->after('enrollment_token');
            }
        });

        // 4. Update existing admin user (User 1) to superadmin role
        DB::table('users')->where('id', 1)->update(['role' => 'superadmin', 'status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_login_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['category', 'action_type', 'target_user_id', 'target_user_email', 'outcome', 'severity']);
        });

        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropColumn(['enrollment_token', 'enrollment_token_expires_at']);
        });
    }
};
