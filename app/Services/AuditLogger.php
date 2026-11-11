<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    /**
     * Record an audit or security log event.
     *
     * @param  array<string, mixed>|null  $metadata
     * @param  string  $category  'audit' or 'security'
     * @param  string  $severity  'info', 'warning', 'danger'
     * @param  string  $outcome  'success', 'failure', 'blocked'
     */
    public static function log(
        string $event,
        string $description,
        ?User $user = null,
        ?array $metadata = null,
        ?Request $request = null,
        string $category = 'audit',
        string $severity = 'info',
        ?User $targetUser = null,
        string $outcome = 'success'
    ): AuditLog {
        $req = $request ?: request();

        // Sanitize metadata to never log passwords, tokens, secrets or session IDs
        $sanitizedMetadata = self::sanitizeMetadata($metadata ?? []);

        $log = AuditLog::create([
            'category' => $category,
            'user_id' => $user?->id,
            'user_email' => $user?->email ?? ($req ? $req->input('email') : null),
            'target_user_id' => $targetUser?->id,
            'target_user_email' => $targetUser?->email,
            'event' => $event,
            'action_type' => $event,
            'description' => $description,
            'outcome' => $outcome,
            'severity' => $severity,
            'ip_address' => $req?->ip(),
            'user_agent' => $req?->userAgent(),
            'metadata' => $sanitizedMetadata ?: null,
            'created_at' => now(),
        ]);

        $logContext = [
            'user_id' => $user?->id,
            'target_user_id' => $targetUser?->id,
            'ip' => $req?->ip(),
            'outcome' => $outcome,
        ];

        $channel = $category === 'security' ? 'security' : 'stack';
        $logLevel = match ($severity) {
            'danger' => 'error',
            'warning' => 'warning',
            default => 'info',
        };

        Log::channel($channel)->{$logLevel}("[{$category}] {$event}: {$description}", $logContext);

        return $log;
    }

    /**
     * Record an administrative audit action (Audit Trail).
     */
    public static function audit(
        string $actionType,
        string $description,
        ?User $actingUser = null,
        ?User $targetUser = null,
        ?array $metadata = null,
        string $outcome = 'success',
        ?Request $request = null
    ): AuditLog {
        return self::log(
            event: $actionType,
            description: $description,
            user: $actingUser,
            metadata: $metadata,
            request: $request,
            category: 'audit',
            severity: 'info',
            targetUser: $targetUser,
            outcome: $outcome
        );
    }

    /**
     * Record a security, authentication, or access control event (Security Logs).
     */
    public static function security(
        string $eventType,
        string $description,
        ?User $user = null,
        string $severity = 'info',
        ?array $metadata = null,
        string $outcome = 'success',
        ?Request $request = null
    ): AuditLog {
        return self::log(
            event: $eventType,
            description: $description,
            user: $user,
            metadata: $metadata,
            request: $request,
            category: 'security',
            severity: $severity,
            outcome: $outcome
        );
    }

    /**
     * Remove sensitive keys from metadata array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function sanitizeMetadata(array $data): array
    {
        $sensitive = [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'session_id',
            'remember_token',
            'verification_code',
            'recovery_code',
            'key',
            'credential',
        ];

        $cleaned = [];
        foreach ($data as $k => $v) {
            $lower = strtolower($k);
            $isSensitive = false;
            foreach ($sensitive as $s) {
                if (str_contains($lower, $s)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $cleaned[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $cleaned[$k] = self::sanitizeMetadata($v);
            } else {
                $cleaned[$k] = $v;
            }
        }

        return $cleaned;
    }

    /**
     * Record a business action about one record (a quotation, order, payment, delivery…), so it
     * shows up in that record's activity history. $user null means a system process did it.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(string $event, string $description, Model $subject, array $details = [], ?User $user = null, string $outcome = 'success', string $severity = 'info'): AuditLog
    {
        $actor = $user ?? (auth()->user() instanceof User ? auth()->user() : null);
        if (! $actor) {
            $details['by'] = 'system';
        }

        $log = self::log($event, $description, $actor, $details, null, 'audit', $severity, null, $outcome);
        $log->forceFill(['subject_type' => class_basename($subject), 'subject_id' => $subject->getKey()])->save();

        return $log;
    }
}
