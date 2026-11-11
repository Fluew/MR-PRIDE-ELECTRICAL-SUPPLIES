<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string|null $preferred_currency USD or PHP, display only (App\Support\Currency)
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $must_change_password
 * @property Carbon|null $password_changed_at
 * @property array|null $password_history
 * @property string|null $admin_recovery_key
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, TrustedDevice> $trustedDevices
 * @property-read Collection<int, AuditLog> $auditLogs
 */
#[Fillable([
    'name',
    'email',
    'role',
    'status',
    'password',
    'current_team_id',
    'must_change_password',
    'password_changed_at',
    'last_login_at',
    'password_history',
    'admin_recovery_key',
])]
#[Hidden([
    'password',
    'password_history',
    'admin_recovery_key',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_history' => 'array',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'first_name',
    ];

    /**
     * Get user's first name.
     */
    public function getFirstNameAttribute(): string
    {
        $name = trim($this->name ?? '');
        if (empty($name)) {
            return 'User';
        }
        $parts = explode(' ', $name);

        return $parts[0] ?: 'User';
    }

    /**
     * All recognized staff roles mapped to their module name.
     */
    public const STAFF_ROLES = [
        'staff_inventory' => 'Inventory',
        'staff_quotations' => 'Quotations',
        'staff_procurement' => 'Procurement',
        'staff_deliveries' => 'Deliveries',
        'staff_sales' => 'Sales',
        'staff_forecast' => 'Demand Forecasting',
        'staff_suppliers' => 'Suppliers',
        'staff_reports' => 'Reports & Analytics',
    ];

    /**
     * Determine if user has administrator privileges (replaces superadmin).
     */
    public function isSuperAdmin(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Determine if user has administrator privileges (Admin can access all modules).
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin', 'owner'], true);
    }

    /**
     * Determine if user has strictly regular admin role.
     */
    public function isRegularAdmin(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Determine if user has staff privileges (1 module per staff role).
     */
    public function isStaff(): bool
    {
        return str_starts_with($this->role, 'staff_') || $this->role === 'staff';
    }

    /**
     * Modules a staff account can be assigned, keyed by module slug (which doubles as the route
     * slug), derived from STAFF_ROLES so there is a single list of modules.
     *
     * @return array<string, string> module slug => human label
     */
    public static function moduleOptions(): array
    {
        $options = [];
        foreach (self::STAFF_ROLES as $role => $label) {
            $options[substr($role, strlen('staff_'))] = $label;
        }

        return $options;
    }

    /**
     * The single module implied by a legacy role value (null when none applies).
     */
    public static function legacyModuleForRole(?string $role): ?string
    {
        if ($role === 'staff') {
            return 'inventory';
        }

        if ($role !== null && isset(self::STAFF_ROLES[$role])) {
            return substr($role, strlen('staff_'));
        }

        return null;
    }

    public function modulePermissions(): HasMany
    {
        return $this->hasMany(UserModulePermission::class)->orderBy('id');
    }

    /**
     * Whether this account has explicit module rows (otherwise the legacy single-role fallback applies).
     */
    public function hasExplicitModules(): bool
    {
        return $this->modulePermissions->isNotEmpty();
    }

    /**
     * Modules this account may access, in assignment order.
     * Admins get every module; staff get their explicit modules, or the one implied by a legacy role.
     *
     * @return array<int, string>
     */
    public function assignedModules(): array
    {
        if ($this->isAdmin()) {
            return array_keys(self::moduleOptions());
        }

        if (! $this->isStaff()) {
            return [];
        }

        if ($this->hasExplicitModules()) {
            return $this->modulePermissions->pluck('module')->values()->all();
        }

        $legacy = self::legacyModuleForRole($this->role);

        return $legacy ? [$legacy] : [];
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->assignedModules(), true);
    }

    /**
     * @param  array<int, string>  $modules
     */
    public function hasAnyModule(array $modules): bool
    {
        return array_intersect($modules, $this->assignedModules()) !== [];
    }

    /**
     * Replace this account's module assignments (unknown modules are ignored). The account role is
     * kept in step so every existing `role` check keeps working: staff_<first module> for staff,
     * and no module rows for admins / customers.
     *
     * @param  array<int, string>  $modules
     */
    public function syncModules(array $modules): void
    {
        $valid = array_keys(self::moduleOptions());
        $modules = array_values(array_unique(array_filter($modules, fn ($m) => in_array($m, $valid, true))));

        $this->modulePermissions()->delete();

        if ($this->isStaff()) {
            foreach ($modules as $module) {
                $this->modulePermissions()->create(['module' => $module]);
            }
        }

        $this->unsetRelation('modulePermissions');
    }

    /**
     * Get the route slug of the first module assigned to this staff member.
     */
    public function assignedModuleSlug(): string
    {
        if ($this->isAdmin()) {
            return 'dashboard';
        }

        return $this->assignedModules()[0] ?? 'inventory';
    }

    /**
     * Get human-friendly label for the role.
     */
    public function roleLabel(): string
    {
        if ($this->isAdmin()) {
            return 'Administrator';
        }

        if ($this->isStaff()) {
            $options = self::moduleOptions();
            $labels = array_map(fn ($m) => $options[$m], $this->assignedModules());

            if ($labels !== []) {
                return 'Staff ('.implode(', ', $labels).')';
            }

            return 'Staff';
        }

        return 'User';
    }

    /**
     * Determine if user is a standard customer.
     */
    public function isUser(): bool
    {
        return $this->role === 'user' || empty($this->role);
    }

    /**
     * Determine if user is internal personnel (admin or staff).
     */
    public function isAdminOrStaff(): bool
    {
        return $this->isAdmin() || $this->isStaff();
    }

    /**
     * Check if user account is active or pending first-time activation.
     */
    public function isActive(): bool
    {
        return in_array($this->status ?? 'active', ['active', 'pending_activation'], true);
    }

    /**
     * Determine if account is pending initial activation and password change.
     */
    public function isPendingActivation(): bool
    {
        return ($this->status ?? '') === 'pending_activation';
    }

    /**
     * Determine if user has any of the given roles.
     *
     * @param  string|array<int, string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $rolesArray = is_array($roles) ? $roles : func_get_args();

        return in_array($this->role, $rolesArray, true);
    }

    /** Shape every generated temporary password must have, e.g. "Juan1234". */
    public const INITIAL_PASSWORD_PATTERN = '/^[A-Z][a-z]+1234$/';

    /**
     * Temporary first-login password: the first name, normalised, followed by 1234.
     * "juan carlos" → "Juan1234", "MARÍA" → "Maria1234". Letters only (accents are transliterated);
     * if nothing usable is left, "User1234". Only valid until the forced first-login change.
     */
    public static function initialPasswordFor(string $firstName): string
    {
        $word = Str::before(trim($firstName), ' ');
        $letters = preg_replace('/[^A-Za-z]/', '', Str::ascii($word));
        $password = ($letters !== '' ? ucfirst(strtolower($letters)) : 'User').'1234';

        if (! preg_match(self::INITIAL_PASSWORD_PATTERN, $password)) {
            // Single-letter names ("J") don't fit the pattern; pad them so the format still holds.
            $password = ucfirst(strtolower(str_pad($letters ?: 'User', 2, 'x'))).'1234';
        }

        return $password;
    }

    /**
     * Check if the user's password has reached the 90-day expiration threshold.
     */
    public function isPasswordExpired(): bool
    {
        // Only enforced for admin and staff accounts
        if (! $this->isAdminOrStaff()) {
            return false;
        }

        if ($this->must_change_password) {
            return true;
        }

        if (! $this->password_changed_at) {
            return true;
        }

        // Expire after 90 days (3 months)
        return $this->password_changed_at->diffInDays(now()) >= 90;
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
