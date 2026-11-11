<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeviceEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Role options in the User Management role filter, keyed by filter value.
     * Owner / Superadmin and User are not offered here; those accounts still appear under All Roles.
     *
     * @var array<string, string>
     */
    protected const ROLE_FILTERS = [
        'admin' => 'Administrator',
        'staff' => 'Staff',
    ];

    /**
     * Narrow a team member query to one role filter option.
     *
     * Administrator matches every account shown as "Administrator" in the list (admin, plus
     * legacy superadmin/owner accounts, which User::isAdmin() treats the same). Staff matches
     * every staff account, whatever modules it has (staff, staff_inventory, staff_sales, ...).
     */
    protected function applyRoleFilter(mixed $query, string $role): mixed
    {
        return match ($role) {
            'admin' => $query->whereIn('users.role', ['admin', 'superadmin', 'owner']),
            'staff' => $query->where(function ($q) {
                $q->where('users.role', 'staff')->orWhere('users.role', 'like', 'staff_%');
            }),
            default => $query,
        };
    }

    /**
     * Display centralized User Management screen.
     * Accessible to Owner/Superadmin and Admin.
     * Tabs:
     * - accounts (Superadmin & Admin)
     * - sessions (Superadmin only)
     * - devices (Superadmin only)
     * - audit (Superadmin only)
     * - security (Superadmin only)
     */
    public function index(Request $request): Response
    {
        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'User Management requires administrative privileges.');

        $team = $currentUser->currentTeam ?: $currentUser->teams()->first() ?: Team::first();
        abort_if(! $team, 403, 'No active team selected.');

        $activeTab = $request->input('tab', 'accounts');

        $search = trim((string) $request->input('search', ''));
        // Only the roles offered in the filter are accepted; anything else shows all roles.
        $roleFilter = strtolower((string) $request->input('role', ''));
        if (! array_key_exists($roleFilter, self::ROLE_FILTERS)) {
            $roleFilter = 'All Roles';
        }
        $statusFilter = $request->input('status', 'All Statuses');

        // 1. User Accounts Data (filtered, paginated list for the accounts table)
        $accountsData = null;
        $roleCounts = [];
        if ($activeTab === 'accounts') {
            // Members matching the search and status; the role filter is applied on top.
            $filteredMembers = function () use ($team, $search, $statusFilter) {
                $query = $team->members();

                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where('users.name', 'like', "%{$search}%")
                            ->orWhere('users.email', 'like', "%{$search}%");
                    });
                }

                if ($statusFilter && $statusFilter !== 'All Statuses') {
                    $query->where('users.status', strtolower($statusFilter));
                }

                return $query;
            };

            // Count per role option, following the current search and status.
            $roleCounts = ['All Roles' => $filteredMembers()->count()];
            foreach (array_keys(self::ROLE_FILTERS) as $roleKey) {
                $roleCounts[$roleKey] = $this->applyRoleFilter($filteredMembers(), $roleKey)->count();
            }

            $query = $filteredMembers()->withCount('trustedDevices')->with('modulePermissions');
            if ($roleFilter !== 'All Roles') {
                $this->applyRoleFilter($query, $roleFilter);
            }

            // Keep the page inside the filtered result so a narrower filter never lands on an empty page.
            $perPage = 15;
            $total = $roleCounts[$roleFilter];
            $page = min(max(1, (int) $request->input('page', 1)), max(1, (int) ceil($total / $perPage)));

            $accountsData = $query->orderBy('users.name')->paginate($perPage, ['*'], 'page', $page)->appends(array_filter([
                'tab' => 'accounts',
                'search' => $search !== '' ? $search : null,
                'role' => $roleFilter !== 'All Roles' ? $roleFilter : null,
                'status' => $statusFilter !== 'All Statuses' ? $statusFilter : null,
            ]))->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roleLabel(),
                'raw_role' => $user->role,
                'modules' => $user->isStaff() ? $user->assignedModules() : [],
                'status' => $user->status === 'pending_activation' ? 'Pending Activation' : ucfirst($user->status ?? 'active'),
                'raw_status' => $user->status ?? 'active',
                'is_active' => ($user->status ?? 'active') === 'active',
                'is_pending_activation' => $user->status === 'pending_activation',
                'must_change_password' => (bool) $user->must_change_password,
                'password_changed_at' => $user->password_changed_at?->format('M d, Y'),
                'last_login_at' => $user->last_login_at?->format('M d, Y H:i') ?? 'Never',
                'joined_at' => $user->pivot->created_at?->format('M d, Y') ?? $user->created_at?->format('M d, Y'),
                'trusted_devices_count' => $user->trusted_devices_count ?? 0,
                'is_superadmin' => false,
            ]);
        }

        // 2. Active Sessions Data (Admin accessible)
        $sessionsData = [];
        if ($activeTab === 'sessions') {
            $currentSessionId = $request->session()->getId();

            $sessionsQuery = DB::table('sessions')
                ->join('users', 'sessions.user_id', '=', 'users.id')
                ->select(
                    'sessions.id',
                    'sessions.user_id',
                    'sessions.ip_address',
                    'sessions.user_agent',
                    'sessions.last_activity',
                    'users.name as user_name',
                    'users.email as user_email',
                    'users.role as user_role'
                );

            if ($search) {
                $sessionsQuery->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('sessions.ip_address', 'like', "%{$search}%");
                });
            }

            $sessionsData = $sessionsQuery->orderByDesc('sessions.last_activity')->get()->map(function ($s) use ($currentSessionId) {
                $lastActivity = Carbon::createFromTimestamp($s->last_activity);
                $isExpired = $lastActivity->diffInMinutes(now()) >= config('session.lifetime', 120);
                $deviceDetails = $this->parseAgent($s->user_agent);

                return [
                    'id' => $s->id,
                    'masked_id' => 'sess_'.substr(hash('sha256', $s->id), 0, 12),
                    'user_id' => $s->user_id,
                    'user_name' => $s->user_name,
                    'user_email' => $s->user_email,
                    'user_role' => ucfirst($s->user_role),
                    'ip_address' => $s->ip_address,
                    'user_agent' => $s->user_agent,
                    'browser' => $deviceDetails['browser'],
                    'os' => $deviceDetails['os'],
                    'is_current' => $s->id === $currentSessionId,
                    'last_activity' => $lastActivity->diffForHumans(),
                    'last_activity_exact' => $lastActivity->format('M d, Y H:i:s'),
                    'is_expired' => $isExpired,
                    'status' => $isExpired ? 'Expired' : 'Active',
                ];
            });
        }

        // 3. Trusted Devices Data (Admin accessible - 1 Account = 1 Device)
        $devicesData = [];
        if ($activeTab === 'devices') {
            $devicesQuery = TrustedDevice::with('user:id,name,email,role');

            if ($search) {
                $devicesQuery->where(function ($q) use ($search) {
                    $q->where('device_name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            $currentCookieUuid = $request->cookie('mrpride_device_uuid') ?: $request->cookie('mrpride_device_id');
            $allStaffUsers = User::where(function ($q) {
                $q->whereIn('role', ['admin', 'superadmin', 'owner'])
                    ->orWhere('role', 'like', 'staff%');
            })->get(['id', 'name', 'email', 'role'])->keyBy('id');

            $devicesData = $devicesQuery->orderByDesc('last_used_at')->get()->map(function (TrustedDevice $d) use ($currentCookieUuid, $allStaffUsers) {
                $authorizedUsers = [];
                if ($d->is_shared && is_array($d->authorized_user_ids)) {
                    foreach ($d->authorized_user_ids as $uid) {
                        if ($u = $allStaffUsers->get($uid)) {
                            $authorizedUsers[] = [
                                'id' => $u->id,
                                'name' => $u->name,
                                'email' => $u->email,
                                'role' => $u->roleLabel(),
                            ];
                        }
                    }
                }

                // Codes and link tokens are stored hashed, so they can't be shown again here; the
                // raw values are shown once (and emailed) when the invitation is issued.
                $hasInvitation = (bool) ($d->enrollment_code || $d->enrollment_token);
                $isEnrollmentExpired = $hasInvitation && $d->enrollmentExpired();

                return [
                    'id' => $d->id,
                    'device_uuid' => $d->device_uuid,
                    'device_name' => $d->device_name,
                    'ip_address' => $d->ip_address,
                    'status' => ucfirst($d->status),
                    'raw_status' => $d->status,
                    'is_shared' => (bool) $d->is_shared,
                    'is_current' => $d->device_uuid === $currentCookieUuid,
                    'last_used_at' => $d->last_used_at?->diffForHumans() ?? 'Never',
                    'created_at' => $d->created_at?->format('M d, Y'),
                    'enrollment_code' => null,
                    'enrollment_link' => null,
                    'has_invitation' => $hasInvitation,
                    'is_enrollment_expired' => $isEnrollmentExpired,
                    'user' => $d->user ? [
                        'id' => $d->user->id,
                        'name' => $d->user->name,
                        'email' => $d->user->email,
                        'role' => $d->user->roleLabel(),
                    ] : null,
                    'authorized_users' => $authorizedUsers,
                ];
            });
        }

        // 4. Audit Trail Data (Admin accessible)
        $auditData = null;
        if ($activeTab === 'audit') {
            $auditQuery = AuditLog::where('category', 'audit')
                ->with(['user:id,name,email', 'targetUser:id,name,email']);

            if ($search) {
                $auditQuery->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%")
                        ->orWhere('target_user_email', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%");
                });
            }

            if ($request->input('event_filter')) {
                $auditQuery->where('event', $request->input('event_filter'));
            }

            if ($request->input('date_from')) {
                $auditQuery->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->input('date_to')) {
                $auditQuery->whereDate('created_at', '<=', $request->input('date_to'));
            }

            $auditData = $auditQuery->orderByDesc('created_at')->paginate(20)->withQueryString();
        }

        // 5. Security Logs Data (Admin accessible)
        $securityData = null;
        if ($activeTab === 'security') {
            $secQuery = AuditLog::where('category', 'security')
                ->with(['user:id,name,email']);

            if ($search) {
                $secQuery->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%");
                });
            }

            if ($request->input('severity')) {
                $secQuery->where('severity', $request->input('severity'));
            }

            if ($request->input('date_from')) {
                $secQuery->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->input('date_to')) {
                $secQuery->whereDate('created_at', '<=', $request->input('date_to'));
            }

            $securityData = $secQuery->orderByDesc('created_at')->paginate(20)->withQueryString();
        }

        // Every team member, unfiltered (used by the device enrollment pickers and other tabs).
        $usersList = $team->members()->with('modulePermissions')->withCount('trustedDevices')->orderBy('users.name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->roleLabel(),
            'raw_role' => $u->role,
            'modules' => $u->isStaff() ? $u->assignedModules() : [],
            'status' => $u->status === 'pending_activation' ? 'Pending Activation' : ucfirst($u->status ?? 'active'),
            'raw_status' => $u->status ?? 'active',
            'is_active' => ($u->status ?? 'active') === 'active',
            'is_pending_activation' => $u->status === 'pending_activation',
            'trusted_devices_count' => $u->trusted_devices_count ?? 0,
            'is_superadmin' => false,
        ]);

        return Inertia::render('Users/Index', [
            'activeTab' => $activeTab,
            'isSuperAdmin' => true,
            'users' => $usersList,
            'accounts' => $accountsData,
            'roleCounts' => $roleCounts,
            'roleFilterOptions' => collect(self::ROLE_FILTERS)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
            'sessions' => $sessionsData,
            'devices' => $devicesData,
            'auditLogs' => $auditData,
            'securityLogs' => $securityData,
            'adminRecoveryKey' => $currentUser->admin_recovery_key,
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
                'status' => $statusFilter,
                'event_filter' => $request->input('event_filter'),
                'severity' => $request->input('severity'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
            'roles' => [
                ['value' => 'admin', 'label' => 'Administrator (All Modules)'],
                ['value' => 'staff_inventory', 'label' => 'Staff - Inventory'],
                ['value' => 'staff_quotations', 'label' => 'Staff - Quotations'],
                ['value' => 'staff_procurement', 'label' => 'Staff - Procurement'],
                ['value' => 'staff_deliveries', 'label' => 'Staff - Deliveries'],
                ['value' => 'staff_sales', 'label' => 'Staff - Sales'],
                ['value' => 'staff_forecast', 'label' => 'Staff - Demand Forecasting'],
                ['value' => 'staff_suppliers', 'label' => 'Staff - Suppliers'],
                ['value' => 'staff_reports', 'label' => 'Staff - Reports & Analytics'],
            ],
            'moduleOptions' => collect(User::moduleOptions())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'temporaryPasswordNotice' => session('temporaryPasswordNotice'),
            'enrollmentNotice' => session('enrollmentNotice'),
        ]);
    }

    /**
     * Add a new user account.
     */
    public function store(Request $request): RedirectResponse
    {
        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'User Management requires administrative privileges.');

        $team = $currentUser->currentTeam ?: $currentUser->teams()->first() ?: Team::first();
        abort_if(! $team, 403, 'No active team selected.');

        $allowedRoles = [
            'admin',
            'staff_inventory',
            'staff_quotations',
            'staff_procurement',
            'staff_deliveries',
            'staff_sales',
            'staff_forecast',
            'staff_suppliers',
            'staff_reports',
            'staff',
            'user',
        ];

        [$role, $modules] = $this->resolveAccountAssignment($request, $allowedRoles);

        if ($request->filled('first_name') || $request->filled('last_name')) {
            $validated = $request->validate([
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ]);
            $fullName = trim($validated['first_name'].' '.$validated['last_name']);
        } else {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ]);
            $fullName = trim($validated['name']);
        }

        // Temporary bootstrap password in the agreed "Firstname1234" format (e.g. Juan1234).
        // It is guessable on purpose, so it is only usable together with a trusted device bound to
        // this account (which needs the emailed OTP), and it must be changed at first login.
        $firstName = $validated['first_name'] ?? Str::before($fullName, ' ');
        $rawPassword = User::initialPasswordFor($firstName);

        $user = User::create([
            'name' => $fullName,
            'email' => $validated['email'],
            'role' => $role,
            'status' => 'pending_activation',
            'password' => Hash::make($rawPassword),
            'must_change_password' => true,
            'password_changed_at' => now(),
            'email_verified_at' => now(),
        ]);

        $user->syncModules($modules);

        $teamRole = $role === 'admin' ? 'admin' : (str_starts_with($role, 'staff') ? 'staff' : 'member');
        $team->members()->attach($user->id, ['role' => $teamRole]);

        AuditLogger::audit(
            'account.created',
            "Administrator {$currentUser->email} created user {$user->email} with role '{$user->roleLabel()}'. Status set to Pending Activation. A temporary password (first name + 1234) was issued.",
            $currentUser,
            $user,
            ['role' => $user->role, 'status' => 'pending_activation', 'must_change_password' => true],
            'success',
            $request
        );

        $temporaryPasswordNotice = "Account created for {$fullName}. Status: Pending Activation. Initial temporary password: {$rawPassword}. They must change it at first login.";

        if (! $user->isAdminOrStaff()) {
            return back()
                ->with('success', "Account created (Pending Activation). Initial temporary password: {$rawPassword}")
                ->with('temporaryPasswordNotice', $temporaryPasswordNotice);
        }

        // Send the 5-minute OTP + enrollment link so they can register their device.
        $invite = app(DeviceEnrollmentService::class)->issueFor($user, $currentUser, null, $request);

        $response = back()
            ->with('temporaryPasswordNotice', $temporaryPasswordNotice)
            ->with('enrollmentNotice', $this->enrollmentNotice($user, $invite));

        return $invite['email_sent']
            ? $response->with('success', "Account created. Enrollment code and link were emailed to {$user->email} (valid for ".DeviceEnrollmentService::MINUTES.' minutes).')
            : $response->with('error', "Account created, but the enrollment email to {$user->email} could not be sent: {$invite['email_error']}. Give them the code and link shown below, or issue a new invitation.");
    }

    /**
     * Update user details and role.
     */
    public function update(Request $request, mixed $team, ?User $user = null): RedirectResponse
    {
        if ($team instanceof User) {
            $user = $team;
        }
        abort_if(! $user, 404, 'User not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'User Management requires administrative privileges.');

        // Prevent self-role modification
        if ($currentUser->id === $user->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        $allowedRoles = [
            'admin',
            'staff_inventory',
            'staff_quotations',
            'staff_procurement',
            'staff_deliveries',
            'staff_sales',
            'staff_forecast',
            'staff_suppliers',
            'staff_reports',
            'staff',
            'user',
        ];

        [$newRole, $newModules] = $this->resolveAccountAssignment($request, $allowedRoles);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ]);

        $name = $user->name;
        if (! empty($validated['first_name']) && ! empty($validated['last_name'])) {
            $name = trim($validated['first_name'].' '.$validated['last_name']);
        } elseif (! empty($validated['name'])) {
            $name = trim($validated['name']);
        }

        $oldRole = $user->role;
        $oldModules = $user->assignedModules();

        $user->update([
            'name' => $name,
            'role' => $newRole,
        ]);
        $user->syncModules($newModules);
        $modulesChanged = $user->isStaff() && $oldModules !== $user->assignedModules();

        $teamRole = $newRole === 'admin' ? 'admin' : (str_starts_with($newRole, 'staff') ? 'staff' : 'member');
        $currentUser->currentTeam?->members()->updateExistingPivot($user->id, ['role' => $teamRole]);

        // If role or module assignments changed, invalidate user's active sessions immediately
        if ($oldRole !== $newRole || $modulesChanged) {
            DB::table('sessions')->where('user_id', $user->id)->delete();

            AuditLogger::audit(
                'account.role_changed',
                "Administrator {$currentUser->email} changed role/modules of {$user->email} from '{$oldRole}' [".implode(', ', $oldModules)."] to '{$newRole}' [".implode(', ', $user->assignedModules()).']. Active sessions revoked.',
                $currentUser,
                $user,
                ['old_role' => $oldRole, 'new_role' => $newRole, 'old_modules' => $oldModules, 'new_modules' => $user->assignedModules()],
                'success',
                $request
            );
        } else {
            AuditLogger::audit(
                'account.updated',
                "Administrator {$currentUser->email} updated profile details for {$user->email}.",
                $currentUser,
                $user,
                null,
                'success',
                $request
            );
        }

        return back()->with('success', 'User account updated successfully.');
    }

    /**
     * Work out the account role and module assignments from the request.
     *
     * New format: `account_type` (admin|staff) plus `modules[]` for staff. The account role is stored as
     * staff_<first module> so every existing role check keeps working, while the full module list is
     * kept in user_module_permissions. Legacy format: a single `role` value, as before.
     *
     * @param  array<int, string>  $allowedRoles
     * @return array{0: string, 1: array<int, string>} [role, modules]
     */
    private function resolveAccountAssignment(Request $request, array $allowedRoles): array
    {
        if ($request->has('account_type')) {
            $request->validate([
                'account_type' => ['required', Rule::in(['admin', 'staff'])],
                'modules' => ['exclude_if:account_type,admin', 'required', 'array', 'min:1'],
                'modules.*' => ['string', 'distinct', Rule::in(array_keys(User::moduleOptions()))],
            ], [
                'modules.required' => 'Select at least one module for a staff account.',
                'modules.min' => 'Select at least one module for a staff account.',
            ]);

            if ($request->input('account_type') === 'admin') {
                return ['admin', []];
            }

            $modules = array_values($request->input('modules'));

            return ['staff_'.$modules[0], $modules];
        }

        $role = $request->validate([
            'role' => ['required', Rule::in($allowedRoles)],
        ])['role'];

        $legacyModule = User::legacyModuleForRole($role);

        return [$role, $legacyModule ? [$legacyModule] : []];
    }

    /**
     * Toggle account activation status (Active / Inactive).
     */
    public function toggleStatus(Request $request, mixed $team, ?User $user = null): RedirectResponse
    {
        if ($team instanceof User) {
            $user = $team;
        }
        abort_if(! $user, 404, 'User not found.');
        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Account activation control is restricted to Administrator.');

        if ($currentUser->id === $user->id) {
            return back()->withErrors(['status' => 'You cannot deactivate your own administrator account.']);
        }

        $newStatus = $user->isActive() ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        if ($newStatus === 'inactive') {
            // Revoke all sessions immediately
            DB::table('sessions')->where('user_id', $user->id)->delete();

            AuditLogger::audit(
                'account.deactivated',
                "Administrator {$currentUser->email} deactivated account {$user->email}. Active sessions revoked.",
                $currentUser,
                $user,
                ['status' => 'inactive'],
                'success',
                $request
            );
        } else {
            AuditLogger::audit(
                'account.activated',
                "Administrator {$currentUser->email} activated account {$user->email}.",
                $currentUser,
                $user,
                ['status' => 'active'],
                'success',
                $request
            );
        }

        return back()->with('success', "Account {$user->email} has been set to ".ucfirst($newStatus).'.');
    }

    /**
     * Remove user account and revoke their sessions.
     */
    public function destroy(Request $request, mixed $team, ?User $user = null): RedirectResponse
    {
        if ($team instanceof User) {
            $user = $team;
        }

        abort_if(! $user, 404, 'User not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'User Management requires administrative privileges.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }

        if ($user->id === $currentUser->id) {
            return back()->withErrors(['user' => 'You cannot remove your own administrator account.']);
        }

        // Invalidate all active sessions
        DB::table('sessions')->where('user_id', $user->id)->delete();

        // Revoke trusted devices
        $user->trustedDevices()->delete();

        $currentUser->currentTeam?->members()->detach($user->id);
        $user->delete();

        AuditLogger::audit(
            'account.deleted',
            "Administrator {$currentUser->email} deleted user {$user->email}. Sessions and devices terminated.",
            $currentUser,
            null,
            ['deleted_user_email' => $user->email],
            'success',
            $request
        );

        return back()->with('success', 'User removed and sessions revoked successfully.');
    }

    /**
     * Revoke an individual active session (Admin accessible).
     */
    public function revokeSession(Request $request, mixed $team, ?string $sessionId = null): RedirectResponse
    {
        $actualSessionId = $sessionId ?? (is_string($team) ? $team : null);
        abort_if(! $actualSessionId, 404, 'Session ID is required.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Revoking sessions is restricted to Administrator.');

        $sessionRecord = DB::table('sessions')->where('id', $actualSessionId)->first();
        if ($sessionRecord) {
            DB::table('sessions')->where('id', $actualSessionId)->delete();

            AuditLogger::audit(
                'session.revoked',
                "Administrator {$currentUser->email} revoked active session for user ID {$sessionRecord->user_id}.",
                $currentUser,
                null,
                ['session_id_masked' => substr(hash('sha256', $actualSessionId), 0, 10)],
                'success',
                $request
            );
        }

        return back()->with('success', 'Session revoked successfully.');
    }

    /**
     * Revoke all active sessions belonging to a specific user (Admin accessible).
     */
    public function revokeUserSessions(Request $request, mixed $team, ?User $user = null): RedirectResponse
    {
        if ($team instanceof User) {
            $user = $team;
        }
        abort_if(! $user, 404, 'User not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Revoking user sessions is restricted to Administrator.');

        $count = DB::table('sessions')->where('user_id', $user->id)->count();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLogger::audit(
            'session.revoked_all_for_user',
            "Administrator {$currentUser->email} terminated all {$count} active sessions for {$user->email}.",
            $currentUser,
            $user,
            ['revoked_count' => $count],
            'success',
            $request
        );

        return back()->with('success', "All {$count} active session(s) for {$user->name} have been revoked.");
    }

    /**
     * Issue (or re-issue after expiry) a device enrollment invitation for one account.
     *
     * One device, one account: invitations are always for a single account, and a new one
     * replaces that account's previous device. Every device must go through enrollment (link +
     * OTP from the user's email) before it is trusted; there is no "approve without enrolling".
     */
    public function enrollDevice(Request $request): RedirectResponse
    {
        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Device enrollment is restricted to Administrator.');

        $validated = $request->validate([
            'assignment_type' => ['nullable', 'string', Rule::in(['single'])],
            'user_id' => ['required', 'exists:users,id'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'assignment_type.in' => 'Shared workstations are not allowed: each device can be registered to one account only.',
        ]);

        $targetUser = User::findOrFail($validated['user_id']);
        abort_if(! $targetUser->isAdminOrStaff(), 422, 'Trusted devices are only applicable to Admin and Staff accounts.');

        $invite = app(DeviceEnrollmentService::class)->issueFor($targetUser, $currentUser, $validated['device_name'] ?? null, $request);

        $response = back()->with('enrollmentNotice', $this->enrollmentNotice($targetUser, $invite));

        return $invite['email_sent']
            ? $response->with('success', "Enrollment code and link emailed to {$targetUser->email}. They expire in ".DeviceEnrollmentService::MINUTES.' minutes.')
            : $response->with('error', "The enrollment email to {$targetUser->email} could not be sent: {$invite['email_error']}. Give them the code and link shown below, or try again.");
    }

    /**
     * One-time admin view of a freshly issued invitation (the raw code/link are not stored).
     *
     * @param  array{device: TrustedDevice, code: string, link: string, expires_at: Carbon, email_sent: bool, email_error: ?string}  $invite
     */
    private function enrollmentNotice(User $user, array $invite): array
    {
        return [
            'device_name' => $invite['device']->device_name,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'code' => $invite['code'],
            'link' => $invite['link'],
            'expires_at' => $invite['expires_at']->format('h:i A').' ('.DeviceEnrollmentService::MINUTES.' minutes only)',
            'expires_in_minutes' => DeviceEnrollmentService::MINUTES,
            'email_sent' => $invite['email_sent'],
        ];
    }

    /**
     * Rename a trusted device (Admin accessible).
     */
    public function renameDevice(Request $request, mixed $team, ?TrustedDevice $device = null): RedirectResponse
    {
        if ($team instanceof TrustedDevice) {
            $device = $team;
        }
        abort_if(! $device, 404, 'Device not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Renaming devices is restricted to Administrator.');

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $oldName = $device->device_name;
        $device->update(['device_name' => trim($validated['device_name'])]);

        AuditLogger::audit(
            'device.renamed',
            "Administrator {$currentUser->email} renamed device #{$device->id} from '{$oldName}' to '{$device->device_name}'.",
            $currentUser,
            $device->user,
            ['device_id' => $device->id, 'old_name' => $oldName, 'new_name' => $device->device_name],
            'success',
            $request
        );

        return back()->with('success', "Device successfully renamed to '{$device->device_name}'.");
    }

    /**
     * Revoke a trusted device (Admin accessible).
     * Immediately invalidates device access and active user sessions.
     */
    public function revokeDevice(Request $request, mixed $team, ?TrustedDevice $device = null): RedirectResponse
    {
        if ($team instanceof TrustedDevice) {
            $device = $team;
        }
        abort_if(! $device, 404, 'Device not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Revoking devices is restricted to Administrator.');

        $device->update([
            'status' => 'revoked',
            'credential_hash' => null, // clear hash so credential immediately fails verification
        ]);

        // Revoke active sessions for the device's user(s) to enforce immediate lockout
        if ($device->user_id) {
            DB::table('sessions')->where('user_id', $device->user_id)->delete();
        }
        if ($device->is_shared && is_array($device->authorized_user_ids)) {
            DB::table('sessions')->whereIn('user_id', $device->authorized_user_ids)->delete();
        }

        AuditLogger::audit(
            'device.revoked',
            "Administrator {$currentUser->email} revoked device '{$device->device_name}' ({$device->device_uuid}). Workstation access immediately terminated.",
            $currentUser,
            $device->user,
            ['device_name' => $device->device_name, 'device_uuid' => $device->device_uuid],
            'success',
            $request
        );

        return back()->with('success', "Device '{$device->device_name}' revoked successfully. Workstation access is now blocked.");
    }

    /**
     * Approve a pending trusted device (Admin accessible).
     */
    public function approveDevice(Request $request, mixed $team, ?TrustedDevice $device = null): RedirectResponse
    {
        if ($team instanceof TrustedDevice) {
            $device = $team;
        }
        abort_if(! $device, 404, 'Device not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Approving devices is restricted to Administrator.');

        // For single-user devices, ensure 1 active device policy
        if ($device->user_id && ! $device->is_shared) {
            TrustedDevice::where('user_id', $device->user_id)
                ->where('id', '!=', $device->id)
                ->where('is_shared', false)
                ->delete();
        }

        $device->update([
            'status' => 'approved',
            'approved_by' => $currentUser->id,
            'approved_at' => now(),
            'last_used_at' => now(),
        ]);

        AuditLogger::audit(
            'device.approved',
            "Administrator {$currentUser->email} approved device '{$device->device_name}'.",
            $currentUser,
            $device->user,
            ['device_name' => $device->device_name],
            'success',
            $request
        );

        return back()->with('success', "Device '{$device->device_name}' approved successfully.");
    }

    /**
     * Delete a trusted device (Admin accessible).
     */
    public function destroyDevice(Request $request, mixed $team, ?TrustedDevice $device = null): RedirectResponse
    {
        if ($team instanceof TrustedDevice) {
            $device = $team;
        }
        abort_if(! $device, 404, 'Device not found.');

        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Deleting devices is restricted to Administrator.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }

        $deviceName = $device->device_name;
        if ($device->user_id) {
            DB::table('sessions')->where('user_id', $device->user_id)->delete();
        }
        if ($device->is_shared && is_array($device->authorized_user_ids)) {
            DB::table('sessions')->whereIn('user_id', $device->authorized_user_ids)->delete();
        }
        $device->delete();

        AuditLogger::audit(
            'device.deleted',
            "Administrator {$currentUser->email} removed device '{$deviceName}'.",
            $currentUser,
            null,
            ['device_name' => $deviceName],
            'success',
            $request
        );

        return back()->with('success', 'Device record removed successfully.');
    }

    /**
     * Regenerate Emergency Admin Recovery Key (Admin accessible).
     */
    public function regenerateRecoveryKey(Request $request): RedirectResponse
    {
        $currentUser = $request->user();
        abort_if(! $currentUser || ! $currentUser->isAdmin(), 403, 'Recovery key management is restricted to Administrator.');

        $newKey = 'RECOVER-'.strtoupper(Str::random(6)).'-'.strtoupper(Str::random(6)).'-'.strtoupper(Str::random(6));
        $currentUser->update(['admin_recovery_key' => $newKey]);

        AuditLogger::audit(
            'security.recovery_key_regenerated',
            "Administrator {$currentUser->email} regenerated their emergency admin recovery key.",
            $currentUser,
            $currentUser,
            null,
            'success',
            $request
        );

        return back()->with('success', "Emergency Admin Recovery Key regenerated: {$newKey}");
    }

    /**
     * Parse browser and operating system details from User-Agent string.
     */
    protected function parseAgent(?string $agent): array
    {
        if (! $agent) {
            return ['browser' => 'Unknown', 'os' => 'Unknown'];
        }

        $os = 'Unknown OS';
        if (str_contains($agent, 'Windows NT 10.0')) {
            $os = 'Windows 10/11';
        } elseif (str_contains($agent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X')) {
            $os = 'macOS';
        } elseif (str_contains($agent, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($agent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) {
            $os = 'iOS';
        }

        $browser = 'Unknown Browser';
        if (str_contains($agent, 'Edg/')) {
            $browser = 'Microsoft Edge';
        } elseif (str_contains($agent, 'Chrome/')) {
            $browser = 'Google Chrome';
        } elseif (str_contains($agent, 'Firefox/')) {
            $browser = 'Mozilla Firefox';
        } elseif (str_contains($agent, 'Safari/') && ! str_contains($agent, 'Chrome/')) {
            $browser = 'Apple Safari';
        } elseif (str_contains($agent, 'Opera') || str_contains($agent, 'OPR/')) {
            $browser = 'Opera';
        }

        return ['browser' => $browser, 'os' => $os];
    }
}
