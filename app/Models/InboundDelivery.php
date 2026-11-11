<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One inbound P.O. delivery: its Delivery # and date, the purchase order and supplier, and who
 * confirmed it. Its products are `deliveries` rows (one per purchase order line), each with its
 * received / accepted / rejected quantities and quality inspection.
 *
 * A cancelled delivery stays on record (audit trail); its accepted units were taken back out of
 * stock when it was cancelled.
 */
class InboundDelivery extends Model
{
    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'team_id', 'delivery_number', 'delivery_date', 'purchase_order_number', 'supplier_id', 'status',
        'confirmed_by_id', 'confirmed_at', 'cancelled_by_id', 'cancelled_at', 'cancellation_reason', 'supplier_document_number', 'notes', 'submission_key',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * Reserve the next Delivery # in the existing convention (DEL-MMDDYY-NNN, by confirmation day).
     * The counter is one atomic UPDATE on a quotation_number_sequences row ("DEL:DEL-MMDDYY"),
     * floored at the highest number already used, so two people confirming at once never get the
     * same number. The unique (team_id, delivery_number) index is the last guard.
     */
    public static function reserveNumber(int $teamId, mixed $date = null): string
    {
        $code = 'DEL-'.($date ? Carbon::parse($date) : now())->format('mdy');
        $taken = fn (string $n) => static::where('team_id', $teamId)->where('delivery_number', $n)->exists()
            || Delivery::withTrashed()->where('team_id', $teamId)->where('delivery_number', $n)->exists();

        return DB::transaction(function () use ($teamId, $code, $taken) {
            $floor = collect([
                static::where('team_id', $teamId)->where('delivery_number', 'like', "{$code}-%")->pluck('delivery_number'),
                Delivery::withTrashed()->where('team_id', $teamId)->where('delivery_number', 'like', "{$code}-%")->pluck('delivery_number'),
            ])->flatten()->map(fn ($n) => preg_match('/-(\d+)$/', (string) $n, $m) ? (int) $m[1] : 0)->max() ?? 0;

            $sequence = fn () => DB::table('quotation_number_sequences')->where('team_id', $teamId)->where('prefix', "DEL:{$code}");
            $bump = fn () => $sequence()->update([
                'last_value' => DB::raw("(CASE WHEN last_value > {$floor} THEN last_value ELSE {$floor} END) + 1"),
                'updated_at' => now(),
            ]);
            do {
                if ($bump() === 0) {
                    $created = DB::table('quotation_number_sequences')->insertOrIgnore([
                        'team_id' => $teamId, 'prefix' => "DEL:{$code}", 'last_value' => $floor + 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($created === 0) {
                        $bump(); // someone else created the row a moment ago
                    }
                }
                $candidate = sprintf('%s-%03d', $code, (int) $sequence()->value('last_value'));
            } while ($taken($candidate));

            return $candidate;
        });
    }
}
