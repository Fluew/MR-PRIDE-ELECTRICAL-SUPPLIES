<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'po_date',
        'po_number',
        'quantity',
        'item_sku',
        'product_id',
        'promised_delivery_date',
        'description',
        'unit_of_measure',
        'unit_cost',
        'total_cost',
        'supplier_id',
        'status',
        'notes',
        'deleted_by_id',
        'purchase_request_id',
        'prepared_by_id',
        'payment_terms',
        'quantity_adjustment_reason',
        'sent_at',
        'closed_at',
        'cancellation_reason',
    ];

    public const DRAFT = 'Draft';

    public const SENT = 'Sent to Supplier';

    public const PARTIAL = 'Partially Delivered';

    public const FULL = 'Fully Delivered';

    public const CLOSED = 'Closed';

    public const CANCELLED = 'Cancelled';

    /** No second approval: the source Purchase Request was already approved by an admin. */
    public const STATUSES = [self::DRAFT, self::SENT, self::PARTIAL, self::FULL, self::CLOSED, self::CANCELLED];

    /** Statuses in which goods may still be received. */
    public const RECEIVABLE = [self::SENT, self::PARTIAL];

    /**
     * How each status is shown: receiving progress reads Pending → Partially Received → Received.
     * (The stored values are unchanged, so existing records and rules keep working.)
     */
    public const STATUS_LABELS = [
        self::DRAFT => 'Draft',
        self::SENT => 'Pending',
        self::PARTIAL => 'Partially Received',
        self::FULL => 'Received',
        self::CLOSED => 'Closed',
        self::CANCELLED => 'Cancelled',
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    protected $casts = [
        'po_date' => 'date',
        'promised_delivery_date' => 'date',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'sent_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CommercialInvoice::class, 'purchase_order_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'purchase_order_id');
    }

    /**
     * Generate purchase order number formatted like 4000005472 (10-digit number)
     */
    public static function generatePoNumber(int $teamId): string
    {
        $base = 4000000000;
        $count = static::where('team_id', $teamId)->count();
        $candidateNum = $base + 5000 + $count + 1;

        while (static::where('team_id', $teamId)->where('po_number', (string) $candidateNum)->exists()) {
            $candidateNum++;
        }

        return (string) $candidateNum;
    }
}
