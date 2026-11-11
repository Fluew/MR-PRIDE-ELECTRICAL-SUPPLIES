<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TrustedDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_uuid',
        'device_name',
        'ip_address',
        'status',
        'credential_hash',
        'enrollment_code',
        'enrollment_code_expires_at',
        'is_shared',
        'authorized_user_ids',
        'approved_by',
        'approved_at',
        'enrollment_token',
        'enrollment_token_expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'authorized_user_ids' => 'array',
            'enrollment_code_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'enrollment_token_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'active'], true);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /** Device id used by the local-development bypass (SKIP_DEVICE_VERIFICATION, APP_ENV=local only). */
    public const LOCAL_DEV_UUID = 'local-development-workstation';

    /**
     * Check if a specific user account is authorized to use this workstation.
     *
     * One device, one account: a device only authorizes the account it is bound to. Legacy
     * "shared workstation" rows (is_shared / authorized_user_ids) no longer grant access to other
     * accounts; those users need their own enrolled device. The only exception is the local
     * development bypass device, which exists only while the bypass is active.
     */
    public function isAuthorizedForUser(User $user): bool
    {
        if (! $this->isApproved()) {
            return false;
        }

        if ($this->device_uuid === self::LOCAL_DEV_UUID && self::bypassActive()) {
            return true;
        }

        return $this->user_id && (int) $this->user_id === (int) $user->id;
    }

    /** Whether the local-development device bypass is on (never outside APP_ENV=local). */
    public static function bypassActive(): bool
    {
        return (bool) config('auth.skip_device_verification', false) && app()->isLocal();
    }

    /**
     * Enrollment codes and link tokens are stored as SHA-256 hashes, never in plain text.
     * (They are high-entropy and short-lived, so a fast hash is enough and allows lookups.)
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** Find the pending invitation for a raw link token. */
    public static function findByEnrollmentToken(string $rawToken): ?self
    {
        return $rawToken === '' ? null : self::where('enrollment_token', self::hashSecret($rawToken))->first();
    }

    /** Whether this invitation's link and code are past their expiry time. */
    public function enrollmentExpired(): bool
    {
        $expiresAt = $this->enrollment_token_expires_at ?? $this->enrollment_code_expires_at;

        return ! $expiresAt || $expiresAt->isPast();
    }

    /** Make the invitation unusable (after success, expiry, or too many wrong codes). */
    public function clearEnrollment(): void
    {
        $this->forceFill([
            'enrollment_code' => null,
            'enrollment_code_expires_at' => null,
            'enrollment_token' => null,
            'enrollment_token_expires_at' => null,
        ])->save();
    }

    /**
     * Locate and verify an active trusted device by request cookies.
     */
    public static function findActiveByCredential(Request $request): ?self
    {
        // Dev convenience only: the bypass is ignored outside APP_ENV=local, so a forgotten
        // SKIP_DEVICE_VERIFICATION=true can't switch off device checks in production.
        // (Read via config(), not env(), so it also works when config is cached.)
        if (self::bypassActive()) {
            $device = self::where('device_uuid', self::LOCAL_DEV_UUID)->first();
            if (! $device) {
                $device = self::create([
                    'device_uuid' => self::LOCAL_DEV_UUID,
                    'device_name' => 'Local Development Workstation',
                    'status' => 'approved',
                    'is_shared' => true,
                    'authorized_user_ids' => null,
                    'approved_at' => now(),
                    'last_used_at' => now(),
                ]);
            }

            return $device;
        }

        $uuid = $request->cookie('mrpride_device_uuid') ?: $request->cookie('mrpride_device_id');
        $credential = $request->cookie('mrpride_device_credential');

        if (! $uuid || ! is_string($uuid) || ! $credential || ! is_string($credential)) {
            return null;
        }

        $device = self::where('device_uuid', $uuid)->first();
        if (! $device || ! $device->isApproved() || empty($device->credential_hash)) {
            return null;
        }

        $expectedHash = hash('sha256', $credential);
        if (! hash_equals($device->credential_hash, $expectedHash)) {
            return null;
        }

        return $device;
    }

    /**
     * Issue and securely cookie a high-entropy random credential for this device.
     */
    public function issueCredential(?string $rawCredential = null): string
    {
        $rawCredential = $rawCredential ?: Str::random(64);
        $this->credential_hash = hash('sha256', $rawCredential);
        $this->save();

        $secure = request()->isSecure();
        cookie()->queue(cookie()->forever('mrpride_device_credential', $rawCredential, httpOnly: true, sameSite: 'lax', secure: $secure));
        cookie()->queue(cookie()->forever('mrpride_device_uuid', $this->device_uuid, httpOnly: true, sameSite: 'lax', secure: $secure));
        cookie()->queue(cookie()->forever('mrpride_device_id', $this->device_uuid, httpOnly: true, sameSite: 'lax', secure: $secure));

        return $rawCredential;
    }

    /**
     * Generate a unique enrollment code formatted for easy input.
     */
    public static function generateEnrollmentCode(): string
    {
        // Str::random() uses random_bytes(), a cryptographically secure source.
        do {
            $code = 'MRP-'.strtoupper(Str::random(6));
        } while (self::where('enrollment_code', self::hashSecret($code))->exists());

        return $code;
    }
}
