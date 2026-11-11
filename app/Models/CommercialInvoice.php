<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialInvoice extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'invoice_date',
        'invoice_number',
        'quantity',
        'description',
        'unit_cost',
        'total_cost',
        'supplier_id',
        'status',
        'notes',
        'deleted_by_id',
        'purchase_order_id',
        'delivery_id',
        'product_id',
        'tax_amount',
        'payment_terms',
        'recorded_by_id',
        'discrepancies',
        'verified_by_id',
        'verified_at',
        'verification_remarks',
        'attachment_path',
        'attachment_name',
    ];

    public const PENDING = 'Pending Verification';

    public const FLAGGED = 'Flagged';

    public const VERIFIED = 'Verified';

    public const CANCELLED = 'Cancelled';

    protected $casts = [
        'invoice_date' => 'date',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discrepancies' => 'array',
        'verified_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class, 'commercial_invoice_id');
    }

    /**
     * Generate commercial invoice number formatted like CI-035-0722
     */
    public static function generateInvoiceNumber(int $teamId, mixed $date = null): string
    {
        $parsedDate = $date ? Carbon::parse($date) : now();
        $mmyy = $parsedDate->format('my'); // e.g. 0722, 1026

        $count = static::where('team_id', $teamId)->count() + 1;
        $counter = $count;

        do {
            $seq = str_pad((string) ($counter % 1000), 3, '0', STR_PAD_LEFT);
            $candidate = "CI-{$seq}-{$mmyy}";
            $counter++;
        } while (static::where('team_id', $teamId)->where('invoice_number', $candidate)->exists());

        return $candidate;
    }
}
