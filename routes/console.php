<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('db:backup')->dailyAt('02:00')->description('Daily database backup');

Schedule::call(function () {
    \App\Models\AuditLog::where('created_at', '<', now()->subMonths(6))->delete();
})->weekly()->description('Prune old audit log entries');

Artisan::command('device:enroll-code {--user= : User ID or email} {--name= : Device name} {--shared : Allow shared workstation} {--minutes=5 : Expiration in minutes}', function () {
    $userId = $this->option('user');
    $user = null;
    if ($userId) {
        $user = is_numeric($userId)
            ? \App\Models\User::find($userId)
            : \App\Models\User::where('email', $userId)->first();

        if (! $user) {
            $this->error("User not found: {$userId}");
            return 1;
        }
    }

    $isShared = (bool) $this->option('shared');
    $deviceName = $this->option('name') ?: ($user ? "{$user->name}'s Workstation" : 'Workstation');
    $minutes = (int) $this->option('minutes') ?: 5;
    $code = \App\Models\TrustedDevice::generateEnrollmentCode();
    $token = \Illuminate\Support\Str::random(48);
    $expiresAt = now()->addMinutes($minutes);

    $device = \App\Models\TrustedDevice::create([
        'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'device_name' => $deviceName,
        'user_id' => $user?->id,
        'status' => 'pending',
        'is_shared' => $isShared,
        'authorized_user_ids' => $user && $isShared ? [$user->id] : null,
        'enrollment_code' => \App\Models\TrustedDevice::hashSecret($code),
        'enrollment_code_expires_at' => $expiresAt,
        'enrollment_token' => \App\Models\TrustedDevice::hashSecret($token),
        'enrollment_token_expires_at' => $expiresAt,
    ]);

    $baseUrl = config('app.url', 'http://localhost:8000');
    $oneClickUrl = rtrim($baseUrl, '/') . "/device/enroll/{$token}";

    $this->info("==================================================");
    $this->info(" Enrollment Code: {$code}");
    $this->info(" One-Time Link:   {$oneClickUrl}");
    $this->info(" Device Name:     {$deviceName}");
    $this->info(" Assigned User:   " . ($user ? "{$user->name} ({$user->email})" : 'None (Shared/Unassigned)'));
    $this->info(" Mode:            " . ($isShared ? 'Shared Workstation' : 'Single User Binding'));
    $this->info(" Expires:         " . $expiresAt->toDateTimeString() . " ({$minutes} mins)");
    $this->info("==================================================");
    $this->comment("Open in the browser on the target device within {$minutes} minutes:");
    $this->line("{$oneClickUrl}");
    $this->info("==================================================");

    return 0;
})->purpose('Generate a single-use trusted device enrollment code (5 minutes default)');

Artisan::command('device:list', function () {
    $devices = \App\Models\TrustedDevice::with('user', 'approver')->get();
    if ($devices->isEmpty()) {
        $this->warn('No devices registered.');
        return 0;
    }

    $rows = $devices->map(fn ($d) => [
        'ID' => $d->id,
        'Name' => $d->device_name,
        'Status' => $d->status,
        'Assigned User' => $d->user?->name ?: ($d->is_shared ? 'Shared (' . count($d->authorized_user_ids ?? []) . ' users)' : 'None'),
        'Shared' => $d->is_shared ? 'Yes' : 'No',
        'Last Verified' => $d->last_verified_at?->diffForHumans() ?: 'Never',
        'Active Credential' => ! empty($d->credential_hash) ? 'Yes' : 'No',
    ]);

    $this->table(['ID', 'Name', 'Status', 'Assigned User', 'Shared', 'Last Verified', 'Active Credential'], $rows);
    return 0;
})->purpose('List registered and pending devices');

Artisan::command('device:trust-admin {--name=Administrator Laptop : Name for the workstation}', function () {
    $adminUsers = \App\Models\User::where('role', 'admin')->get();
    if ($adminUsers->isEmpty()) {
        $this->error('No administrator accounts found in the database.');
        return 1;
    }

    $adminIds = $adminUsers->pluck('id')->all();
    $firstAdmin = $adminUsers->first();
    $token = \Illuminate\Support\Str::random(48);
    $code = 'MRP-ADMIN';
    $deviceName = (string) ($this->option('name') ?: 'Administrator Laptop');

    // Remove any stale pending admin registration
    \App\Models\TrustedDevice::where('enrollment_code', $code)->delete();

    $device = \App\Models\TrustedDevice::create([
        'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'device_name' => $deviceName,
        'user_id' => $firstAdmin->id,
        'status' => 'pending',
        'is_shared' => true,
        'authorized_user_ids' => $adminIds,
        'enrollment_code' => $code,
        'enrollment_code_expires_at' => now()->addDays(30),
        'enrollment_token' => $token,
        'enrollment_token_expires_at' => now()->addDays(30),
    ]);

    $baseUrl = config('app.url', 'http://localhost:8000');
    $oneClickUrl = rtrim($baseUrl, '/') . "/device/enroll/{$token}";
    $enrollPageUrl = rtrim($baseUrl, '/') . "/device/enroll?code={$code}";

    $this->info("======================================================================");
    $this->info("           ADMINISTRATOR WORKSTATION ENROLLMENT GENERATED             ");
    $this->info("======================================================================");
    $this->line("<comment>Device Label:</comment>       {$deviceName}");
    $this->line("<comment>Authorized Admins:</comment>  " . $adminUsers->pluck('email')->implode(', '));
    $this->line("<comment>Enrollment Code:</comment>    <info>{$code}</info>");
    $this->line("<comment>Valid Until:</comment>        " . now()->addDays(30)->toDateTimeString() . " (30 days)");
    $this->newLine();
    $this->comment("OPTION 1 (Instant 1-Click Link):");
    $this->line("  Open this URL directly in your browser on this laptop:");
    $this->info("  {$oneClickUrl}");
    $this->newLine();
    $this->comment("OPTION 2 (Enrollment Code):");
    $this->line("  Go to <info>{$enrollPageUrl}</info> and click 'Authorize This Device'");
    $this->info("======================================================================");

    return 0;
})->purpose('Generate an instant trusted device authorization link/code for Administrators');

Artisan::command('device:recover {email? : Administrator email} {key? : Emergency recovery key}', function () {
    $email = (string) ($this->argument('email') ?: $this->ask('Enter Administrator Email:'));
    $user = \App\Models\User::where('email', strtolower(trim($email)))->first();

    if (! $user || ! $user->isAdmin()) {
        $this->error("No administrator account found with email [{$email}].");
        return 1;
    }

    $key = (string) ($this->argument('key') ?: $this->ask('Enter Emergency Admin Recovery Key:'));
    $cleanKey = strtoupper(trim($key));

    if (empty($user->admin_recovery_key) || ! hash_equals($user->admin_recovery_key, $cleanKey)) {
        $this->error("Invalid recovery key for administrator [{$user->email}].");
        return 1;
    }

    // Rotate the key (single-use)
    $newKey = 'RECOVER-'.strtoupper(\Illuminate\Support\Str::random(6)).'-'.strtoupper(\Illuminate\Support\Str::random(6)).'-'.strtoupper(\Illuminate\Support\Str::random(6));
    $user->update(['admin_recovery_key' => $newKey]);

    // Issue a 7-day device enrollment token for this admin
    $token = \Illuminate\Support\Str::random(48);
    $deviceUuid = (string) \Illuminate\Support\Str::uuid();
    $deviceName = 'Admin Workstation (Emergency Recovery)';

    // Clean up any pending recovery invitations
    \App\Models\TrustedDevice::where('user_id', $user->id)
        ->where('status', 'pending')
        ->delete();

    $device = \App\Models\TrustedDevice::create([
        'user_id' => $user->id,
        'device_uuid' => $deviceUuid,
        'device_name' => $deviceName,
        'status' => 'pending',
        'is_shared' => false,
        'enrollment_token' => \App\Models\TrustedDevice::hashSecret($token),
        'enrollment_token_expires_at' => now()->addDays(7),
        'enrollment_code' => \App\Models\TrustedDevice::hashSecret('RECOVERED'),
        'enrollment_code_expires_at' => now()->addDays(7),
    ]);

    $baseUrl = config('app.url', 'http://localhost:8000');
    $recoverUrl = rtrim($baseUrl, '/') . "/device/recover";
    $oneClickUrl = rtrim($baseUrl, '/') . "/device/enroll/{$token}?email=" . urlencode($user->email);

    $this->info("======================================================================");
    $this->info("             EMERGENCY ADMIN DEVICE RECOVERY VERIFIED                 ");
    $this->info("======================================================================");
    $this->line("<comment>Administrator:</comment>       {$user->name} ({$user->email})");
    $this->line("<comment>Previous Key:</comment>        {$cleanKey} (Burned / Used)");
    $this->line("<comment>New Recovery Key:</comment>    <info>{$newKey}</info> (Keep this safe!)");
    $this->newLine();
    $this->comment("HOW TO AUTHORIZE YOUR BROWSER:");
    $this->line("Option A (Browser Portal):");
    $this->line("  Go to <info>{$recoverUrl}</info> on any device and enter your credentials.");
    $this->newLine();
    $this->line("Option B (Direct 1-Click Enrollment):");
    $this->line("  Open this link on your target workstation browser:");
    $this->info("  {$oneClickUrl}");
    $this->info("======================================================================");

    return 0;
})->purpose('Verify Emergency Admin Recovery Key and generate device access');



