<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierSkuMapping;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\MaterialList\ExtractionException;
use App\Services\Procurement\ProcurementWorkflow;
use App\Services\Procurement\PurchaseOrderReader;
use App\Support\DateNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PurchaseRequestController extends Controller
{
    /** Date order of the CSV import templates (DATE column: MONTH-DAY-YEAR, eg 10-25-2026). */
    private const CSV_DATE_ORDER = DateNormalizer::MDY;

    /**
     * Display procurement monitoring page.
     */
    public function index(Request $request): Response
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 403, 'No active team selected.');

        $search = $request->input('search');
        $status = $request->input('status', 'All');
        $supplierId = $request->input('supplier_id', 'All');
        $unitOfMeasure = $request->input('unit_of_measure', 'All');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        // The module's sections; anything else (including the removed commercial invoices tab) opens Purchase Orders.
        $activeTab = in_array($request->input('tab'), ['purchase_orders', 'requests'], true)
            ? $request->input('tab')
            : 'purchase_orders';

        // 1. Purchase Requests
        $query = PurchaseRequest::where('team_id', $team->id)
            ->with([
                'product:id,name,sku',
                'supplier:id,name',
                'requestedBy:id,name',
                'reviewedBy:id,name',
                'purchaseOrder:id,purchase_request_id,po_number,status',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        if ($supplierId && $supplierId !== 'All') {
            $query->where('supplier_id', (int) $supplierId);
        }

        if ($dateFrom) {
            $query->whereDate('requested_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('requested_at', '<=', $dateTo);
        }

        $requests = $query->latest('requested_at')->latest('id')->paginate(15, ['*'], 'requests_page')->withQueryString();

        // 2. Purchase Orders
        $poQuery = PurchaseOrder::where('team_id', $team->id)->with(['supplier:id,name', 'product:id,name,sku', 'purchaseRequest:id,request_number'])->withCount('deliveries');
        if ($search) {
            $poQuery->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhere('item_sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status && $status !== 'All') {
            $poQuery->where('status', $status);
        }
        if ($supplierId && $supplierId !== 'All') {
            $poQuery->where('supplier_id', (int) $supplierId);
        }
        if ($unitOfMeasure && $unitOfMeasure !== 'All') {
            $poQuery->where('unit_of_measure', $unitOfMeasure);
        }
        if ($dateFrom) {
            $poQuery->whereDate('po_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $poQuery->whereDate('po_date', '<=', $dateTo);
        }
        $purchaseOrders = $poQuery->latest('po_date')->latest('id')->paginate(15, ['*'], 'po_page')->withQueryString();

        // Summary metrics - 3 fast aggregate queries instead of 8
        $prRow = PurchaseRequest::where('team_id', $team->id)
            ->selectRaw("
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'Pending Approval' THEN 1 ELSE 0 END) as requested_count,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as ordered_count,
                SUM(CASE WHEN status = 'Converted to Purchase Order' THEN 1 ELSE 0 END) as delivered_count
            ")
            ->first();

        $poRow = PurchaseOrder::where('team_id', $team->id)
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(total_cost), 0) as total_amount')
            ->first();

        $metrics = [
            'totalRequests' => (int) ($prRow->total_requests ?? 0),
            'requestedCount' => (int) ($prRow->requested_count ?? 0),
            'orderedCount' => (int) ($prRow->ordered_count ?? 0),
            'deliveredCount' => (int) ($prRow->delivered_count ?? 0),
            'totalPurchaseOrders' => (int) ($poRow->total_count ?? 0),
            'totalPurchaseOrderAmount' => (float) ($poRow->total_amount ?? 0),
        ];

        // Available products and suppliers for forms
        $products = Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'description', 'unit', 'cost_price', 'stock', 'reorder_level']);

        // Plain arrays: cached objects come back as __PHP_Incomplete_Class (cache.serializable_classes).
        $suppliers = Cache::remember("team:{$team->id}:procurement_suppliers_v2", 300, function () use ($team) {
            return Supplier::where('team_id', $team->id)
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray();
        });

        return Inertia::render('Procurement/Index', [
            'requests' => $requests,
            'purchaseOrders' => $purchaseOrders,
            'activeTab' => $activeTab,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'supplier_id' => $supplierId,
                'unit_of_measure' => $unitOfMeasure,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'tab' => $activeTab,
            ],
            'metrics' => $metrics,
            'products' => $products,
            // "Product Not Listed" requests: the system's units, each once.
            'unitOptions' => PurchaseRequest::unitOptions($team->id),
            'suppliers' => $suppliers,
            'isAdmin' => $request->user()->isAdmin(),
            'statusOptions' => [
                'requests' => PurchaseRequest::STATUSES,
                'purchase_orders' => PurchaseOrder::STATUSES,
            ],
        ]);
    }

    /**
     * Delete (archive) a purchase request. Admin only; draft, pending or rejected requests without a purchase order.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $purchaseRequest = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $purchaseRequest = $this->resolvePurchaseRequest($purchaseRequest, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $purchaseRequest->team_id !== $team->id, 403);

        // Lock the row so a concurrent review / PO conversion cannot slip in between the checks and the delete.
        $error = DB::transaction(function () use (&$purchaseRequest) {
            $purchaseRequest = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();

            if (! in_array($purchaseRequest->status, [PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::REJECTED], true)) {
                return "Purchase request {$purchaseRequest->request_number} cannot be deleted: only draft, pending or rejected requests can be deleted; approved and converted requests are kept for the audit trail.";
            }

            // A request that already has a purchase order (even an archived one) is part of that order's history.
            if ($purchaseRequest->purchaseOrder()->withTrashed()->exists()) {
                return "Purchase request {$purchaseRequest->request_number} cannot be deleted: it is linked to a purchase order.";
            }

            // Only this request is archived (soft delete); its product, supplier and any other records are untouched.
            $purchaseRequest->delete();

            return null;
        });

        if ($error) {
            return back()->with('error', $error);
        }

        AuditLogger::audit(
            'procurement.purchase_request_deleted',
            "Moved purchase request {$purchaseRequest->request_number} to the Recycle Bin.",
            $request->user(),
            null,
            [
                'purchase_request_id' => $purchaseRequest->id,
                'request_number' => $purchaseRequest->request_number,
                'status' => $purchaseRequest->status,
                'deleted_at' => $purchaseRequest->deleted_at?->toIso8601String(),
            ],
            'success',
            $request
        );

        return back()->with('success', "Purchase request {$purchaseRequest->request_number} was deleted and moved to the Recycle Bin.");
    }

    /**
     * Set the product and promised delivery date of a purchase order (used by Supplier Evaluation).
     * Once a delivery has been received against it, only an administrator may change the promised date.
     */
    /**
     * Administrator correction of a purchase order (Order Date, linked product, promised delivery
     * date), with a required reason recorded in the audit trail. The supplier's document is
     * otherwise read-only.
     */
    public function correctPurchaseOrder(Request $request, mixed $current_team = null, mixed $purchaseOrder = null): RedirectResponse
    {
        $purchaseOrder = $this->resolvePurchaseOrder($purchaseOrder, $current_team);
        $team = $this->getTeam($request);
        abort_if(! $team || $purchaseOrder->team_id !== $team->id, 403);
        abort_unless($request->user()->isAdmin(), 403, 'Only an administrator can correct a purchase order.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'po_date' => ['nullable', 'date', 'before_or_equal:today'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('team_id', $team->id)],
            'promised_delivery_date' => ['nullable', 'date'],
        ], ['reason.required' => 'Give the reason for the correction.']);

        return $this->runWorkflow(fn () => 'Purchase order '.app(ProcurementWorkflow::class)
            ->correctPurchaseOrder($purchaseOrder, $request->user(), $validated) // only the fields that were sent
            ->po_number.' corrected. The change and its reason are in the audit trail.');
    }

    private function productIdForSku(int $teamId, ?string $sku): ?int
    {
        return $sku ? Product::where('team_id', $teamId)->where('sku', trim($sku))->value('id') : null;
    }

    /**
     * Delete a purchase order.
     */
    public function destroyPurchaseOrder(Request $request, mixed $current_team = null, mixed $purchaseOrder = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $purchaseOrder = $this->resolvePurchaseOrder($purchaseOrder, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $purchaseOrder->team_id !== $team->id, 403);

        if ($purchaseOrder->deliveries()->exists()) {
            return back()->withErrors(['status' => 'Purchase orders with received deliveries cannot be deleted; close the order instead.']);
        }

        $purchaseOrder->delete();

        return back()->with('success', 'Purchase order deleted successfully.');
    }

    /**
     * Process CSV Import for Purchase Orders.
     * Headers: ORDER DATE, PURCHASE ORDER NO., QUANTITY, SUPPLIER, ITEM/SKU Number, Description, UNIT COST, U/M,
     * EXTENDED COST (SUPPLIER and EXTENDED COST optional; "Date" and "Unit Cost" are still accepted).
     */
    public function importPurchaseOrdersCsv(Request $request): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt'],
            'execute' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath() ?: $file->getPathname();
        $content = file_get_contents($filePath);
        $rawLines = preg_split('/\r\n|\n|\r/', trim($content));

        if (empty($rawLines) || count($rawLines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded CSV file is empty or missing headers.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $firstLine = $rawLines[0];
        $delimiter = ',';
        if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        } elseif (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        }

        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }
            $row = str_getcsv($rawLine, $delimiter);
            if (! empty(array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== ''))) {
                $lines[] = $row;
            }
        }

        if (count($lines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded CSV file contains no data rows.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $rawHeaders = $lines[0];
        $headers = array_map(function ($h) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return preg_replace('/[^a-z0-9]/', '', strtolower($cleaned));
        }, $rawHeaders);

        $dateIdx = $this->findHeaderIndex($headers, ['orderdate', 'date', 'podate']);
        $poIdx = $this->findHeaderIndex($headers, ['purchaseorderno', 'purchaseordernumber', 'pono', 'ponumber', 'purchaseorder']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'qtyordered', 'quantityordered', 'count']);
        $skuIdx = $this->findHeaderIndex($headers, ['itemskunumber', 'itemsku', 'skunumber', 'sku', 'itemno', 'itemnumber']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'desc', 'itemdescription']);
        $umIdx = $this->findHeaderIndex($headers, ['um', 'uom', 'unitofmeasure', 'unit', 'measure']);
        $costIdx = $this->findHeaderIndex($headers, ['unitcost', 'cost', 'unitprice', 'price', 'rate']);
        // Optional: SUPPLIER (an existing supplier's name) and EXTENDED COST (checked against QUANTITY × UNIT COST).
        $supplierIdx = $this->findHeaderIndex($headers, ['supplier', 'suppliername']);
        $extendedIdx = $this->findHeaderIndex($headers, ['extendedcost', 'extended', 'extcost', 'totalcost']);
        $suppliers = Supplier::where('team_id', $team->id)->get(['id', 'name'])->keyBy(fn ($s) => mb_strtolower(trim($s->name)));

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1 || $costIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: ORDER DATE, PURCHASE ORDER NO., QTY ORDERED (or QUANTITY), ITEM/SKU Number, Description, UNIT COST, U/M (SUPPLIER and EXTENDED COST are optional).',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $validRows = [];
        $invalidRows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $row = $lines[$i];
            if (empty(array_filter($row))) {
                continue;
            }

            $rowNumber = $i + 1;
            $errors = [];

            // 1. DATE
            $rawDate = isset($row[$dateIdx]) ? trim($row[$dateIdx]) : '';
            $parsedDate = null;
            $displayDate = $rawDate;

            // The template's DATE column is MONTH-DAY-YEAR, so that order settles ambiguous dates;
            // unambiguous DD-MM-YYYY and YYYY-MM-DD dates are accepted, invalid ones rejected.
            $date = DateNormalizer::parse($rawDate, self::CSV_DATE_ORDER);
            if ($date['status'] === DateNormalizer::EMPTY) {
                $errors[] = 'Date is required.';
            } elseif ($date['value'] === null) {
                $errors[] = $date['problem'];
            } else {
                $parsedDate = $date['value'];
                $displayDate = Carbon::createFromFormat('!Y-m-d', $parsedDate)->format('m-d-Y');
            }

            // 2. PURCHASE ORDER NO
            $rawPo = $poIdx !== -1 && isset($row[$poIdx]) ? trim($row[$poIdx]) : '';
            if ($rawPo === '') {
                $rawPo = PurchaseOrder::generatePoNumber($team->id);
            }

            // 3. QUANTITY
            $rawQty = isset($row[$qtyIdx]) ? preg_replace('/[^0-9]/', '', (string) $row[$qtyIdx]) : '0';
            $qty = is_numeric($rawQty) ? (int) $rawQty : 0;
            if ($qty <= 0) {
                $errors[] = 'Quantity must be at least 1.';
            }

            // 4. ITEM/SKU Number
            $rawSku = $skuIdx !== -1 && isset($row[$skuIdx]) ? trim($row[$skuIdx]) : '';

            // 5. DESCRIPTION
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            // 6. U/M
            $rawUm = $umIdx !== -1 && isset($row[$umIdx]) && trim($row[$umIdx]) !== '' ? trim($row[$umIdx]) : 'PCS';

            // 7. UNIT COST
            $rawCost = isset($row[$costIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$costIdx]) : '0';
            $unitCost = is_numeric($rawCost) ? (float) $rawCost : 0.00;
            if ($unitCost < 0) {
                $errors[] = 'Unit cost cannot be negative.';
            }

            // 8. EXTENDED COST = QUANTITY × UNIT COST; a different figure in the file is reported, not replaced.
            $totalCost = round($qty * $unitCost, 2);
            $rawExtended = $extendedIdx !== -1 && isset($row[$extendedIdx]) ? trim((string) $row[$extendedIdx]) : '';
            if ($rawExtended !== '') {
                $extended = preg_replace('/[^0-9.\-]/', '', $rawExtended);
                if (! is_numeric($extended)) {
                    $errors[] = "EXTENDED COST \"{$rawExtended}\" is not an amount.";
                } elseif ($qty > 0 && abs((float) $extended - $totalCost) > 0.01) {
                    $errors[] = 'EXTENDED COST '.number_format((float) $extended, 2).' does not equal QTY ORDERED × UNIT COST ('.number_format($totalCost, 2).').';
                }
            }

            // 9. SUPPLIER: an existing supplier, by name.
            $rawSupplier = $supplierIdx !== -1 && isset($row[$supplierIdx]) ? trim((string) $row[$supplierIdx]) : '';
            $supplierId = null;
            if ($rawSupplier !== '') {
                $supplierId = $suppliers->get(mb_strtolower($rawSupplier))?->id;
                if ($supplierId === null) {
                    $errors[] = "SUPPLIER \"{$rawSupplier}\" is not in the supplier list. Add the supplier first, or leave the column empty.";
                }
            }

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $rawDate,
                    'po_number' => $rawPo,
                    'supplier' => $rawSupplier,
                    'quantity' => $rawQty,
                    'item_sku' => $rawSku,
                    'description' => $desc,
                    'unit_of_measure' => $rawUm,
                    'unit_cost' => $rawCost,
                    'total_cost' => $totalCost,
                    'errors' => $errors,
                ];
            } else {
                $validRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $parsedDate,
                    'original_date' => $rawDate,
                    'display_date' => $displayDate,
                    'po_number' => $rawPo,
                    'supplier' => $rawSupplier,
                    'supplier_id' => $supplierId,
                    'quantity' => $qty,
                    'item_sku' => $rawSku,
                    'description' => $desc,
                    'unit_of_measure' => $rawUm,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ];
            }
        }

        $execute = $request->boolean('execute');

        if ($execute && count($validRows) > 0) {
            DB::transaction(function () use ($team, $validRows) {
                foreach ($validRows as $r) {
                    PurchaseOrder::create([
                        'team_id' => $team->id,
                        'po_date' => $r['date'],
                        'po_number' => $r['po_number'],
                        'supplier_id' => $r['supplier_id'],
                        'quantity' => $r['quantity'],
                        'item_sku' => $r['item_sku'] ?: null,
                        'description' => $r['description'],
                        'unit_of_measure' => $r['unit_of_measure'] ?: 'PCS',
                        'unit_cost' => $r['unit_cost'],
                        'total_cost' => $r['total_cost'],
                        // Imported POs have no approved purchase request, so they are historical records only.
                        'status' => PurchaseOrder::CLOSED,
                        'notes' => 'Imported historical record (no approved purchase request). Goods cannot be received against it.',
                    ]);
                }
            });

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully imported '.count($validRows).' purchase order(s).',
                    'count' => count($validRows),
                    'invalid_count' => count($invalidRows),
                ]);
            }

            return back()->with('success', 'Successfully imported '.count($validRows).' purchase order(s).');
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'total_rows' => count($lines) - 1,
                'valid_count' => count($validRows),
                'invalid_count' => count($invalidRows),
            ],
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ]);
    }

    /** Largest purchase order document accepted (PDF, PNG or JPG). */
    public const PO_DOCUMENT_MAX_KB = 25 * 1024;

    /**
     * Read a purchase order document (text or scanned PDF, PNG, JPG) into an editable preview:
     * ORDER DATE, PURCHASE ORDER NO., SUPPLIER and the product rows (QUANTITY, ITEM/SKU Number,
     * Description, UNIT COST, U/M, EXTENDED COST). Nothing is saved (see importPurchaseOrderDocument).
     */
    public function readPurchaseOrderDocument(Request $request, PurchaseOrderReader $reader): JsonResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:'.self::PO_DOCUMENT_MAX_KB, 'extensions:pdf,png,jpg,jpeg'],
        ], [
            'file.required' => 'Choose a purchase order document to upload.',
            'file.max' => 'The document is larger than 25 MB. Please upload a smaller file.',
            'file.uploaded' => 'The document could not be uploaded. It may be larger than 25 MB.',
            'file.extensions' => 'Purchase order documents must be PDF, PNG or JPG files.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');
        if (! app()->runningUnitTests()) {
            @set_time_limit(300); // OCR of a scanned document can take a while
        }

        try {
            $result = $reader->read($file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalName(), $team->id);
        } catch (ExtractionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Purchase order document could not be read: '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['success' => false, 'message' => 'The document could not be read. Please check the file and try again.'], 500);
        }

        return response()->json(['success' => true, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200)] + $result);
    }

    /**
     * Save a purchase order read from a document, after the user checked and corrected it. Every
     * product becomes one purchase order line (as for the CSV import), with EXTENDED COST
     * recalculated as QUANTITY × UNIT COST. Like other imported orders it is a historical record
     * (Closed): it has no approved purchase request, so goods are not received against it.
     */
    public function importPurchaseOrderDocument(Request $request): RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/_.]*$/'],
            'po_date' => ['required', 'date_format:Y-m-d'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'new_supplier_name' => ['nullable', 'string', 'max:255'],
            'file_name' => ['nullable', 'string', 'max:200'],
            'rows' => ['required', 'array', 'min:1', 'max:'.PurchaseOrderReader::MAX_ROWS],
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'rows.*.item_sku' => ['nullable', 'string', 'max:100'],
            'rows.*.description' => ['required', 'string', 'max:500'],
            'rows.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'rows.*.unit_of_measure' => ['required', 'string', 'max:50'],
        ], [
            'po_number.required' => 'Enter the PURCHASE ORDER NO.',
            'po_number.regex' => 'The PURCHASE ORDER NO. may only contain letters, numbers, dashes and slashes.',
            'po_date.required' => 'Enter the ORDER DATE.',
            'po_date.date_format' => 'Enter a valid ORDER DATE.',
            'supplier_id.exists' => 'Choose a supplier from the list.',
            'rows.required' => 'Add at least one product.',
            'rows.min' => 'Add at least one product.',
            'rows.*.quantity.required' => 'Product :position: QTY ORDERED is missing.',
            'rows.*.quantity.integer' => 'Product :position: QTY ORDERED must be a whole number.',
            'rows.*.quantity.min' => 'Product :position: QTY ORDERED must be at least 1.',
            'rows.*.description.required' => 'Product :position: Description is missing.',
            'rows.*.unit_cost.required' => 'Product :position: UNIT COST is missing.',
            'rows.*.unit_cost.numeric' => 'Product :position: UNIT COST must be an amount.',
            'rows.*.unit_cost.decimal' => 'Product :position: UNIT COST can have at most 2 decimals.',
            'rows.*.unit_of_measure.required' => 'Product :position: U/M is missing.',
        ]);

        $poNumber = trim($validated['po_number']);
        if (PurchaseOrder::where('team_id', $team->id)->where('po_number', $poNumber)->exists()) {
            return back()->withErrors(['po_number' => "Purchase order {$poNumber} already exists. It was not imported again."]);
        }

        $created = DB::transaction(function () use ($team, $validated, $poNumber, $request) {
            $supplierId = $validated['supplier_id'] ?? null;
            $newName = trim((string) ($validated['new_supplier_name'] ?? ''));
            if (! $supplierId && $newName !== '') {
                $existing = Supplier::where('team_id', $team->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($newName)])->first();
                $supplierId = ($existing ?? Supplier::create(['team_id' => $team->id, 'name' => $newName, 'status' => 'Active']))->id;
            }
            $source = trim((string) ($validated['file_name'] ?? '')) ?: 'a purchase order document';

            $lines = [];
            foreach ($validated['rows'] as $row) {
                $sku = trim((string) ($row['item_sku'] ?? ''));
                $quantity = (int) $row['quantity'];
                $unitCost = round((float) $row['unit_cost'], 2);
                $lines[] = PurchaseOrder::create([
                    'team_id' => $team->id,
                    'po_date' => $validated['po_date'],
                    'po_number' => $poNumber,
                    'supplier_id' => $supplierId,
                    'quantity' => $quantity,
                    'item_sku' => $sku !== '' ? $sku : null,
                    // Linked through the supplier's SKU mapping, else when the SKU is exactly one of our
                    // own SKUs. Never by description: unlinked products are linked or created on receipt.
                    'product_id' => $sku !== ''
                        ? (SupplierSkuMapping::productFor($team->id, $supplierId, $sku)?->id ?? Product::where('team_id', $team->id)->where('sku', $sku)->value('id'))
                        : null,
                    'description' => trim($row['description']),
                    'unit_of_measure' => mb_strtoupper(trim($row['unit_of_measure'])),
                    'unit_cost' => $unitCost,
                    'total_cost' => round($quantity * $unitCost, 2), // EXTENDED COST
                    // Pending: its goods are received with "Record Inbound P.O. Delivery".
                    'status' => PurchaseOrder::SENT,
                    'sent_at' => now(),
                    'prepared_by_id' => $request->user()?->id,
                    'notes' => "Imported from {$source} (no approved purchase request).",
                ]);
            }
            AuditLogger::record('purchase_order.imported', "Purchase order {$poNumber} imported from {$source} with ".count($lines).' product line(s).', $lines[0], ['lines' => count($lines)], $request->user());

            return $lines;
        });

        $total = number_format(collect($created)->sum(fn ($po) => (float) $po->total_cost), 2);

        return back()->with('success', "Purchase order {$poNumber} imported with ".count($created)." product(s), total {$total}.");
    }

    /**
     * Find index of header matching any candidate.
     */
    protected function findHeaderIndex(array $headers, array $candidates): int
    {
        foreach ($headers as $index => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate) {
                    return $index;
                }
            }
        }

        return -1;
    }

    /**
     * Helper to retrieve team from request or route parameter.
     */
    protected function getTeam(Request $request, mixed $teamParam = null): ?Team
    {
        if ($teamParam instanceof Team) {
            return $teamParam;
        }

        if (is_string($teamParam) && ! empty($teamParam)) {
            $found = Team::where('slug', $teamParam)->first();
            if ($found) {
                return $found;
            }
        }

        $team = $request->route('current_team') ?? $request->route('team') ?? $teamParam;
        if (is_string($team)) {
            $found = Team::where('slug', $team)->first();
            if ($found) {
                return $found;
            }
        }
        if ($team instanceof Team) {
            return $team;
        }

        return $request->user()?->currentTeam;
    }

    /**
     * Resolve purchase request from route parameters.
     */
    protected function resolvePurchaseRequest(mixed $purchaseRequest, mixed $fallback = null): PurchaseRequest
    {
        if ($purchaseRequest instanceof PurchaseRequest) {
            return $purchaseRequest;
        }

        if (is_numeric($purchaseRequest)) {
            return PurchaseRequest::findOrFail((int) $purchaseRequest);
        }

        if ($fallback instanceof PurchaseRequest) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return PurchaseRequest::findOrFail((int) $fallback);
        }

        abort(404, 'Purchase request not found.');
    }

    /**
     * Resolve purchase order from route parameters.
     */
    protected function resolvePurchaseOrder(mixed $purchaseOrder, mixed $fallback = null): PurchaseOrder
    {
        if ($purchaseOrder instanceof PurchaseOrder) {
            return $purchaseOrder;
        }

        if (is_numeric($purchaseOrder)) {
            return PurchaseOrder::findOrFail((int) $purchaseOrder);
        }

        if ($fallback instanceof PurchaseOrder) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return PurchaseOrder::findOrFail((int) $fallback);
        }

        abort(404, 'Purchase order not found.');
    }
}
