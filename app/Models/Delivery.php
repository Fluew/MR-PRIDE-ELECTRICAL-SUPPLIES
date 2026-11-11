<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'inbound_delivery_id',
        'delivery_date',
        'delivery_number',
        'purchase_order_number',
        'purchase_order_id',
        'quantity',
        'accepted_quantity',
        'rejected_quantity',
        'inspection_result',
        'inspected_on',
        'inspected_by_id',
        'inspection_remarks',
        'rejection_reason',
        'unit',
        'description',
        'supplier_id',
        'received_by_id',
        'status',
        'notes',
        'deleted_by_id',
        'supplier_document_number',
        'over_receipt_reason',
        'over_receipt_approved_by_id',
        'inspection_number',
        'rejection_category',
        'resolution_status',
        'resolution_remarks',
        'resolved_at',
        'posted_quantity',
        'posted_at',
        'posted_by_id',
        'stock_movement_id',
        'damaged_quantity',
        'defective_quantity',
    ];

    /** Kinds of rejection recorded at inspection. */
    public const REJECTION_CATEGORIES = ['defective', 'damaged', 'incorrect_item', 'nonconforming_spec', 'shortage_in_pack', 'other'];

    /** How rejected units were resolved with the supplier. */
    public const RESOLUTIONS = ['returned', 'replaced', 'credited', 'written_off'];

    protected $casts = [
        'delivery_date' => 'date',
        'quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'posted_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'defective_quantity' => 'integer',
        'inspected_on' => 'date',
        'resolved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /**
     * Inspection status shown to staff, derived from the recorded quantities:
     * Pending Inspection, Passed, Partially Accepted, Failed, or Pending Resolution
     * (rejected units exist and no supplier resolution has been recorded yet).
     */
    public function inspectionStatus(): string
    {
        if ($this->inspection_result === null) {
            return 'Pending Inspection';
        }
        if ((int) $this->rejected_quantity > 0 && $this->resolution_status === null) {
            return 'Pending Resolution';
        }

        return match ($this->inspection_result) {
            'accepted' => 'Passed',
            'rejected' => 'Failed',
            default => 'Partially Accepted',
        };
    }

    /** Quality status of the received product: Passed, Partially Passed, Failed, or Pending Inspection. */
    public function qualityStatus(): string
    {
        return match ($this->inspection_result) {
            'accepted' => 'Passed',
            'partially_accepted' => 'Partially Passed',
            'rejected' => 'Failed',
            default => 'Pending Inspection',
        };
    }

    /**
     * Every delivery belongs to an inbound delivery record (its Delivery #). Records made one
     * line at a time (the purchase order workspace, the CSV import) get theirs here; deliveries
     * sharing a number share one.
     */
    protected static function booted(): void
    {
        $attach = function (Delivery $delivery) {
            if ($delivery->inbound_delivery_id || ! $delivery->team_id || $delivery->delivery_number === null) {
                return;
            }
            $header = InboundDelivery::firstOrCreate(
                ['team_id' => $delivery->team_id, 'delivery_number' => $delivery->delivery_number],
                [
                    'delivery_date' => $delivery->delivery_date ?? now()->toDateString(),
                    'purchase_order_number' => $delivery->purchase_order_number,
                    'supplier_id' => $delivery->supplier_id,
                    'status' => $delivery->status === 'Cancelled' ? InboundDelivery::CANCELLED : InboundDelivery::CONFIRMED,
                    'confirmed_by_id' => $delivery->received_by_id,
                    'confirmed_at' => now(),
                ],
            );
            $delivery->inbound_delivery_id = $header->id;
        };
        static::creating($attach);
        static::restoring($attach);
    }

    public function inboundDelivery(): BelongsTo
    {
        return $this->belongsTo(InboundDelivery::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CommercialInvoice::class, 'delivery_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    /**
     * Generate a unique delivery number, e.g. DEL-102526-001 (same sequence as the inbound
     * delivery records, so two deliveries recorded at once never share a number).
     */
    public static function generateDeliveryNumber(int $teamId, mixed $date = null): string
    {
        return InboundDelivery::reserveNumber($teamId, $date);
    }
}
