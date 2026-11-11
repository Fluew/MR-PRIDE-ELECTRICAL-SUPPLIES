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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('user')->index()->after('email');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            $table->text('password_history')->nullable()->after('password_changed_at');
            $table->string('admin_recovery_key', 64)->nullable()->after('password_history');
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_uuid', 64)->index();
            $table->string('device_name', 255);
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('verification_code', 64)->nullable();
            $table->timestamp('verification_code_expires_at')->nullable();
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_uuid']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_email', 255)->nullable()->index();
            $table->string('event', 64)->index();
            $table->text('description');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Set initial roles and timestamps for existing users
        DB::table('users')->where('id', 1)->update([
            'role' => 'admin',
            'must_change_password' => false,
            'password_changed_at' => now(),
            'admin_recovery_key' => 'RECOVER-MRPRIDE-ADMIN-INITIAL-KEY-2026',
        ]);

        DB::table('users')->where('id', 2)->update([
            'role' => 'staff',
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('trusted_devices');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'must_change_password',
                'password_changed_at',
                'password_history',
                'admin_recovery_key',
            ]);
        });
    }
};
