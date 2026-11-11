<?php

namespace App\Models;

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\QuotationNumberException;
use App\Traits\TracksDeletions;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Quotation extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'quotation_number',
        'client_id',
        'client_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'quotation_date',
        'valid_until',
        'subtotal',
        'discount',
        'tax_rate',
        'tax_amount',
        'grand_total',
        'status',
        'notes',
        'terms_and_conditions',
        'attachment_path',
        'material_file_path',
        'material_file_name',
        'source_file_sha256',
        'request_details',
        'prepared_by_id',
        'submitted_for_approval_at',
        'approved_by_id',
        'approved_at',
        'email_status',
        'sent_at',
        'sent_by_id',
        'sent_pdf_path',
        'customer_decision_at',
        'customer_decision_by_id',
        'customer_decision_source',
        'customer_decision_remarks',
        'converted_to_sale_id',
        'converted_at',
        'converted_by_id',
        'deleted_by_id',
    ];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'status' => QuotationStatus::class,
            'request_details' => 'array',
            'email_status' => EmailStatus::class,
            'submitted_for_approval_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'customer_decision_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /** File fingerprints are only used on the server, to spot repeated imports. */
    protected $hidden = ['source_file_sha256', 'sent_pdf_path'];

    protected $appends = [
        'attachment_url',
        'material_file_url',
        'status_label',
        'email_status_label',
        'is_approved',
    ];

    public function getStatusLabelAttribute(): ?string
    {
        return $this->status?->label();
    }

    /** Approved by an administrator (and not revised since). */
    public function getIsApprovedAttribute(): bool
    {
        return $this->approved_at !== null;
    }

    public function getEmailStatusLabelAttribute(): ?string
    {
        return $this->email_status?->label();
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? route('quotations.attachment', ['quotation' => $this->id]) : null;
    }

    /** Staff-only link to the client's original material-list file (PDF/Excel/CSV/image). */
    public function getMaterialFileUrlAttribute(): ?string
    {
        return $this->material_file_path ? route('quotations.attachment', ['quotation' => $this->id, 'file' => 'materials']) : null;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /** Purchase requests raised for this quotation's short items. */
    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /** Extra files the client sent with the request (product lists). */
    public function files(): HasMany
    {
        return $this->hasMany(QuotationFile::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    public function decisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_decision_by_id');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_to_sale_id');
    }

    // ------------------------------------------------------------------ quotation numbers
    //
    // Format: Q<MONTH><DAY><YEAR>-<PRODUCT SKU or PROD>, then -01, -02, … for the same day and SKU.
    // Example: Q010423-068SSCR2, Q010423-068SSCR2-01. Numbers are unique per team (unique index
    // on team_id + quotation_number) and are handed out from quotation_number_sequences, so a
    // number is never given twice: not to two requests at the same moment, and not again after
    // its quotation was archived or permanently deleted.

    /** How many numbers createWithNumber() tries before giving up. */
    public const NUMBER_ATTEMPTS = 3;

    /** The fixed part of a number, e.g. Q103026-PROD. */
    public static function numberPrefix(?string $productSku = null, mixed $date = null): string
    {
        $datePart = ($date ? Carbon::parse($date) : now())->format('mdy'); // e.g. 010423 or 102526
        $skuPart = substr((string) preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string) $productSku)), 0, 150);

        return sprintf('Q%s-%s', $datePart, $skuPart !== '' ? $skuPart : 'PROD');
    }

    /**
     * The number the next quotation would get, without reserving it (for showing in forms).
     * Use createWithNumber() or generateNumber() to actually assign one.
     */
    public static function peekNumber(int $teamId, ?string $productSku = null, mixed $date = null): string
    {
        $prefix = static::numberPrefix($productSku, $date);
        $last = DB::table('quotation_number_sequences')
            ->where('team_id', $teamId)->where('prefix', $prefix)->value('last_value');

        return static::formatNumber($prefix, max($last ?? -1, static::highestUsedValue($teamId, $prefix)) + 1);
    }

    /**
     * Reserve the next number for this team, day and SKU. The number is used up even when the
     * quotation is never saved, so it cannot be given to anyone else.
     */
    public static function generateNumber(int $teamId, ?string $productSku = null, mixed $date = null): string
    {
        $prefix = static::numberPrefix($productSku, $date);

        return DB::transaction(function () use ($teamId, $prefix) {
            // Numbers saved without this sequence (older records, CSV imports) are never reused.
            $floor = static::highestUsedValue($teamId, $prefix);
            $sequence = fn () => DB::table('quotation_number_sequences')->where('team_id', $teamId)->where('prefix', $prefix);

            // A single UPDATE both increments and locks the row until the transaction ends, so
            // concurrent requests wait for each other instead of reading the same value.
            $bump = fn () => $sequence()->update([
                'last_value' => DB::raw("(CASE WHEN last_value > {$floor} THEN last_value ELSE {$floor} END) + 1"),
                'updated_at' => now(),
            ]);

            if ($bump() === 0) {
                $created = DB::table('quotation_number_sequences')->insertOrIgnore([
                    'team_id' => $teamId,
                    'prefix' => $prefix,
                    'last_value' => $floor + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($created === 0) {
                    $bump(); // another request created the row first
                }
            }

            return static::formatNumber($prefix, (int) $sequence()->value('last_value'));
        });
    }

    /**
     * Create a quotation with a newly reserved number. This is the one way to create a numbered
     * quotation. The unique index stays the final guard: if the number was taken in the meantime
     * (for example by a CSV import running at the same moment), another number is reserved and
     * the insert is tried again.
     *
     * @param  array<string, mixed>  $attributes  must include team_id
     *
     * @throws QuotationNumberException when no unique number could be assigned
     */
    public static function createWithNumber(array $attributes, ?string $productSku = null, mixed $date = null): static
    {
        $teamId = (int) $attributes['team_id'];

        for ($attempt = 1; ; $attempt++) {
            $number = static::generateNumber($teamId, $productSku, $date);

            try {
                // A savepoint, so a failed insert is undone on its own while the number stays used.
                return DB::transaction(fn () => static::create(['quotation_number' => $number] + $attributes));
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'quotation_number')) {
                    throw $e;
                }
                if ($attempt >= self::NUMBER_ATTEMPTS) {
                    throw new QuotationNumberException(
                        "Could not assign a unique quotation number for team {$teamId} after {$attempt} attempts (last tried {$number}).",
                        previous: $e,
                    );
                }
            }
        }
    }

    /** Sequence value to number: 0 is the prefix alone, then -01, -02, … */
    private static function formatNumber(string $prefix, int $value): string
    {
        return $value === 0 ? $prefix : sprintf('%s-%02d', $prefix, $value);
    }

    /**
     * Highest sequence value already used by this team's quotations with this prefix, archived
     * ones included, or -1 when there are none. Compared case-insensitively, like MySQL's unique index.
     */
    private static function highestUsedValue(int $teamId, string $prefix): int
    {
        $numbers = static::withTrashed()
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q->where('quotation_number', $prefix)->orWhere('quotation_number', 'like', $prefix.'-%'))
            ->pluck('quotation_number');

        $highest = -1;
        foreach ($numbers as $number) {
            if (strcasecmp($number, $prefix) === 0) {
                $highest = max($highest, 0);
            } elseif (preg_match('/^'.preg_quote($prefix, '/').'-(\d{1,9})$/i', $number, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $highest;
    }

    /**
     * Check if quotation is currently expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        if (! $this->valid_until) {
            return false;
        }

        $validUntil = $this->valid_until instanceof CarbonInterface
            ? $this->valid_until->toDateString()
            : substr((string) $this->valid_until, 0, 10);

        return $validUntil < now()->toDateString() && ! $this->status?->isClosed() && $this->status !== QuotationStatus::Accepted;
    }
}
