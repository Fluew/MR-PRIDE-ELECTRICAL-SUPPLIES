<?php

namespace App\Services\Procurement;

use App\Models\BiddingEvent;
use App\Services\MaterialList\ExtractionException;
use App\Support\DateNormalizer;

/**
 * Reads a supplier's quotation for a bidding event, with the same logic as the purchase order
 * import:
 *  - a document (text PDF, scanned PDF, PNG, JPG) is read by PurchaseOrderReader (OCR for scans
 *    and photos);
 *  - a CSV uses the purchase order import columns: ORDER DATE, PURCHASE ORDER NO., QTY ORDERED,
 *    SUPPLIER, ITEM/SKU Number, Description, UNIT COST, U/M, EXTENDED COST.
 *
 * The result is shown in "Record quotation" for checking: a bid is one product line, so the row
 * matching the requested product is suggested (by SKU, then by description) and the user picks
 * the row whose UNIT COST and QTY become the bid. Nothing is saved here.
 */
class BidQuotationReader
{
    public const MAX_KB = 25 * 1024;

    private const CSV_COLUMNS = [
        'date' => ['orderdate', 'date', 'podate', 'quotationdate'],
        'reference' => ['purchaseorderno', 'purchaseordernumber', 'pono', 'ponumber', 'purchaseorder', 'quotationno', 'quotationnumber', 'reference'],
        'quantity' => ['quantity', 'qty', 'qtyordered', 'quantityordered', 'qtyoffered'],
        'supplier' => ['supplier', 'suppliername', 'vendor', 'vendorname'],
        'item_sku' => ['itemskunumber', 'itemsku', 'skunumber', 'sku', 'itemno', 'itemnumber'],
        'description' => ['description', 'desc', 'itemdescription'],
        'unit_cost' => ['unitcost', 'cost', 'unitprice', 'price', 'rate'],
        'unit_of_measure' => ['um', 'uom', 'unitofmeasure', 'unit', 'measure'],
        'extended_cost' => ['extendedcost', 'extended', 'extcost', 'totalcost', 'amount'],
    ];

    public function __construct(private PurchaseOrderReader $documents) {}

    /**
     * @return array{method: string, ocr: bool, header: array, rows: list<array>, suggested_row: ?int, notes: list<string>}
     *
     * @throws ExtractionException with a message for staff
     */
    public function read(string $path, string $originalName, BiddingEvent $event): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $result = in_array($extension, ['csv', 'txt'], true)
            ? $this->readCsv($path)
            : $this->readDocument($path, $originalName, $event->team_id);

        $result['header']['supplier'] = $this->invitedSupplier($result['header']['supplier'], $event);
        $result['suggested_row'] = $this->suggestRow($result['rows'], $event);
        if ($result['rows'] !== [] && $result['suggested_row'] === null) {
            $result['notes'][] = 'None of the products matches the requested item by SKU or description. Choose the row that is the supplier\'s offer for it.';
        }
        $result['notes'][] = 'Lead time is not part of the document: enter the delivery lead time the supplier stated.';

        return $result;
    }

    // ------------------------------------------------------------------ documents

    private function readDocument(string $path, string $originalName, int $teamId): array
    {
        $read = $this->documents->read($path, $originalName, $teamId);
        $h = $read['header'];

        return [
            'method' => $read['method'],
            'ocr' => $read['ocr'],
            'header' => [
                'quotation_date' => $h['po_date'],
                'reference' => $h['po_number'],
                'supplier' => $h['supplier'],
            ],
            'rows' => $read['rows'],
            // The "already exists" purchase order check does not apply to a quotation.
            'notes' => $read['notes'],
        ];
    }

    // ------------------------------------------------------------------ CSV

    private function readCsv(string $path): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path)) ?? '';
        $raw = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $first = $raw[0] ?? '';
        $delimiter = match (true) {
            substr_count($first, "\t") > substr_count($first, ',') => "\t",
            substr_count($first, ';') > substr_count($first, ',') => ';',
            default => ',',
        };
        $lines = [];
        foreach ($raw as $line) {
            $cells = str_getcsv($line, $delimiter);
            if (array_filter($cells, fn ($v) => trim((string) $v) !== '') !== []) {
                $lines[] = $cells;
            }
        }
        if (count($lines) < 2) {
            throw new ExtractionException('The CSV file is empty or has no data rows under its headers.', 'no_text');
        }

        $headers = array_map(fn ($h) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $h)), $lines[0]);
        $idx = [];
        foreach (self::CSV_COLUMNS as $field => $names) {
            $found = array_values(array_intersect($headers, $names));
            $idx[$field] = $found === [] ? null : array_search($found[0], $headers, true);
        }
        if ($idx['quantity'] === null || $idx['description'] === null || $idx['unit_cost'] === null) {
            throw new ExtractionException('Required column headers missing. The CSV must include QTY ORDERED (or QUANTITY), Description and UNIT COST; ORDER DATE, PURCHASE ORDER NO., SUPPLIER, ITEM/SKU Number, U/M and EXTENDED COST are read when present.', 'unsupported');
        }

        $cell = fn (array $row, string $f) => $idx[$f] === null ? '' : trim((string) ($row[$idx[$f]] ?? ''));
        $rows = [];
        $notes = [];
        $dates = [];
        $references = [];
        $suppliers = [];
        foreach (array_slice($lines, 1) as $line) {
            $rows[] = $this->csvRow(fn (string $f) => $cell($line, $f));
            foreach (['date' => &$dates, 'reference' => &$references, 'supplier' => &$suppliers] as $f => &$list) {
                if (($v = $cell($line, $f)) !== '') {
                    $list[$v] = true;
                }
            }
            unset($list);
            if (count($rows) >= PurchaseOrderReader::MAX_ROWS) {
                $notes[] = 'Only the first '.PurchaseOrderReader::MAX_ROWS.' rows were read.';
                break;
            }
        }

        return [
            'method' => 'csv',
            'ocr' => false,
            'header' => [
                'quotation_date' => $this->csvDate(array_keys($dates)),
                'reference' => $this->single(array_keys($references), 'PURCHASE ORDER NO.'),
                'supplier' => ['name' => array_key_first($suppliers), 'supplier_id' => null, 'matched_name' => null,
                    'issue' => count($suppliers) > 1 ? 'The file names more than one SUPPLIER; the first one is shown. Check it.' : null],
            ],
            'rows' => $rows,
            'notes' => $notes,
        ];
    }

    /** One CSV row, in the same shape (and with the same checks) as a row read from a document. */
    private function csvRow(callable $cell): array
    {
        $issues = [];
        [$quantity, $issue] = PurchaseOrderReader::quantity($cell('quantity'));
        if ($issue) {
            $issues['quantity'] = $issue;
        }
        $unitCost = PurchaseOrderReader::money($cell('unit_cost'));
        if ($unitCost['issue']) {
            $issues['unit_cost'] = $unitCost['issue'];
        }
        $extended = $cell('extended_cost') === '' ? ['value' => null, 'issue' => null] : PurchaseOrderReader::money($cell('extended_cost'));
        $description = preg_replace('/\s+/u', ' ', $cell('description')) ?? '';
        $um = strtoupper($cell('unit_of_measure'));
        if ($description === '') {
            $issues['description'] = 'Description is missing.';
        }
        if ($um === '') {
            $issues['unit_of_measure'] = 'U/M was not found. Enter it (PCS, BOX, SET, …).';
        }
        $calculated = $quantity !== null && $unitCost['value'] !== null ? round($quantity * $unitCost['value'], 2) : null;
        if ($extended['issue']) {
            $issues['extended_cost'] = $extended['issue'];
        } elseif ($calculated !== null && $extended['value'] !== null && abs($calculated - $extended['value']) > 0.01) {
            $issues['extended_cost'] = 'The file shows '.number_format($extended['value'], 2).', but QTY ORDERED × UNIT COST = '.number_format($calculated, 2).'. Check the quantity and unit cost.';
        }

        return [
            'quantity' => $quantity,
            'item_sku' => $cell('item_sku'),
            'description' => $description,
            'unit_cost' => $unitCost['value'],
            'unit_of_measure' => $um,
            'document_extended_cost' => $extended['value'],
            'calculated_cost' => $calculated,
            'issues' => $issues,
        ];
    }

    /** @return array{value: ?string, original: ?string, issue: ?string} */
    private function csvDate(array $values): array
    {
        if ($values === []) {
            return ['value' => null, 'original' => null, 'issue' => 'ORDER DATE was not found. Enter the quotation date.'];
        }
        $date = DateNormalizer::parse($values[0], DateNormalizer::MDY);
        $issue = $date['value'] === null ? ($date['problem'] ?? 'The date could not be read.') : null;
        if (count($values) > 1) {
            $issue = trim(($issue ?? '').' The file has more than one ORDER DATE; the first one is shown.');
        }

        return ['value' => $date['value'], 'original' => $values[0], 'issue' => $issue];
    }

    /** @return array{value: ?string, issue: ?string} */
    private function single(array $values, string $label): array
    {
        return [
            'value' => $values === [] ? null : mb_substr($values[0], 0, 100),
            'issue' => count($values) > 1 ? "The file has more than one {$label}; the first one is shown." : null,
        ];
    }

    // ------------------------------------------------------------------ matching to the event

    /** The supplier must be one of the event's invited suppliers that has no quotation yet. */
    private function invitedSupplier(array $supplier, BiddingEvent $event): array
    {
        $event->loadMissing('invitations.supplier:id,name');
        $quoted = $event->bids()->pluck('supplier_id')->all();
        $key = fn (?string $n) => trim(preg_replace('/\s+/', ' ', preg_replace('/\b(inc|corp|corporation|co|company|ltd|llc)\b\.?/i', '', preg_replace('/[^a-z0-9& ]/', ' ', str_replace('&', ' and ', mb_strtolower((string) $n))) ?? '') ?? '') ?? '');

        $invitation = null;
        foreach ($event->invitations as $i) {
            if (($supplier['supplier_id'] ?? null) === $i->supplier_id || ($supplier['name'] && $key($i->supplier->name) === $key($supplier['name']))) {
                $invitation = $i;
                break;
            }
        }

        $issue = match (true) {
            ! $supplier['name'] => 'The supplier was not found in the file. Choose the invited supplier.',
            $invitation === null => "\"{$supplier['name']}\" is not one of the invited suppliers. Choose the invited supplier who sent this quotation.",
            in_array($invitation->supplier_id, $quoted, true) => "{$invitation->supplier->name} already has a recorded quotation. Use \"Revise\" on its row instead.",
            default => $supplier['issue'] ?? null,
        };
        $usable = $invitation && ! in_array($invitation->supplier_id, $quoted, true);

        return [
            'name' => $supplier['name'],
            'supplier_id' => $usable ? $invitation->supplier_id : null,
            'matched_name' => $invitation?->supplier->name,
            'issue' => $issue,
        ];
    }

    /** The row for the requested product: same SKU, else the description sharing the most words. */
    private function suggestRow(array $rows, BiddingEvent $event): ?int
    {
        if ($rows === []) {
            return null;
        }
        if (count($rows) === 1) {
            return 0;
        }
        $pr = $event->purchaseRequest()->with('product:id,name,sku')->first();
        $sku = mb_strtolower(trim((string) $pr?->product?->sku));
        if ($sku !== '') {
            foreach ($rows as $i => $row) {
                if (mb_strtolower(trim($row['item_sku'])) === $sku) {
                    return $i;
                }
            }
        }

        $words = fn (?string $s) => array_unique(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower((string) $s)) ?: [], fn ($w) => mb_strlen($w) >= 2));
        $wanted = $words($pr?->product?->name ?? $pr?->product_description);
        if ($wanted === []) {
            return null;
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($rows as $i => $row) {
            $score = count(array_intersect($wanted, $words($row['description']))) / count($wanted);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }

        return $bestScore >= 0.5 ? $best : null;
    }
}
