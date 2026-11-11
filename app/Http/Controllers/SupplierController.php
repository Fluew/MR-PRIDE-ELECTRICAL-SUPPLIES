<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Services\AuditLogger;
use App\Services\Suppliers\SupplierEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /**
     * Suppliers with their record-based evaluation (see SupplierEvaluator).
     */
    public function index(Request $request, SupplierEvaluator $evaluator): Response
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        $search = $request->input('search');
        $status = $request->input('status', 'All');
        $sort = $request->input('sort', 'score_desc');

        $query = Supplier::where('team_id', $team->id);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        $evaluations = $evaluator->forTeam($team->id);
        $all = $query->orderBy('name', $sort === 'name_desc' ? 'desc' : 'asc')
            ->get(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'status'])
            ->each(fn ($s) => $s->setAttribute('evaluation', $evaluations[$s->id] ?? null));

        if (! in_array($sort, ['name_asc', 'name_desc'], true)) {
            // Complete scores first (highest first), then incomplete ones by name.
            $all = $all->sortBy([
                fn ($a, $b) => ($b->evaluation['complete'] ?? false) <=> ($a->evaluation['complete'] ?? false),
                fn ($a, $b) => ($b->evaluation['score'] ?? -1) <=> ($a->evaluation['score'] ?? -1),
                fn ($a, $b) => strcmp($a->name, $b->name),
            ])->values();
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $suppliers = new LengthAwarePaginator($all->forPage($page, 15)->values(), $all->count(), 15, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'weights' => config('evaluation.supplier.weights'),
            'periodMonths' => (int) config('evaluation.supplier.period_months'),
            'products' => Product::where('team_id', $team->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'unit']),
            'canVerify' => $request->user()->isAdmin(),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'sort' => $sort,
            ],
        ]);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ]);

        $validated['team_id'] = $team->id;

        $supplier = Supplier::create($validated);
        AuditLogger::record(
            'supplier.created',
            "Created supplier {$supplier->name}.",
            $supplier,
            ['name' => $supplier->name, 'status' => $supplier->status],
            $request->user()
        );

        return back()->with('success', 'Supplier created successfully.');
    }

    /**
     * Update the specified supplier.
     */
    public function update(Request $request, mixed $current_team = null, mixed $supplier = null): RedirectResponse
    {
        $supplier = $this->resolveSupplier($supplier, $current_team, $request);
        $team = $request->user()->currentTeam;
        abort_if(! $team || $supplier->team_id !== $team->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ]);

        $supplier->update($validated);
        AuditLogger::record(
            'supplier.updated',
            "Updated supplier {$supplier->name}.",
            $supplier,
            ['name' => $supplier->name, 'status' => $supplier->status],
            $request->user()
        );

        return back()->with('success', 'Supplier updated successfully.');
    }

    /**
     * Remove the specified supplier.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $supplier = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $supplier = $this->resolveSupplier($supplier, $current_team, $request);
        $team = $request->user()->currentTeam;
        abort_if(! $team || $supplier->team_id !== $team->id, 403);

        // Prevent deletion if supplier has products
        if ($supplier->products()->exists()) {
            return back()->withErrors(['supplier' => 'Cannot delete supplier with linked products. Reassign products first.']);
        }

        AuditLogger::record(
            'supplier.deleted',
            "Moved supplier {$supplier->name} to Recycle Bin.",
            $supplier,
            ['name' => $supplier->name],
            $request->user()
        );
        $supplier->delete();

        return back()->with('success', 'Supplier deleted successfully.');
    }

    /**
     * The records behind a supplier's evaluation: its quotes and product availability.
     */
    public function records(Request $request, mixed $current_team = null, mixed $supplier = null): JsonResponse
    {
        $supplier = $this->resolveSupplier($supplier, $current_team, $request);
        $this->authorizeTeam($request, $supplier->team_id);

        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return response()->json([
            'quotes' => SupplierQuote::where('supplier_id', $supplier->id)->with(['product:id,name,sku', 'verifiedBy:id,name'])
                ->orderByDesc('quoted_on')->orderByDesc('id')->get()
                ->map(fn ($q) => [
                    'id' => $q->id,
                    'product' => $q->product?->only(['id', 'name', 'sku']),
                    'reference' => $q->reference,
                    'unit_price' => (float) $q->unit_price,
                    'currency' => $q->currency,
                    'unit' => $q->unit,
                    'tax_inclusive' => $q->tax_inclusive,
                    'quoted_on' => $q->quoted_on->toDateString(),
                    'valid_until' => $q->valid_until?->toDateString(),
                    'expired' => $q->valid_until !== null && $q->valid_until->lt(today()),
                    'notes' => $q->notes,
                    'verified_at' => $q->verified_at?->toIso8601String(),
                    'verified_by' => $person($q->verifiedBy),
                ]),
            'availability' => ProductSupplier::where('supplier_id', $supplier->id)->with(['product:id,name,sku', 'verifiedBy:id,name'])
                ->orderByDesc('updated_at')->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'product' => $a->product?->only(['id', 'name', 'sku']),
                    'is_available' => $a->is_available,
                    'notes' => $a->notes,
                    'verified_at' => $a->verified_at?->toIso8601String(),
                    'verified_by' => $person($a->verifiedBy),
                ]),
        ]);
    }

    /** Record a supplier quote. It counts in Price Competitiveness only after an administrator verifies it. */
    public function storeQuote(Request $request, mixed $current_team = null, mixed $supplier = null): RedirectResponse
    {
        $supplier = $this->resolveSupplier($supplier, $current_team, $request);
        $this->authorizeTeam($request, $supplier->team_id);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('team_id', $supplier->team_id)],
            'reference' => ['nullable', 'string', 'max:100'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', Rule::in(['USD', 'PHP'])],
            'unit' => ['required', 'string', 'max:50'],
            'tax_inclusive' => ['required', 'boolean'],
            'quoted_on' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quoted_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['unit'] = mb_strtolower(trim($validated['unit']));

        $duplicate = SupplierQuote::where('supplier_id', $supplier->id)
            ->where('product_id', $validated['product_id'])
            ->whereDate('quoted_on', $validated['quoted_on'])
            ->where('unit', $validated['unit'])
            ->where('currency', $validated['currency'])
            ->where('tax_inclusive', (bool) $validated['tax_inclusive'])
            ->exists();
        if ($duplicate) {
            return back()->withErrors(['product_id' => 'This supplier already has a quote for this product, date and terms.']);
        }

        $quote = SupplierQuote::create($validated + [
            'team_id' => $supplier->team_id,
            'supplier_id' => $supplier->id,
            'recorded_by_id' => $request->user()->id,
        ]);
        AuditLogger::record('supplier_quote.recorded', "Quote recorded for {$supplier->name}.", $quote, [], $request->user());

        return back()->with('success', 'Quote recorded. An administrator must verify it before it counts.');
    }

    public function verifyQuote(Request $request, mixed $current_team = null, mixed $quote = null): RedirectResponse
    {
        $quote = $this->findTeamRecord(SupplierQuote::class, $quote ?? $request->route('quote'), $request);
        abort_unless($request->user()->isAdmin(), 403);

        $quote->update(['verified_at' => now(), 'verified_by_id' => $request->user()->id]);
        AuditLogger::record('supplier_quote.verified', 'Supplier quote verified.', $quote, [], $request->user());

        return back()->with('success', 'Quote verified.');
    }

    public function destroyQuote(Request $request, mixed $current_team = null, mixed $quote = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        $quote = $this->findTeamRecord(SupplierQuote::class, $quote ?? $request->route('quote'), $request);
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }

        AuditLogger::record('supplier_quote.removed', 'Supplier quote removed.', $quote, $quote->only(['product_id', 'unit_price', 'quoted_on']), $request->user());
        $quote->delete();

        return back()->with('success', 'Quote removed.');
    }

    /**
     * Record whether this supplier can currently supply a product. A change needs verifying again.
     */
    public function storeAvailability(Request $request, mixed $current_team = null, mixed $supplier = null): RedirectResponse
    {
        $supplier = $this->resolveSupplier($supplier, $current_team, $request);
        $this->authorizeTeam($request, $supplier->team_id);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('team_id', $supplier->team_id)],
            'is_available' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = ProductSupplier::updateOrCreate(
            ['product_id' => $validated['product_id'], 'supplier_id' => $supplier->id],
            [
                'team_id' => $supplier->team_id,
                'is_available' => $validated['is_available'],
                'notes' => $validated['notes'] ?? null,
                'recorded_by_id' => $request->user()->id,
                'verified_at' => null,
                'verified_by_id' => null,
            ],
        );
        AuditLogger::record('supplier_availability.recorded', "Availability recorded for {$supplier->name}.", $record, ['is_available' => $record->is_available], $request->user());

        return back()->with('success', 'Availability recorded. An administrator must verify it before it counts.');
    }

    public function verifyAvailability(Request $request, mixed $current_team = null, mixed $availability = null): RedirectResponse
    {
        $record = $this->findTeamRecord(ProductSupplier::class, $availability ?? $request->route('availability'), $request);
        abort_unless($request->user()->isAdmin(), 403);

        $record->update(['verified_at' => now(), 'verified_by_id' => $request->user()->id]);
        AuditLogger::record('supplier_availability.verified', 'Supplier availability verified.', $record, [], $request->user());

        return back()->with('success', 'Availability verified.');
    }

    public function destroyAvailability(Request $request, mixed $current_team = null, mixed $availability = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        $record = $this->findTeamRecord(ProductSupplier::class, $availability ?? $request->route('availability'), $request);
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }

        AuditLogger::record('supplier_availability.removed', 'Supplier availability record removed.', $record, $record->only(['product_id', 'is_available']), $request->user());
        $record->delete();

        return back()->with('success', 'Availability record removed.');
    }

    private function authorizeTeam(Request $request, int $teamId): void
    {
        abort_if($request->user()->currentTeam?->id !== $teamId, 403);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function findTeamRecord(string $class, mixed $id, Request $request)
    {
        $record = $id instanceof $class ? $id : $class::findOrFail((int) $id);
        $this->authorizeTeam($request, $record->team_id);

        return $record;
    }

    /**
     * Resolve supplier from route parameters.
     */
    protected function resolveSupplier(mixed $supplier, mixed $fallback = null, ?Request $request = null): Supplier
    {
        if ($supplier instanceof Supplier) {
            return $supplier;
        }

        $candidates = array_filter([
            $supplier,
            $fallback,
            $request?->route('supplier'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof Supplier) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = Supplier::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Supplier not found.');
    }
}
