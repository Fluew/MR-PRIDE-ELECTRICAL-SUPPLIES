<?php

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MaintenanceService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Ensure clean maintenance state before each test
    if (MaintenanceService::isDown()) {
        $admin = User::factory()->create(['role' => 'admin']);
        MaintenanceService::disable($admin);
    }
});

afterEach(function () {
    if (MaintenanceService::isDown()) {
        $admin = User::where('role', 'admin')->first() ?: User::factory()->create(['role' => 'admin']);
        MaintenanceService::disable($admin);
    }
});

test('admin can access maintenance settings', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);

    $response = $this->actingAs($admin)->withCookies(createTrustedDeviceFor($admin)['cookies'])->get('/settings/maintenance');
    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/Maintenance')
        ->has('status')
    );
});

test('non-admin user is rejected from maintenance settings', function () {
    $staff = User::factory()->create([
        'role' => 'staff_inventory',
        'password_changed_at' => now(),
    ]);

    $this->actingAs($staff)->get('/settings/maintenance')->assertNotFound();
});

test('admin can access all modules under Settings -> System Maintenance while staff with verified device is forbidden', function () {
    $team = Team::factory()->create(['slug' => 'maint-team']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $adminCookies = createTrustedDeviceFor($admin)['cookies'];

    $staff = User::factory()->create([
        'role' => 'staff_inventory',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($staff, ['role' => 'member']);
    $staffCookies = createTrustedDeviceFor($staff)['cookies'];

    // Admin can access all System Maintenance routes
    $this->actingAs($admin)->withCookies($adminCookies)
        ->get('/settings/maintenance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Maintenance'));

    $this->actingAs($admin)->withCookies($adminCookies)
        ->get('/settings/maintenance/recycle-bin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('RecycleBin/Index'));

    $this->actingAs($admin)->withCookies($adminCookies)
        ->get('/settings/maintenance/security-logs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Users/Index')->where('activeTab', 'security'));

    $this->actingAs($admin)->withCookies($adminCookies)
        ->get('/settings/maintenance/audit-trail')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Users/Index')->where('activeTab', 'audit'));

    // Staff can still access Profile and Security (Change Password)
    $this->actingAs($staff)->withCookies($staffCookies)->get('/settings/profile')->assertOk();
    $this->actingAs($staff)->withCookies($staffCookies)->get('/settings/security')->assertOk();

    // Staff cannot access any System Maintenance pages or API endpoints via direct URLs or JSON requests
    foreach ([
        '/settings/maintenance',
        '/settings/maintenance/recycle-bin',
        '/settings/maintenance/security-logs',
        '/settings/maintenance/audit-trail',
        "/{$team->slug}/recycle-bin",
        "/{$team->slug}/users?tab=security",
        "/{$team->slug}/users?tab=audit",
    ] as $url) {
        $this->actingAs($staff)->withCookies($staffCookies)->get($url)->assertForbidden();
        $this->actingAs($staff)->withCredentials()->withCookies($staffCookies)->getJson($url)->assertForbidden();
    }

    $this->actingAs($staff)->withCredentials()->withCookies($staffCookies)->postJson('/settings/maintenance/enable')->assertForbidden();
    $this->actingAs($staff)->withCredentials()->withCookies($staffCookies)->postJson('/settings/maintenance/disable')->assertForbidden();
    $this->actingAs($staff)->withCredentials()->withCookies($staffCookies)->postJson('/settings/maintenance/verify')->assertForbidden();
    $this->actingAs($staff)->withCredentials()->withCookies($staffCookies)->postJson('/settings/maintenance/recycle-bin/empty')->assertForbidden();
});

test('admin can enable maintenance mode, show maintenance page to guests, and verify functions before reopening', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);

    // Step 1: Admin enables maintenance mode
    $enableResponse = $this->actingAs($admin)->withCookies(createTrustedDeviceFor($admin)['cookies'])->post('/settings/maintenance/enable', [
        'reason' => 'Database updates & system upgrades in progress.',
        'duration_minutes' => 30,
        'expected_return_time' => '02:00 PM (approx. 30 mins)',
    ]);

    $enableResponse->assertSessionHas('success');
    expect(MaintenanceService::isDown())->toBeTrue();

    // Verify audit log recorded
    $log = AuditLog::where('event', 'system.maintenance.enabled')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($admin->id);

    // Step 3: Guest / customer / staff receives 503 Maintenance page
    auth()->logout();
    $guestResponse = $this->get('/');
    $guestResponse->assertStatus(503);
    $guestResponse->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance')
        ->where('title', 'System Under Maintenance')
        ->where('message', 'MRP ElectroDesk is temporarily unavailable while we perform system maintenance.')
        ->where('reason', 'Database updates & system upgrades in progress.')
        ->where('expectedReturnTime', '02:00 PM (approx. 30 mins)')
    );

    // API / JSON request receives 503 JSON payload
    $apiResponse = $this->getJson('/');
    $apiResponse->assertStatus(503);
    $apiResponse->assertJsonFragment([
        'title' => 'System Under Maintenance',
        'message' => 'MRP ElectroDesk is temporarily unavailable while we perform system maintenance.',
    ]);

    // Admin can still access maintenance settings to monitor and test
    $adminAccess = $this->actingAs($admin)->withCookies(createTrustedDeviceFor($admin)['cookies'])->get('/settings/maintenance');
    $adminAccess->assertSuccessful();

    // Step 4: Admin runs pre-flight system readiness verification
    $verifyResponse = $this->actingAs($admin)->withCredentials()->withCookies(createTrustedDeviceFor($admin)['cookies'])->postJson('/settings/maintenance/verify');
    $verifyResponse->assertSuccessful();
    $verifyResponse->assertJsonStructure([
        'all_passed',
        'verified_at',
        'tests' => [
            '*' => ['id', 'title', 'status', 'message'],
        ],
    ]);
    expect($verifyResponse->json('all_passed'))->toBeTrue();

    // Admin reopens system / disables maintenance mode
    $disableResponse = $this->actingAs($admin)->withCookies(createTrustedDeviceFor($admin)['cookies'])->post('/settings/maintenance/disable');
    $disableResponse->assertSessionHas('success');
    expect(MaintenanceService::isDown())->toBeFalse();

    // System is back to normal operations
    auth()->logout();
    $this->get('/')->assertSuccessful();
});

test('admin can run verification using GET or POST when system is under maintenance', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);

    // Enable maintenance mode
    MaintenanceService::enable(['reason' => 'Testing verification under maintenance'], $admin);
    expect(MaintenanceService::isDown())->toBeTrue();

    // Verify GET request works under maintenance
    $getResponse = $this->actingAs($admin)->withCredentials()->withCookies(createTrustedDeviceFor($admin)['cookies'])->getJson('/settings/maintenance/verify');
    $getResponse->assertSuccessful();
    $getResponse->assertJsonPath('all_passed', true);
    expect($getResponse->json('tests'))->toHaveCount(6);

    // Verify POST request works under maintenance
    $postResponse = $this->actingAs($admin)->withCredentials()->withCookies(createTrustedDeviceFor($admin)['cookies'])->postJson('/settings/maintenance/verify');
    $postResponse->assertSuccessful();
    $postResponse->assertJsonPath('all_passed', true);
});

test('admin can schedule maintenance with hours and minutes, auto-calculate return time, override return time, and record all actions in audit trail', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $adminCookies = createTrustedDeviceFor($admin)['cookies'];

    // 1. Validate that minutes > 59 is rejected
    $this->actingAs($admin)->withCookies($adminCookies)
        ->post('/settings/maintenance/enable', [
            'reason' => 'Scheduled maintenance test',
            'scheduled_start_at' => now()->addMinutes(10)->format('Y-m-d\TH:i'),
            'duration_hours' => 1,
            'duration_minutes' => 60,
        ])
        ->assertSessionHasErrors(['duration_minutes']);

    // 2. Validate that total duration of 0h 0m is rejected
    $this->actingAs($admin)->withCookies($adminCookies)
        ->post('/settings/maintenance/enable', [
            'reason' => 'Scheduled maintenance test',
            'scheduled_start_at' => now()->addMinutes(10)->format('Y-m-d\TH:i'),
            'duration_hours' => 0,
            'duration_minutes' => 0,
        ])
        ->assertSessionHasErrors(['duration_minutes']);

    // 3. Validate that a past scheduled start date is rejected
    $this->actingAs($admin)->withCookies($adminCookies)
        ->post('/settings/maintenance/enable', [
            'reason' => 'Past schedule test',
            'scheduled_start_at' => now()->subHours(2)->format('Y-m-d\TH:i'),
            'duration_hours' => 1,
            'duration_minutes' => 15,
        ])
        ->assertSessionHasErrors(['scheduled_start_at']);

    // 4. Enable with Scheduled Start + Hours (1h) + Minutes (45m) and auto-calculated expected return time
    $scheduledStart = now()->addMinutes(30)->startOfMinute();
    $expectedEnd = $scheduledStart->copy()->addMinutes(105);

    $this->actingAs($admin)->withCookies($adminCookies)
        ->post('/settings/maintenance/enable', [
            'reason' => 'Planned database index optimization.',
            'scheduled_start_at' => $scheduledStart->format('Y-m-d\TH:i'),
            'duration_hours' => 1,
            'duration_minutes' => 45,
            'expected_return_time' => '',
            'is_manual_override' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $status = MaintenanceService::getStatus();
    expect($status['enabled'])->toBeTrue()
        ->and($status['duration_hours'])->toBe(1)
        ->and($status['duration_minutes'])->toBe(45)
        ->and($status['duration_total_minutes'])->toBe(105)
        ->and($status['is_manual_override'])->toBeFalse()
        ->and($status['expected_return_time'])->toContain($expectedEnd->format('M d, Y h:i A'))
        ->and($status['expected_return_time'])->toContain('1 hour 45 minutes');

    // Verify both scheduling and activation are recorded in the System Audit Trail
    expect(AuditLog::where('event', 'system.maintenance.scheduled')->where('user_id', $admin->id)->exists())->toBeTrue();
    expect(AuditLog::where('event', 'system.maintenance.enabled')->where('user_id', $admin->id)->exists())->toBeTrue();

    // 5. Update with manual override for Expected Return Time
    $this->actingAs($admin)->withCookies($adminCookies)
        ->post('/settings/maintenance/enable', [
            'reason' => 'Extended upgrade window.',
            'scheduled_start_at' => $scheduledStart->format('Y-m-d\TH:i'),
            'duration_hours' => 2,
            'duration_minutes' => 0,
            'expected_return_time' => 'Custom Override: Tomorrow at 08:00 AM',
            'is_manual_override' => true,
        ])
        ->assertSessionHasNoErrors();

    $updatedStatus = MaintenanceService::getStatus();
    expect($updatedStatus['expected_return_time'])->toBe('Custom Override: Tomorrow at 08:00 AM')
        ->and($updatedStatus['is_manual_override'])->toBeTrue()
        ->and($updatedStatus['duration_hours'])->toBe(2)
        ->and($updatedStatus['duration_minutes'])->toBe(0);

    // 6. Staff user is blocked with 503 during maintenance while Admin still has full access
    $staff = User::factory()->create([
        'role' => 'staff_inventory',
        'password_changed_at' => now(),
    ]);
    $staffCookies = createTrustedDeviceFor($staff)['cookies'];

    $this->actingAs($staff)->withCookies($staffCookies)->get('/dashboard')->assertStatus(503);
    $this->actingAs($admin)->withCookies($adminCookies)->get('/settings/maintenance')->assertOk();

    // 7. Admin immediately disables maintenance mode and deactivation is recorded in Audit Trail
    $this->actingAs($admin)->withCookies($adminCookies)
        ->post('/settings/maintenance/disable')
        ->assertSessionHas('success');

    expect(MaintenanceService::isDown())->toBeFalse();
    expect(AuditLog::where('event', 'system.maintenance.disabled')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('reports module includes Recent Activity Feed sourced from System Audit Trail with module and date range filters', function () {
    $team = Team::factory()->create(['slug' => 'mr-pride']);

    $admin = User::factory()->create([
        'name' => 'Admin Tester',
        'email' => 'admin.reports@mrpride.local',
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $adminCookies = createTrustedDeviceFor($admin)['cookies'];

    // Record sample audit events across modules
    AuditLogger::audit('sale.created', 'Created sales order SO-2026-0999.', $admin, null, ['order_number' => 'SO-2026-0999']);
    AuditLogger::audit('product.created', 'Created product Breaker 20A (BRK-20A).', $admin, null, ['sku' => 'BRK-20A']);
    AuditLogger::audit('purchase_request.created', 'Created purchase request PR-2026-0100.', $admin, null, ['request_number' => 'PR-2026-0100']);
    AuditLogger::audit('supplier.created', 'Created supplier Apex Electrical.', $admin, null, ['name' => 'Apex Electrical']);
    AuditLogger::audit('user.created', 'Created user account staff@mrpride.local.', $admin, null, ['label' => 'staff@mrpride.local']);
    AuditLogger::audit('system.maintenance.enabled', 'Enabled system maintenance mode.', $admin, null, ['reason' => 'Routine check']);

    $response = $this->actingAs($admin)->withCookies($adminCookies)->get('/mr-pride/reports');
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index')
        ->has('activityFeed')
        ->has('activityFeed.items')
        ->where('activityFeed.modules', [
            'Sales',
            'Inventory',
            'Procurement',
            'Suppliers',
            'User Management',
            'System Maintenance',
        ])
    );

    // Filter by module (Sales) and date range
    $filteredResponse = $this->actingAs($admin)->withCookies($adminCookies)->get('/mr-pride/reports?activity_module=Sales&activity_from='.now()->toDateString().'&activity_to='.now()->toDateString());
    $filteredResponse->assertOk();
    $filteredResponse->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index')
        ->where('activityFeed.items.0.module', 'Sales')
        ->where('activityFeed.items.0.reference', 'SO-2026-0999')
        ->where('activityFeed.items.0.user', 'Admin Tester')
    );

    // Export Recent Activity Feed CSV
    $exportResponse = $this->actingAs($admin)->withCookies($adminCookies)
        ->get('/mr-pride/reports/export/activity-feed?module=Sales');
    $exportResponse->assertOk();
    expect($exportResponse->headers->get('content-type'))->toContain('text/csv');
});

test('unregistered workstation accessing protected system receives 404 and logs threat event', function () {
    $response = $this->get('/login');
    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page->component('NotFound'));

    $threatLog = AuditLog::where('event', 'auth.threat.unregistered_device')->latest()->first();
    expect($threatLog)->not->toBeNull();
    expect($threatLog->outcome)->toBe('blocked');
    expect($threatLog->category)->toBe('security');
});
