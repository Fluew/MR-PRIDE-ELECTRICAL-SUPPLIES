<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\HeaderMapper;
use App\Services\MaterialList\WordTable;
use App\Support\DateNormalizer;

/**
 * Reads a purchase order document (text PDF, scanned PDF, PNG or JPG) into the nine fields the
 * Purchase Order page uses, and nothing else:
 *   ORDER DATE, PURCHASE ORDER NO., SUPPLIER (once per document) and, per product,
 *   QUANTITY, ITEM/SKU Number, Description, UNIT COST, U/M, EXTENDED COST.
 *
 * - Words are read with their positions (pdftotext for text PDFs, Tesseract for scans and
 *   photos). A label's value is the text beside it or right below it ("PURCHASE ORDER NO." over
 *   "4000005769"), so ship-to, buyer, vendor code and other boxes are never taken instead.
 * - The supplier is a "Supplier"/"Vendor name" label's value or, failing that, the company name
 *   heading the first page (the largest text above every label), never a ship-to or buyer.
 * - The product table is rebuilt from the word positions (WordTable) under its column headers.
 * - EXTENDED COST is always QUANTITY × UNIT COST; a different figure in the document is shown
 *   and flagged, never silently replaced. Nothing is guessed: a value that cannot be read, or was
 *   read with low OCR confidence, is returned with an "issue" for the user to check.
 *
 * Nothing is saved here (see PurchaseRequestController::importPurchaseOrderDocument).
 */
class PurchaseOrderReader
{
    /** OCR confidence (0-100) under which a value is flagged. */
    public const LOW_CONFIDENCE = 60;

    public const MAX_ROWS = 300;

    /** Product table columns: field => rank => header wordings (normalised by HeaderMapper::normalise). */
    private const COLUMNS = [
        'quantity' => [0 => ['quantity', 'qty', 'qty ordered', 'quantity ordered', 'order qty', 'ordered qty', 'qnty', 'quant']],
        'item_sku' => [
            0 => ['item sku number', 'item sku no', 'item sku', 'sku', 'sku number', 'sku no', 'item number', 'item no', 'item code',
                'product code', 'part number', 'part no', 'stock number', 'stock no', 'catalog no', 'catalog number'],
            1 => ['item'],
        ],
        'description' => [0 => ['description', 'item description', 'product description', 'desc', 'particulars', 'descriptions']],
        'unit_cost' => [0 => ['unit cost', 'unit price', 'cost per unit', 'price per unit', 'unit rate'], 1 => ['cost', 'price', 'rate']],
        'unit_of_measure' => [0 => ['u m', 'uom', 'unit of measure', 'unit of measurement'], 1 => ['um', 'unit', 'units']],
        'extended_cost' => [
            0 => ['extended cost', 'extended', 'ext cost', 'extended price', 'ext price', 'extended amount', 'ext amount', 'line total', 'total cost'],
            1 => ['amount', 'total', 'total amount'],
        ],
    ];

    /** Label wordings (as normalised words). The value is beside or below the label. */
    private const PO_NUMBER_LABELS = [['purchase', 'order', 'no'], ['purchase', 'order', 'number'], ['purchase', 'order', '#'], ['po', 'no'], ['po', 'number'], ['po', '#'], ['p', 'o', 'no']];

    private const DATE_LABELS = [['order', 'date'], ['po', 'date'], ['p', 'o', 'date'], ['date', 'ordered'], ['purchase', 'order', 'date']];

    /** Only an explicit Supplier label: on a purchase order the VENDOR box names whoever receives the order. */
    private const SUPPLIER_LABELS = [['supplier', 'name'], ['supplier']];

    /** A word right before a label that makes it a different field ("ALTERNATE PO NUMBER", "VENDOR CODE"). */
    private const OTHER_FIELD_BEFORE = ['alternate', 'alt', 'customer', 'quotation', 'quote', 'back', 'store', 'due', 'ship', 'delivery', 'reference', 'ref', 'buyer'];

    private const OTHER_FIELD_AFTER = ['code', 'no', 'number', '#', 'id', 'tin', 'address'];

    /** Labels of the boxes that are not ours: their text is never the supplier. */
    private const NOT_SUPPLIER = ['ship to', 'bill to', 'deliver to', 'sold to', 'buyer', 'vendor', 'tin', 'customer', 'attention', 'attn', 'contact', 'tel', 'phone', 'fax', 'email', 'address'];

    /** Box labels of a purchase order form: the company heading is always above the first of them. */
    private const BOX_LABELS = '/\b(order\s+date|purchase\s+order\s+(no|number|#)|p\.?\s*o\.?\s*(no|number|#|date)|vendor|ship\s+to|bill\s+to|buyer|status|freight|ship\s+via|payment\s+terms|store\s+(no|number)|order\s+type|date\s+due|due\s+date|quotation\s+(no|number)|customer\s+(no|number)|page\s+\d)\b/i';

    /** Words that make a line look like a company name. */
    private const COMPANY_WORDS = ['construction', 'corporation', 'corp', 'inc', 'incorporated', 'co', 'company', 'ltd', 'llc', 'trading', 'enterprise',
        'enterprises', 'supply', 'supplies', 'electrical', 'electric', 'hardware', 'industries', 'industrial', 'group', 'services', 'sons',
        'builders', 'depot', 'distributors', 'distribution', 'merchandising', 'development', 'engineering', 'contractors', 'philippines'];

    public function __construct(private FileReader $reader) {}

    /**
     * @return array{method: string, ocr: bool, header: array, rows: list<array>, document_total: ?float, calculated_total: float, notes: list<string>, existing: ?string}
     *
     * @throws ExtractionException with a message for staff when the file cannot be read
     */
    public function read(string $path, string $originalName, int $teamId): array
    {
        $reader = $this->reader->forStaffImports();
        $type = $reader->detectType($path, $originalName);
        if (! in_array($type, ['pdf', 'image'], true)) {
            throw new ExtractionException('Purchase order documents must be PDF, PNG or JPG files. Use "Import CSV" for spreadsheets.', 'unsupported');
        }

        try {
            $read = $reader->readWords($path, $type);
        } catch (ExtractionException $e) {
            throw new ExtractionException(match ($e->reason) {
                'ocr_unavailable' => 'Text recognition (OCR) is not available on the server, so scanned PDFs and photos cannot be read right now. Ask an administrator to check the Tesseract installation.',
                'ocr_failed', 'pdf_render_failed' => 'Reading this document took too long or failed. Try a clearer scan or photo.',
                default => $e->getMessage(),
            }, $e->reason);
        }
        if ($read === null) {
            throw new ExtractionException('The text of this PDF could not be read with its layout (pdftotext is not available on the server).', 'unsupported');
        }

        $ocr = $read['method'] !== 'pdf-text';
        $words = self::words($read['tsv']);
        if ($words === []) {
            throw new ExtractionException('No text was found in this document. Try a clearer scan or photo.', 'no_text');
        }
        $lines = self::lines($words);
        $notes = [];

        $table = WordTable::build($read['tsv'], fn (array $cells, bool $wordLevel = false) => self::isTableHeader($cells, $wordLevel), $ocr);
        [$rows, $documentTotal, $tableNotes] = $this->rows($table, $ocr);
        $notes = [...$notes, ...$tableNotes];
        if ($rows === []) {
            $notes[] = $table['aligned']
                ? 'The product table was found, but no product rows could be read under it. Add the products below.'
                : 'No product table with QTY ORDERED, Description and UNIT COST columns was found. Add the products below.';
        }

        $header = [
            'po_number' => $this->poNumber($lines),
            'po_date' => $this->orderDate($lines, $ocr),
            'supplier' => $this->supplier($lines, $teamId),
        ];

        $calculated = round(array_sum(array_map(fn ($r) => $r['calculated_cost'] ?? 0, $rows)), 2);
        if ($documentTotal !== null && abs($documentTotal - $calculated) > 0.01) {
            $notes[] = 'The document total ('.number_format($documentTotal, 2).') is different from the sum of the products ('.number_format($calculated, 2).'). Check the quantities and costs.';
        }
        if ($ocr) {
            $notes[] = 'Read with text recognition (OCR): compare every value with the document before saving.';
        }

        $existing = null;
        if ($header['po_number']['value'] !== null
            && PurchaseOrder::where('team_id', $teamId)->where('po_number', $header['po_number']['value'])->exists()) {
            $existing = $header['po_number']['value'];
        }

        return [
            'method' => $read['method'],
            'ocr' => $ocr,
            'header' => $header,
            'rows' => $rows,
            'document_total' => $documentTotal,
            'calculated_total' => $calculated,
            'notes' => $notes,
            'existing' => $existing,
        ];
    }

    // ------------------------------------------------------------------------------ the table

    /** The field a product-table header cell names: [field, rank, exact, wording] (HeaderMapper::scan). */
    public static function column(mixed $cell): ?array
    {
        $h = HeaderMapper::normalise($cell);
        if ($h === '' || mb_strlen($h) > 40) {
            return null;
        }
        $best = null;
        foreach (self::COLUMNS as $field => $ranks) {
            foreach ($ranks as $rank => $wordings) {
                foreach ($wordings as $wording) {
                    if ($h === $wording) {
                        return [$field, $rank, true, $wording];
                    }
                    // "UNIT COST (PHP)", "QTY ORDERED (PCS)": the longest wording that starts the header.
                    if (str_starts_with($h, $wording.' ') && substr_count(mb_substr($h, mb_strlen($wording)), ' ') <= 2
                        && ($best === null || mb_strlen($wording) > mb_strlen($best[3]))) {
                        $best = [$field, $rank, false, $wording];
                    }
                }
            }
        }

        return $best;
    }

    /** A product table header: Description, Quantity, and a unit cost or extended cost column. */
    public static function isTableHeader(array $cells, bool $wordLevel = false): bool
    {
        $scan = HeaderMapper::scan($cells, fn (string $cell) => self::column($cell), $wordLevel);
        $m = $scan['mapping'];

        return $scan['header'] && isset($m['description'], $m['quantity']) && (isset($m['unit_cost']) || isset($m['extended_cost']));
    }

    /**
     * Product rows under the table header, with the document total when there is a TOTAL line.
     *
     * @return array{0: list<array>, 1: ?float, 2: list<string>}
     */
    private function rows(array $table, bool $ocr): array
    {
        $header = null;
        foreach ($table['rows'] as $i => $cells) {
            if (self::isTableHeader($cells)) {
                $header = $i;
                break;
            }
        }
        if ($header === null) {
            return [[], null, []];
        }
        $mapping = HeaderMapper::scan($table['rows'][$header], fn (string $cell) => self::column($cell))['mapping'];

        $rows = [];
        $total = null;
        $notes = [];
        $repeated = 0;
        $previousKey = null;
        foreach (array_slice($table['rows'], $header + 1, null, true) as $i => $cells) {
            if (self::isTableHeader($cells)) {
                continue; // the header repeated on a later page
            }
            $cell = fn (string $f) => isset($mapping[$f]) ? trim((string) ($cells[$mapping[$f]] ?? '')) : '';
            $conf = fn (string $f) => isset($mapping[$f]) ? (float) ($table['conf'][$i][$mapping[$f]] ?? 100) : 100.0;
            $line = implode(' ', array_filter(array_map('trim', $cells)));

            // "TOTAL 2,850.79", "GRAND TOTAL": the document total, not a product.
            if (preg_match('/\b(grand\s+total|total\s+amount|order\s+total|total|sub\s*-?\s*total)\b/i', $line) && $cell('quantity') === '') {
                $amount = self::money(preg_match_all('/\d[\d,]*\.\d{2}/', $line, $m) ? end($m[0]) : '');
                if ($amount['value'] !== null && ! preg_match('/sub/i', $line)) {
                    $total = $amount['value'];
                }

                continue;
            }
            // Only rows with a quantity or a cost are products; notes and page footers are not.
            if ($cell('quantity') === '' && $cell('unit_cost') === '' && $cell('extended_cost') === '') {
                continue;
            }

            $row = $this->row($cell, $conf, $ocr);
            $key = json_encode([$row['quantity'], $row['item_sku'], $row['description'], $row['unit_cost'], $row['unit_of_measure'], $row['document_extended_cost']]);
            if ($key === $previousKey) {
                $repeated++; // the same row read twice in a row (repeated OCR detection)

                continue;
            }
            $previousKey = $key;
            $rows[] = $row;
            if (count($rows) >= self::MAX_ROWS) {
                $notes[] = 'Only the first '.self::MAX_ROWS.' products were read.';
                break;
            }
        }
        if ($repeated > 0) {
            $notes[] = "{$repeated} repeated product row(s) were left out.";
        }

        return [$this->correctDescriptions($rows), $total, $notes];
    }

    /** One product row: values as read, the calculated EXTENDED COST, and an issue per doubtful value. */
    private function row(callable $cell, callable $conf, bool $ocr): array
    {
        $issues = [];

        [$quantity, $issue] = self::quantity($cell('quantity'));
        if ($issue) {
            $issues['quantity'] = $issue;
        }
        $unitCost = self::money($cell('unit_cost'));
        if ($unitCost['issue']) {
            $issues['unit_cost'] = $unitCost['issue'];
        }
        $extended = self::money($cell('extended_cost'));

        $sku = preg_replace('/\s+/u', ' ', $cell('item_sku')) ?? ''; // kept as text: leading zeros stay
        $description = preg_replace('/\s+/u', ' ', $cell('description')) ?? '';
        $um = strtoupper(trim(preg_replace('/\s+/u', ' ', $cell('unit_of_measure')) ?? '', " .\t"));

        if ($description === '') {
            $issues['description'] = 'Description is missing.';
        }
        if ($um === '') {
            $issues['unit_of_measure'] = 'U/M was not found. Enter it (PCS, BOX, SET, …).';
        }

        $calculated = $quantity !== null && $unitCost['value'] !== null ? round($quantity * $unitCost['value'], 2) : null;
        if ($extended['issue'] && $cell('extended_cost') !== '') {
            $issues['extended_cost'] = $extended['issue'];
        } elseif ($calculated !== null && $extended['value'] !== null && abs($calculated - $extended['value']) > 0.01) {
            $issues['extended_cost'] = 'The document shows '.number_format($extended['value'], 2).', but QTY ORDERED × UNIT COST = '.number_format($calculated, 2).'. Check the quantity and unit cost.';
        }

        if ($ocr) {
            foreach (['quantity', 'item_sku', 'description', 'unit_cost', 'unit_of_measure', 'extended_cost'] as $field) {
                if (! isset($issues[$field]) && $conf($field) < self::LOW_CONFIDENCE) {
                    $issues[$field] = 'This was hard to read. Check it against the document.';
                }
            }
        }

        return [
            'quantity' => $quantity,
            'item_sku' => $sku,
            'description' => $description,
            'unit_cost' => $unitCost['value'],
            'unit_of_measure' => $um,
            'document_extended_cost' => $extended['value'],
            'calculated_cost' => $calculated,
            'issues' => $issues,
        ];
    }

    /**
     * A quantity: a whole number ("2", "2.00", "1,000"). OCR look-alikes ("1O") are read but flagged.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function quantity(string $raw): array
    {
        $v = trim($raw);
        if ($v === '') {
            return [null, 'Quantity is missing.'];
        }
        $fixed = preg_match('/^[\dOoIl,.]+$/', $v) && preg_match('/\d/', $v) ? strtr($v, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']) : $v;
        $plain = preg_match('/^\d{1,3}(,\d{3})+(\.0+)?$/', $fixed) ? str_replace(',', '', $fixed) : $fixed;
        if (! preg_match('/^\d+(?:\.(\d+))?$/', $plain, $m)) {
            return [null, "\"{$v}\" is not a valid quantity."];
        }
        if ((float) $plain <= 0) {
            return [null, 'Quantity must be at least 1.'];
        }
        if (isset($m[1]) && (int) $m[1] !== 0) {
            return [null, "\"{$v}\" is not a whole number. Purchase order quantities are whole units."];
        }

        return [(int) $plain, $fixed !== $v ? "Read as \"{$v}\" and taken as ".(int) $plain.'. Check it.' : null];
    }

    /**
     * An amount: "62.00", "2,200.00", "₱ 1,234.50", "PHP 379". "62,00" is refused (decimal comma or
     * thousands?). OCR look-alikes are read but flagged.
     *
     * @return array{value: ?float, issue: ?string}
     */
    public static function money(string $raw): array
    {
        $v = trim(str_ireplace(['php', 'usd', '₱', '$', ' '], '', trim($raw)));
        if ($v === '') {
            return ['value' => null, 'issue' => 'Missing.'];
        }
        $fixed = preg_match('/^[\dOoIl,.]+$/', $v) && preg_match('/\d/', $v) ? strtr($v, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']) : $v;
        if (preg_match('/^\d+,\d{2}$/', $fixed)) {
            return ['value' => null, 'issue' => "\"{$raw}\" could be a decimal comma or a thousands separator. Enter the amount."];
        }
        $plain = preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $fixed) ? str_replace(',', '', $fixed) : $fixed;
        if (! preg_match('/^\d+(\.\d{1,4})?$/', $plain)) {
            return ['value' => null, 'issue' => "\"{$raw}\" is not a valid amount."];
        }

        return ['value' => round((float) $plain, 2), 'issue' => $fixed !== $v ? "Read as \"{$raw}\". Check it." : null];
    }

    /**
     * Fix an OCR look-alike in a description word ("SCHNElDER") only when the same word appears
     * correctly in at least two other rows of the document; every change is reported on the row.
     */
    private function correctDescriptions(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            foreach (array_unique(preg_split('/\s+/u', $row['description']) ?: []) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }
        $fold = fn (string $w) => strtr(mb_strtoupper($w), ['0' => 'O', '1' => 'I', 'L' => 'I', '5' => 'S', '8' => 'B']);
        $byFold = [];
        foreach ($counts as $word => $n) {
            if ($n >= 2 && preg_match('/\p{L}{3,}/u', $word)) {
                $byFold[$fold($word)][] = $word;
            }
        }

        foreach ($rows as &$row) {
            $words = preg_split('/(\s+)/u', $row['description'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $changed = [];
            foreach ($words as &$word) {
                if (trim($word) === '' || ($counts[$word] ?? 0) >= 2 || ! preg_match('/[0-9lI|]/u', $word)) {
                    continue;
                }
                $matches = array_values(array_diff($byFold[$fold($word)] ?? [], [$word]));
                if (count($matches) === 1 && mb_strlen($matches[0]) === mb_strlen($word)) {
                    $changed[] = "\"{$word}\" → \"{$matches[0]}\"";
                    $word = $matches[0];
                }
            }
            unset($word);
            if ($changed) {
                $row['description'] = implode('', $words);
                $row['issues']['description'] = 'Corrected '.implode(', ', $changed).' as written elsewhere in the document. Check it.';
            }
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------------------ the header

    /** @return array{value: ?string, issue: ?string} */
    private function poNumber(array $lines): array
    {
        $found = $this->labelled($lines, self::PO_NUMBER_LABELS, fn (string $t) => preg_match('/^[A-Z0-9][A-Z0-9\-\/]{2,29}$/i', $t) && preg_match('/\d/', $t) ? $t : null);
        if ($found === null) {
            return ['value' => null, 'issue' => 'PURCHASE ORDER NO. was not found. Enter it from the document.'];
        }

        return ['value' => $found['value'], 'issue' => $found['conf'] < self::LOW_CONFIDENCE ? 'This was hard to read. Check it against the document.' : null];
    }

    /** @return array{value: ?string, original: ?string, issue: ?string} */
    private function orderDate(array $lines, bool $ocr): array
    {
        $issue = null;
        $parse = function (string $text) use ($ocr, &$issue): ?string {
            $issue = null;
            $t = trim($text, " ,.\t");
            // "1/13/23": a two-digit year is read as 20YY and flagged (the shared parser refuses it).
            if (preg_match('/^(\d{1,2})([\/\-.])(\d{1,2})\2(\d{2})$/', $t, $m)) {
                $t = "{$m[1]}/{$m[3]}/20{$m[4]}";
                $issue = "The year is written as \"{$m[4]}\" and was read as 20{$m[4]}. Check the date.";
            }
            $date = DateNormalizer::parse($t, DateNormalizer::MDY, $ocr);
            if ($date['value'] === null) {
                return null;
            }
            if ($date['by_preference']) {
                $issue = trim(($issue ?? '').' Read as month/day/year. Check the date.');
            }

            return $date['value'];
        };

        $found = $this->labelled($lines, self::DATE_LABELS, $parse, 3);
        if ($found === null) {
            return ['value' => null, 'original' => null, 'issue' => 'ORDER DATE was not found. Enter it from the document.'];
        }
        if ($found['conf'] < self::LOW_CONFIDENCE) {
            $issue = trim(($issue ?? '').' This was hard to read. Check it against the document.');
        }

        return ['value' => $found['value'], 'original' => $found['text'], 'issue' => $issue];
    }

    /**
     * The supplier: a Supplier/Vendor name label's value, or else the company name heading the
     * first page. Matched to the team's suppliers by name.
     *
     * @return array{name: ?string, supplier_id: ?int, matched_name: ?string, issue: ?string}
     */
    private function supplier(array $lines, int $teamId): array
    {
        $name = null;
        $sure = false;
        $found = $this->labelled($lines, self::SUPPLIER_LABELS, fn (string $t) => preg_match('/\p{L}{2,}/u', $t) ? $t : null, 6, true);
        if ($found !== null) {
            $name = $found['value'];
            $sure = $found['conf'] >= self::LOW_CONFIDENCE;
        } else {
            [$name, $sure] = $this->companyHeading($lines);
        }

        if ($name === null) {
            return ['name' => null, 'supplier_id' => null, 'matched_name' => null, 'issue' => 'The supplier could not be identified. Choose it from the list.'];
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name, " ,.:;-\t");

        $match = $this->matchSupplier($name, $teamId);
        $issue = match (true) {
            ! $sure => 'The supplier was read from the top of the document but could not be confirmed. Check it.',
            $match === null => "\"{$name}\" is not in the supplier list. Choose a supplier, or add it as a new supplier.",
            default => null,
        };

        return ['name' => $name, 'supplier_id' => $match?->id, 'matched_name' => $match?->name, 'issue' => $issue];
    }

    /**
     * The company name heading the first page: lines above the first label or the product table,
     * not a title ("PURCHASE ORDER"), address, phone, TIN or e-mail. The largest text wins; it is
     * "sure" when it looks like a company name (CONSTRUCTION, CORP, INC, TRADING, …).
     *
     * @return array{0: ?string, 1: bool}
     */
    private function companyHeading(array $lines): array
    {
        $first = array_values(array_filter($lines, fn ($l) => $l['page'] === $lines[0]['page']));
        $candidates = [];
        foreach ($first as $line) {
            if (str_contains($line['text'], ':') || self::startsWithAny(mb_strtolower($line['text']), self::NOT_SUPPLIER)
                || preg_match(self::BOX_LABELS, $line['text']) || self::isTableHeader(explode(' ', $line['text']), true)) {
                break; // the heading is above the first box label and the table
            }
            foreach (self::phrases($line) as $text) {
                $candidate = $this->headingCandidate($text, $line['height']);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }
        if ($candidates === []) {
            return [null, false];
        }

        usort($candidates, fn ($a, $b) => [$b['company'], $b['height']] <=> [$a['company'], $a['height']]);

        return [$candidates[0]['text'], $candidates[0]['company']];
    }

    /** Parts of a line separated by wide gaps: "SURANGEL & SONS CONSTRUCTION" | "PURCHASE ORDER". */
    private static function phrases(array $line): array
    {
        $phrases = [];
        $current = [];
        foreach ($line['words'] as $k => $w) {
            if ($current !== [] && 1.5 * $line['height'] < $w['x0'] - $line['words'][$k - 1]['x1']) {
                $phrases[] = implode(' ', $current);
                $current = [];
            }
            $current[] = $w['text'];
        }
        if ($current !== []) {
            $phrases[] = implode(' ', $current);
        }

        return $phrases;
    }

    /** @return array{text: string, height: float, company: bool}|null */
    private function headingCandidate(string $text, float $height): ?array
    {
        // Not the title ("PURCHASE ORDER"), an address, phone, e-mail or TIN.
        if (preg_match('/^\s*(purchase\s+order|p\.?\s*o\.?)\s*$/iu', $text) || preg_match('/\b(street|st\.|road|rd\.|avenue|ave\.|city|brgy|barangay|tel|phone|fax|e-?mail|www\.|@|tin)\b/iu', $text)) {
            return null;
        }
        $letters = preg_match_all('/\p{L}/u', $text);
        $digits = preg_match_all('/\d/', $text);
        if ($letters < 4 || $digits > $letters / 3 || self::isOwnCompany($text)) {
            return null; // our own name (on a copy we issued) is never the supplier
        }
        $words = preg_split('/[^\p{L}&]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return ['text' => $text, 'height' => $height, 'company' => (bool) array_intersect($words, self::COMPANY_WORDS) || str_contains($text, '&')];
    }

    private static function isOwnCompany(string $text): bool
    {
        $squash = fn (string $s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)) ?? '';
        $own = $squash((string) config('company.name', 'MR-PRIDE Electrical Supplies'));
        $short = $squash((string) preg_replace('/\s+electrical.*$/i', '', (string) config('company.name', '')));

        return $own !== '' && (str_contains($squash($text), $own) || ($short !== '' && $squash($text) === $short));
    }

    private function matchSupplier(string $name, int $teamId): ?Supplier
    {
        $key = fn (?string $n) => trim(preg_replace('/\s+/', ' ', preg_replace('/\b(inc|corp|corporation|co|company|ltd|llc)\b\.?/i', '', preg_replace('/[^a-z0-9& ]/', ' ', str_replace('&', ' and ', mb_strtolower((string) $n))) ?? '') ?? '') ?? '');
        $wanted = $key($name);
        foreach (Supplier::where('team_id', $teamId)->get(['id', 'name']) as $supplier) {
            if ($key($supplier->name) === $wanted) {
                return $supplier;
            }
        }

        return null;
    }

    /**
     * The value of a label: the text after it on its line, or else the text right below it.
     * $accept turns candidate text into the value (null to refuse it).
     *
     * @param  list<list<string>>  $labels
     * @return array{value: string, text: string, conf: float}|null
     */
    private function labelled(array $lines, array $labels, callable $accept, int $maxWords = 1, bool $phrase = false): ?array
    {
        usort($labels, fn ($a, $b) => count($b) <=> count($a)); // "purchase order no" before "po"
        foreach ($lines as $li => $line) {
            $words = $line['words'];
            $norm = array_map(fn ($w) => self::norm($w['text']), $words);
            foreach ($labels as $label) {
                for ($i = 0; $i + count($label) <= count($words); $i++) {
                    if (array_slice($norm, $i, count($label)) !== $label) {
                        continue;
                    }
                    $end = $i + count($label) - 1;
                    if (in_array($norm[$i - 1] ?? '', self::OTHER_FIELD_BEFORE, true)) {
                        continue;
                    }
                    if (in_array($label, self::SUPPLIER_LABELS, true) && in_array($norm[$end + 1] ?? '', self::OTHER_FIELD_AFTER, true)) {
                        continue; // "VENDOR CODE", "SUPPLIER NO."
                    }
                    $labelBox = self::box(array_slice($words, $i, count($label)));

                    // Beside: the words after the label on the same line, up to the next wide gap.
                    $beside = [];
                    for ($k = $end + 1; $k < count($words); $k++) {
                        $gap = $words[$k]['x0'] - ($k === $end + 1 ? $labelBox['x1'] : $words[$k - 1]['x1']);
                        if ($gap > 4 * $line['height'] || ($beside !== [] && $gap > 1.5 * $line['height'])) {
                            break;
                        }
                        $beside[] = $words[$k];
                    }
                    if (($value = $this->accept($beside, $accept, $maxWords, $phrase)) !== null) {
                        return $value;
                    }

                    // Below: the nearest line under the label whose words sit under it.
                    for ($n = $li + 1; $n < count($lines) && $lines[$n]['page'] === $line['page']; $n++) {
                        $below = $lines[$n];
                        if (3 * $line['height'] < $below['y0'] - $labelBox['y1']) {
                            break;
                        }
                        $under = array_values(array_filter($below['words'], fn ($w) => $w['x1'] > $labelBox['x0'] - $line['height'] && $w['x0'] < $labelBox['x1'] + 4 * $line['height']));
                        if (($value = $this->accept($under, $accept, $maxWords, $phrase)) !== null) {
                            return $value;
                        }
                        if ($under !== []) {
                            break;
                        }
                    }
                }
            }
        }

        return null;
    }

    /** The first acceptable value among the words: one token, a few joined tokens, or the whole phrase. */
    private function accept(array $words, callable $accept, int $maxWords, bool $phrase): ?array
    {
        $words = array_values(array_filter($words, fn ($w) => ! in_array(self::norm($w['text']), [':', '#', 'no', '-'], true) || $phrase));
        $words = array_values(array_filter($words, fn ($w) => trim($w['text'], ':#.- ') !== ''));
        if ($words === []) {
            return null;
        }
        $lengths = $phrase ? [min(count($words), $maxWords)] : range(1, min($maxWords, count($words)));
        foreach ($lengths as $n) {
            $slice = array_slice($words, 0, $n);
            $text = trim(implode(' ', array_map(fn ($w) => $w['text'], $slice)), " :\t");
            $value = $accept($text);
            if ($value !== null) {
                return ['value' => $value, 'text' => $text, 'conf' => min(array_map(fn ($w) => $w['conf'], $slice))];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------ words and lines

    /** "P.O." → "po", "NO.:" → "no", "Supplier:" → "supplier". */
    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '#') {
            return '#';
        }

        return preg_replace('/[^\p{L}\p{N}#]/u', '', $t) ?? $t;
    }

    private static function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($text, $p)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{x0: float, x1: float, y0: float, y1: float} */
    private static function box(array $words): array
    {
        return [
            'x0' => min(array_column($words, 'x0')), 'x1' => max(array_column($words, 'x1')),
            'y0' => min(array_column($words, 'y0')), 'y1' => max(array_column($words, 'y1')),
        ];
    }

    /** Words with their page and box from TSV (one string per page, or several pages in one). */
    private static function words(array $tsvPages): array
    {
        $words = [];
        foreach (array_values($tsvPages) as $p => $tsv) {
            $rows = preg_split('/\r\n|\n|\r/', trim($tsv)) ?: [];
            $head = array_flip(explode("\t", (string) array_shift($rows)));
            if (! isset($head['level'], $head['left'], $head['top'], $head['width'], $head['height'], $head['text'])) {
                continue;
            }
            foreach ($rows as $raw) {
                $f = explode("\t", $raw);
                $text = trim(preg_replace('/^[|¦]+|[|¦]+$/u', '', trim($f[$head['text']] ?? '')) ?? '');
                if (($f[$head['level']] ?? '') !== '5' || $text === '' || str_starts_with($text, '###')) {
                    continue;
                }
                $conf = isset($head['conf']) && (float) ($f[$head['conf']] ?? -1) >= 0 ? (float) $f[$head['conf']] : 100.0;
                if ($conf < 15 && ! preg_match('/[\p{L}\p{N}]{2}/u', $text)) {
                    continue; // specks and stray lines
                }
                $x = (float) $f[$head['left']];
                $y = (float) $f[$head['top']];
                $words[] = [
                    'page' => $p * 1000 + (int) ($f[$head['page_num']] ?? 1), 'text' => $text, 'conf' => $conf,
                    'x0' => $x, 'x1' => $x + (float) $f[$head['width']], 'y0' => $y, 'y1' => $y + (float) $f[$head['height']],
                ];
            }
        }

        return $words;
    }

    /** Words grouped into lines (by page, then top to bottom; each line left to right). */
    private static function lines(array $words): array
    {
        usort($words, fn ($a, $b) => [$a['page'], ($a['y0'] + $a['y1']) / 2] <=> [$b['page'], ($b['y0'] + $b['y1']) / 2]);
        $lines = [];
        foreach ($words as $w) {
            $mid = ($w['y0'] + $w['y1']) / 2;
            $h = max(1.0, $w['y1'] - $w['y0']);
            $last = array_key_last($lines);
            if ($last !== null && $lines[$last]['page'] === $w['page'] && abs($mid - $lines[$last]['mid']) <= 0.5 * max($h, $lines[$last]['height'])) {
                $lines[$last]['words'][] = $w;

                continue;
            }
            $lines[] = ['page' => $w['page'], 'mid' => $mid, 'height' => $h, 'words' => [$w]];
        }
        foreach ($lines as &$line) {
            usort($line['words'], fn ($a, $b) => $a['x0'] <=> $b['x0']);
            $heights = array_map(fn ($w) => $w['y1'] - $w['y0'], $line['words']);
            sort($heights);
            $line['height'] = max(1.0, (float) $heights[intdiv(count($heights), 2)]);
            $line['y0'] = min(array_column($line['words'], 'y0'));
            $line['text'] = implode(' ', array_column($line['words'], 'text'));
        }
        unset($line);

        return $lines;
    }
}
