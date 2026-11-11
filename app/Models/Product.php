<?php

namespace App\Models;

use App\Services\Import\InventoryRowRules;
use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'sku',
        'name',
        'category',
        'brand',
        'unit',
        'price',
        'cost_price',
        'stock',
        'min_stock',
        'reorder_level',
        'reorder_quantity',
        'status',
        'description',
        'supplier_id',
        'is_active',
        'deleted_by_id',
        'missing_fields',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'reorder_level' => 'integer',
            'reorder_quantity' => 'integer',
            'is_active' => 'boolean',
            'missing_fields' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('movement_date');
    }

    /** Scope to include precomputed reserved quantity via subquery */
    public function scopeWithReservedQuantity($query, ?int $exceptSaleId = null)
    {
        $subquery = SaleItem::query()
            ->selectRaw('COALESCE(SUM(sale_items.reserved_quantity), 0)')
            ->whereColumn('sale_items.product_id', 'products.id')
            ->where('sale_items.reserved_quantity', '>', 0)
            ->when($exceptSaleId, fn ($q) => $q->where('sale_items.sale_id', '!=', $exceptSaleId))
            ->whereHas('sale', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed']));

        return $query->selectSub($subquery, 'precomputed_reserved_quantity');
    }

    /** Batch calculate reserved quantities for multiple products in a single SQL query */
    public static function batchReservedQuantities(array $productIds, ?int $exceptSaleId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        return SaleItem::query()
            ->selectRaw('product_id, SUM(reserved_quantity) as total_reserved')
            ->whereIn('product_id', $productIds)
            ->where('reserved_quantity', '>', 0)
            ->when($exceptSaleId, fn ($q) => $q->where('sale_id', '!=', $exceptSaleId))
            ->whereHas('sale', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
            ->groupBy('product_id')
            ->pluck('total_reserved', 'product_id')
            ->map(fn ($val) => (int) $val)
            ->all();
    }

    /** Units held for confirmed Sales Orders and not yet taken out of stock. */
    public function reservedQuantity(?int $exceptSaleId = null): int
    {
        if ($exceptSaleId === null && array_key_exists('precomputed_reserved_quantity', $this->attributes)) {
            return (int) $this->attributes['precomputed_reserved_quantity'];
        }

        return (int) SaleItem::query()
            ->where('product_id', $this->id)
            ->where('reserved_quantity', '>', 0)
            ->when($exceptSaleId, fn ($q) => $q->where('sale_id', '!=', $exceptSaleId))
            ->whereHas('sale', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
            ->sum('reserved_quantity');
    }

    /** Stock that is not reserved for any order. */
    public function availableStock(?int $exceptSaleId = null): int
    {
        return max(0, (int) $this->stock - $this->reservedQuantity($exceptSaleId));
    }

    /**
     * Available, Low Stock, Insufficient Stock or Not Available, for a requested quantity
     * (uses the reorder level as the low-stock threshold, like updateCalculatedStatus()).
     */
    public function stockStatusFor(int $quantity, ?int $available = null): string
    {
        $available ??= $this->availableStock();
        $threshold = $this->reorder_level > 0 ? $this->reorder_level : ($this->min_stock > 0 ? $this->min_stock : 10);

        return match (true) {
            $available <= 0 => 'Not Available',
            $available < $quantity => 'Insufficient Stock',
            $available - $quantity <= $threshold => 'Low Stock',
            default => 'Available',
        };
    }

    /**
     * For a product imported with missing values: recheck what is still missing. Once the unit
     * and selling price are filled in it becomes active (sellable). Other products are untouched.
     */
    public function refreshCompleteness(): void
    {
        if ($this->missing_fields === null) {
            return;
        }
        $missing = array_values(array_filter(
            InventoryRowRules::COMPLETION_FIELDS,
            fn ($f) => $this->{$f} === null || $this->{$f} === '',
        ));
        $sellable = ! array_intersect($missing, InventoryRowRules::REQUIRED_TO_ACTIVATE);
        $this->missing_fields = $missing ?: null;
        if ($sellable && ! $this->is_active) {
            $this->is_active = true;
        }
        $this->save();
    }

    /**
     * Calculate and update product status based on stock and reorder level.
     */
    public function updateCalculatedStatus(): void
    {
        $threshold = $this->reorder_level > 0 ? $this->reorder_level : ($this->min_stock > 0 ? $this->min_stock : 10);

        if (! $this->is_active && $this->missing_fields) {
            $this->status = 'Incomplete'; // not sellable until its missing values (e.g. selling price) are entered
        } elseif ($this->stock <= 0) {
            $this->status = 'Out of Stock';
        } elseif ($this->stock <= $threshold) {
            $this->status = 'Low Stock';
        } else {
            $this->status = 'In Stock';
        }

        // Keep min_stock in sync with reorder_level
        $this->min_stock = $threshold;
        $this->reorder_level = $threshold;

        $this->save();
    }
}
