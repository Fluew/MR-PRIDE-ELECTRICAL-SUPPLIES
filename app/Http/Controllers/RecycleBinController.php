<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Models\Client;
use App\Models\CommercialInvoice;
use App\Models\Delivery;
use App\Models\Inquiry;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\RecycleBin\PermanentDeleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecycleBinController extends Controller
{
    /**
     * Map of supported archivable types to their Eloquent model classes and labels.
     *
     * @var array<string, array{class: class-string, label: string, color: string}>
     */
    protected const TYPE_REGISTRY = [
        'product' => [
            'class' => Product::class,
            'label' => 'Product',
            'badge' => 'Emerald',
        ],
        'supplier' => [
            'class' => Supplier::class,
            'label' => 'Supplier',
            'badge' => 'Amber',
        ],
        'client' => [
            'class' => Client::class,
            'label' => 'Client / Customer',
            'badge' => 'Blue',
        ],
        'quotation' => [
            'class' => Quotation::class,
            'label' => 'Quotation',
            'badge' => 'Indigo',
        ],
        'purchase_order' => [
            'class' => PurchaseOrder::class,
            'label' => 'Purchase Order',
            'badge' => 'Cyan',
        ],
        'purchase_request' => [
            'class' => PurchaseRequest::class,
            'label' => 'Purchase Request',
            'badge' => 'Sky',
        ],
        'commercial_invoice' => [
            'class' => CommercialInvoice::class,
            'label' => 'Commercial Invoice',
            'badge' => 'Violet',
        ],
        'delivery' => [
            'class' => Delivery::class,
            'label' => 'Delivery',
            'badge' => 'Purple',
        ],
        'customer_delivery' => [
            'class' => OrderFulfillment::class,
            'label' => 'Customer Delivery',
            'badge' => 'Orange',
        ],
        'inquiry' => [
            'class' => Inquiry::class,
            'label' => 'Message / Inquiry',
            'badge' => 'Teal',
        ],
        'sale' => [
            'class' => Sale::class,
            'label' => 'Sale Transaction',
            'badge' => 'Rose',
        ],
    ];

    /**
     * Record types left out of the Record Type dropdown. Their archived records still appear
     * under "All Records" and can still be restored or deleted there.
     *
     * @var list<string>
     */
    protected const NOT_FILTERABLE_TYPES = ['inquiry'];

    /**
     * Display a listing of recoverable deleted records.
     */
    public function index(Request $request, mixed $current_team = null): Response
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Unauthorized access. Only administrators can access the Recycle Bin.');

        $team = $this->resolveTeam($current_team, $request);

        // Unknown or non-filterable record types fall back to "All Records" instead of showing an empty list.
        $filterableTypes = array_diff_key(self::TYPE_REGISTRY, array_flip(self::NOT_FILTERABLE_TYPES));
        $selectedType = (string) $request->input('type', 'all');
        if ($selectedType !== 'all' && ! isset($filterableTypes[$selectedType])) {
            $selectedType = 'all';
        }
        $search = trim((string) $request->input('search', ''));
        $perPage = 15;

        // Counts per record type follow the current search, so each dropdown option shows
        // how many records it will list. $totalArchived ignores filters (used by "Empty Recycle Bin").
        $allRecords = collect();
        $counts = ['all' => 0] + array_fill_keys(array_keys(self::TYPE_REGISTRY), 0);
        $totalArchived = 0;

        foreach (self::TYPE_REGISTRY as $typeKey => $config) {
            $modelClass = $config['class'];
            $items = $modelClass::onlyTrashed()
                ->where('team_id', $team->id)
                ->with(['deletedBy'])
                ->get();

            $totalArchived += $items->count();
            $isListed = $selectedType === 'all' || $selectedType === $typeKey;

            // Without a search, a type that is not being listed only needs its count.
            if ($search === '' && ! $isListed) {
                $counts[$typeKey] = $items->count();
                $counts['all'] += $items->count();

                continue;
            }

            $matching = $items
                ->map(fn ($item) => $this->formatTrashedRecord($item, $typeKey, $config))
                ->filter(fn (array $rec) => $this->recordMatchesSearch($rec, $search));

            $counts[$typeKey] = $matching->count();
            $counts['all'] += $matching->count();

            if ($isListed) {
                $allRecords = $allRecords->concat($matching);
            }
        }

        // Sort newest deleted first
        $sortedRecords = $allRecords->sortByDesc(fn ($rec) => $rec['deleted_at_raw'])->values();

        // Manual pagination, keeping the page inside the filtered result
        $total = $sortedRecords->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->input('page', 1)), $lastPage);
        $sliced = $sortedRecords->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                // Page links carry the active filters so paging keeps the same type and search.
                'query' => array_filter([
                    'type' => $selectedType !== 'all' ? $selectedType : null,
                    'search' => $search !== '' ? $search : null,
                ]),
            ]
        );

        // Stats summary
        $stats = [
            'total' => $totalArchived,
            'deleted_today' => $allRecords->filter(fn ($r) => substr((string) ($r['deleted_at_raw'] ?? ''), 0, 10) === now()->toDateString())->count(),
            'types_with_records' => collect($counts)->filter(fn ($val, $k) => $k !== 'all' && $val > 0)->count(),
        ];

        return Inertia::render('RecycleBin/Index', [
            'records' => $paginator,
            // 'all' still includes every archived record; only the dropdown's own categories are limited.
            'counts' => array_diff_key($counts, array_flip(self::NOT_FILTERABLE_TYPES)),
            'stats' => $stats,
            'filters' => [
                'type' => $selectedType,
                'search' => $search,
            ],
            'availableTypes' => collect($filterableTypes)->map(fn ($cfg, $k) => [
                'key' => $k,
                'label' => $cfg['label'],
                'badge' => $cfg['badge'],
            ])->values(),
        ]);
    }

    /**
     * Restore an archived record to active use.
     */
    public function restore(Request $request, mixed $current_team = null, string $type = '', int|string $id = 0): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Unauthorized access.');

        $team = $this->resolveTeam($current_team, $request);
        $model = $this->findTrashedRecord($team, $type, $id);

        $title = $this->getRecordTitle($model, $type);
        $typeLabel = self::TYPE_REGISTRY[$type]['label'] ?? ucfirst($type);

        try {
            $this->restoreRecord($model, $request);
        } catch (\RuntimeException $e) {
            return back()->with('error', "{$typeLabel} '{$title}' was not restored: {$e->getMessage()}");
        }

        AuditLogger::audit(
            'recycle_bin.restore',
            "Restored {$typeLabel} '{$title}' (#{$id}) to active use.",
            $user,
            null,
            ['type' => $type, 'id' => $id, 'title' => $title],
            'success',
            $request
        );

        return back()->with('success', "{$typeLabel} '{$title}' has been returned to active use.");
    }

    /**
     * Permanently delete a record from the database (restricted action).
     */
    public function forceDelete(Request $request, mixed $current_team = null, string $type = '', int|string $id = 0): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Unauthorized access.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }

        $team = $this->resolveTeam($current_team, $request);
        $model = $this->findTrashedRecord($team, $type, $id);

        $title = $this->getRecordTitle($model, $type);
        $typeLabel = self::TYPE_REGISTRY[$type]['label'] ?? ucfirst($type);

        if ($reason = $this->purgeBlockedReason($model, $type)) {
            return back()->with('error', "{$typeLabel} '{$title}' cannot be permanently deleted: {$reason} It stays archived.");
        }

        // Permanently erase record
        $this->permanentlyDeleteRecord($model);

        AuditLogger::audit(
            'recycle_bin.permanent_delete',
            "Permanently deleted {$typeLabel} '{$title}' (#{$id}) from the system database.",
            $user,
            null,
            ['type' => $type, 'id' => $id, 'title' => $title],
            'success',
            $request
        );

        return back()->with('success', "{$typeLabel} '{$title}' has been permanently deleted.");
    }

    /**
     * Bulk restore multiple archived records.
     */
    public function bulkRestore(Request $request, mixed $current_team = null): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Unauthorized access.');

        $team = $this->resolveTeam($current_team, $request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string'],
            'items.*.id' => ['required'],
        ]);

        $restoredCount = 0;
        $skippedCount = 0;

        foreach ($validated['items'] as $itemData) {
            try {
                $type = $itemData['type'];
                $id = $itemData['id'];
                $model = $this->findTrashedRecord($team, $type, $id);
                $this->restoreRecord($model, $request);

                $restoredCount++;
            } catch (\Throwable) {
                $skippedCount++;
            }
        }

        AuditLogger::audit(
            'recycle_bin.bulk_restore',
            "Bulk restored {$restoredCount} archived records back to active use.",
            $user,
            null,
            ['restored_count' => $restoredCount],
            'success',
            $request
        );

        return back()->with('success', "Successfully restored {$restoredCount} records to active use."
            .($skippedCount ? " {$skippedCount} could not be restored (for example, a sale whose items are no longer in stock)." : ''));
    }

    /**
     * Bulk permanently delete multiple archived records.
     */
    public function bulkForceDelete(Request $request, mixed $current_team = null): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Unauthorized access.');

        $team = $this->resolveTeam($current_team, $request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string'],
            'items.*.id' => ['required'],
            'confirmation' => ['sometimes', 'required', 'string', Rule::in(['YES'])],
        ]);

        $deletedCount = 0;
        $keptCount = 0;

        foreach ($validated['items'] as $itemData) {
            try {
                $type = $itemData['type'];
                $id = $itemData['id'];
                $model = $this->findTrashedRecord($team, $type, $id);
                if ($this->purgeBlockedReason($model, $type)) {
                    $keptCount++;

                    continue;
                }
                $this->permanentlyDeleteRecord($model);
                $deletedCount++;
            } catch (\Throwable) {
                continue;
            }
        }

        AuditLogger::audit(
            'recycle_bin.bulk_permanent_delete',
            "Bulk permanently purged {$deletedCount} records from the system.",
            $user,
            null,
            ['deleted_count' => $deletedCount, 'kept_count' => $keptCount],
            'success',
            $request
        );

        return back()->with('success', "Successfully purged {$deletedCount} records permanently."
            .($keptCount ? " {$keptCount} records with active stock or transaction dependencies were kept archived." : ''));
    }

    /**
     * Empty the entire Recycle Bin for this team.
     */
    public function emptyBin(Request $request, mixed $current_team = null): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Unauthorized access.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }

        $team = $this->resolveTeam($current_team, $request);
        $totalPurged = 0;

        $keptCount = 0;

        foreach (self::TYPE_REGISTRY as $typeKey => $config) {
            $modelClass = $config['class'];
            $trashed = $modelClass::onlyTrashed()->where('team_id', $team->id)->get();
            foreach ($trashed as $item) {
                if ($this->purgeBlockedReason($item, $typeKey)) {
                    $keptCount++;

                    continue;
                }
                $this->permanentlyDeleteRecord($item);
                $totalPurged++;
            }
        }

        AuditLogger::audit(
            'recycle_bin.empty',
            "Emptied entire recycle bin. Total records permanently purged: {$totalPurged}.",
            $user,
            null,
            ['total_purged' => $totalPurged, 'kept_count' => $keptCount],
            'success',
            $request
        );

        return back()->with('success', "Recycle Bin emptied successfully ({$totalPurged} records permanently removed)."
            .($keptCount ? " {$keptCount} records with active stock or transaction dependencies were kept archived." : ''));
    }

    /**
     * Whether a formatted archived record matches the search text (title, identifier, summary or deleter).
     *
     * @param  array<string, mixed>  $record
     */
    protected function recordMatchesSearch(array $record, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        $needle = mb_strtolower($search);

        foreach ([
            $record['title'] ?? '',
            $record['identifier'] ?? '',
            $record['summary'] ?? '',
            $record['deleted_by']['name'] ?? '',
            $record['deleted_by']['email'] ?? '',
        ] as $haystack) {
            if (str_contains(mb_strtolower((string) $haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permanently delete an archived record from the database, with its child rows, its email
     * log entries and its stored files (see PermanentDeleter).
     */
    protected function permanentlyDeleteRecord(mixed $model): void
    {
        app(PermanentDeleter::class)->delete($model);
    }

    /**
     * Restore one archived record.
     *
     * Voiding a sale returned its items to stock, so restoring the sale takes them out again
     * (with a stock movement for each line). If there is no longer enough stock, nothing is
     * changed and a RuntimeException explains why.
     */
    protected function restoreRecord(mixed $model, Request $request): void
    {
        DB::transaction(function () use ($model, $request) {
            // Cancelled orders are archived as they are (cancelling already handled the stock),
            // so restoring one only brings the record back.
            if ($model instanceof Sale && $model->status !== SalesOrderStatus::Cancelled) {
                $model->load('items');

                foreach ($model->items as $item) {
                    $product = $item->product_id
                        ? Product::whereKey($item->product_id)->lockForUpdate()->first()
                        : null;

                    if (! $product) {
                        continue; // same rule as voiding: lines without a live product were not returned to stock
                    }

                    if ($product->stock < $item->quantity) {
                        throw new \RuntimeException("not enough stock for {$product->name} (available: {$product->stock}, needed: {$item->quantity}).");
                    }

                    $previousStock = $product->stock;
                    $product->decrement('stock', $item->quantity);

                    StockMovement::create([
                        'team_id' => $model->team_id,
                        'product_id' => $product->id,
                        'user_id' => $request->user()->id,
                        'type' => 'adjustment',
                        'quantity' => $item->quantity,
                        'previous_stock' => $previousStock,
                        'new_stock' => $previousStock - $item->quantity,
                        'reference_number' => $model->invoice_number,
                        'reason' => "Sale {$model->invoice_number} restored from recycle bin",
                        'movement_date' => now(),
                    ]);

                    $product->refresh();
                    $product->updateCalculatedStatus();
                }
            }

            $model->restore();

            // If product, re-enable active flag
            if ($model instanceof Product) {
                $model->is_active = true;
                $model->saveQuietly();
            }
        });
    }

    /**
     * Why a record must not be permanently deleted, or null if it can be.
     *
     * The database cascades a hard delete to child rows, so master records that carry active
     * stock or procurement dependencies are kept archived instead. Archived sale transactions
     * can be restored or permanently deleted by an authorized Admin.
     */
    protected function purgeBlockedReason(mixed $model, string $type): ?string
    {
        if ($type === 'product' && ($model->stockMovements()->exists() || $model->saleItems()->exists() || $model->quotationItems()->exists())) {
            return 'it has stock movement, sales or quotation history.';
        }

        if ($type === 'supplier' && ($model->purchaseOrders()->withTrashed()->exists() || $model->deliveries()->withTrashed()->exists())) {
            return 'it has purchase order or delivery history.';
        }

        if ($type === 'purchase_order' && ($model->deliveries()->withTrashed()->exists() || $model->invoices()->withTrashed()->exists())) {
            return 'it has linked delivery or commercial invoice records.';
        }

        if ($type === 'delivery' && ($model->posted_at !== null || $model->invoices()->withTrashed()->exists())) {
            return 'it has been posted to stock or has linked commercial invoices.';
        }

        if ($type === 'customer_delivery' && $model->releases()->exists()) {
            return 'its delivery releases are part of the stock movement history.';
        }

        if ($type === 'commercial_invoice' && $model->status === CommercialInvoice::VERIFIED) {
            return 'verified commercial invoices are part of the stock and financial audit trail.';
        }

        return null;
    }

    /**
     * Find a trashed model record by type and ID.
     */
    protected function findTrashedRecord(Team $team, string $type, int|string $id): mixed
    {
        if (! isset(self::TYPE_REGISTRY[$type])) {
            abort(404, "Invalid record type: {$type}");
        }

        $modelClass = self::TYPE_REGISTRY[$type]['class'];

        return $modelClass::onlyTrashed()
            ->where('team_id', $team->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Get a descriptive title for a model record.
     */
    protected function getRecordTitle(mixed $model, string $type): string
    {
        return match ($type) {
            'product' => (string) ($model->name ?? "Product #{$model->id}"),
            'supplier' => (string) ($model->name ?? "Supplier #{$model->id}"),
            'client' => (string) ($model->company_name ?? "Client #{$model->id}"),
            'quotation' => (string) ($model->quotation_number ?? "Quotation #{$model->id}"),
            'purchase_order' => (string) ($model->po_number ?? "PO #{$model->id}"),
            'purchase_request' => (string) ($model->request_number ?? "PR #{$model->id}"),
            'commercial_invoice' => (string) ($model->invoice_number ?? "Invoice #{$model->id}"),
            'delivery' => (string) ($model->delivery_number ?? "Delivery #{$model->id}"),
            'customer_delivery' => (string) ($model->fulfillment_number ?? "Customer delivery #{$model->id}"),
            'inquiry' => (string) ($model->subject ?? ($model->name ? "Inquiry from {$model->name}" : "Inquiry #{$model->id}")),
            'sale' => (string) ($model->order_number ?? $model->invoice_number ?? "Sale #{$model->id}"),
            default => "#{$model->id}",
        };
    }

    /**
     * Format a trashed Eloquent model into a standardized array for the frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatTrashedRecord(mixed $item, string $typeKey, array $config): array
    {
        $deletedBy = $item->deletedBy;
        $title = $this->getRecordTitle($item, $typeKey);

        $identifier = match ($typeKey) {
            'product' => "SKU: {$item->sku}",
            'supplier' => $item->contact_person ? "Contact: {$item->contact_person}" : ($item->phone ?: 'No contact specified'),
            'client' => $item->contact_person ? "Contact: {$item->contact_person}" : ($item->email ?: 'Client record'),
            'quotation' => $item->client_name ? "Client: {$item->client_name}" : 'Quotation document',
            'purchase_order' => $item->item_sku ? "Item: {$item->item_sku}" : 'Purchase order',
            'purchase_request' => $item->status ? "Status: {$item->status}" : 'Purchase request',
            'commercial_invoice' => 'Date: '.($item->invoice_date?->format('M d, Y') ?? 'N/A'),
            'delivery' => 'PO: '.($item->purchase_order_number ?: 'N/A'),
            'customer_delivery' => 'Recipient: '.($item->recipient_name ?: 'N/A'),
            'inquiry' => "From: {$item->name} ({$item->email})",
            'sale' => 'Client: '.($item->client_name ?: 'Walk-in / Client'),
            default => "ID: {$item->id}",
        };

        // The record's main amount (stored USD), formatted in the browser in the user's display
        // currency; the summary holds the rest.
        $amount = match ($typeKey) {
            'product' => $item->price,
            'quotation', 'sale' => $item->grand_total,
            'purchase_order', 'commercial_invoice' => $item->total_cost,
            'purchase_request' => $item->estimated_cost,
            default => null,
        };

        $summary = match ($typeKey) {
            'product' => sprintf('%d %s', (int) $item->stock, $item->unit ?: 'pcs'),
            'supplier' => $item->email ?: ($item->phone ?: 'Active supplier profile'),
            'client' => $item->phone ?: ($item->email ?: 'Client profile'),
            'quotation' => $item->status instanceof \BackedEnum ? $item->status->label() : (string) $item->status,
            'purchase_order' => sprintf('%d %s', (int) $item->quantity, $item->unit_of_measure ?: 'units'),
            'purchase_request', 'commercial_invoice' => sprintf('Qty: %d', (int) $item->quantity),
            'delivery' => sprintf('Qty: %d %s • %s', (int) $item->quantity, $item->unit ?: 'units', $item->delivery_date?->format('M d, Y') ?? 'N/A'),
            'customer_delivery' => ($item->status instanceof \BackedEnum ? $item->status->label() : (string) $item->status).' • '.($item->fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery'),
            'inquiry' => $item->message ? Str::limit($item->message, 80) : 'Customer contact inquiry',
            'sale' => $item->status instanceof \BackedEnum ? $item->status->label() : ($item->payment_method ?: 'Sale'),
            default => '',
        };

        // Extract key attribute pairs for the "View Details" modal
        $rawAttributes = $item->getAttributes();
        $details = [];

        foreach ($rawAttributes as $k => $v) {
            if (in_array($k, ['deleted_at', 'deleted_by_id', 'team_id', 'password', 'remember_token'])) {
                continue;
            }
            $cleanKey = ucwords(str_replace('_', ' ', $k));
            $formattedVal = is_null($v) ? '—' : (is_bool($v) ? ($v ? 'Yes' : 'No') : (is_array($v) ? json_encode($v) : (string) $v));
            $details[] = [
                'label' => $cleanKey,
                'value' => $formattedVal,
            ];
        }

        return [
            'id' => $item->id,
            'type' => $typeKey,
            'type_label' => $config['label'],
            'badge' => $config['badge'],
            'title' => $title,
            'identifier' => $identifier,
            'summary' => $summary,
            'amount' => $amount !== null ? (float) $amount : null,
            'deleted_at_raw' => $item->deleted_at?->toIso8601String(),
            'deleted_at' => $item->deleted_at?->format('M d, Y h:i A') ?? 'N/A',
            'deleted_at_human' => $item->deleted_at?->diffForHumans() ?? 'Recently',
            'created_at' => $item->created_at?->format('M d, Y') ?? 'N/A',
            'deleted_by' => $deletedBy ? [
                'id' => $deletedBy->id,
                'name' => $deletedBy->name,
                'email' => $deletedBy->email,
                'role' => method_exists($deletedBy, 'roleLabel') ? $deletedBy->roleLabel() : ucfirst($deletedBy->role ?? 'User'),
            ] : null,
            'details' => $details,
        ];
    }

    /**
     * Resolve the active team model context.
     */
    protected function resolveTeam(mixed $current_team, Request $request): Team
    {
        $team = $request->user()?->currentTeam;
        if ($team) {
            return $team;
        }

        if (is_string($current_team) && $current_team !== '') {
            return Team::where('slug', $current_team)->firstOrFail();
        }

        $fallbackTeam = $request->user()?->teams()->first() ?: Team::first();
        if ($fallbackTeam) {
            return $fallbackTeam;
        }

        abort(403, 'No active team context available.');
    }
}
