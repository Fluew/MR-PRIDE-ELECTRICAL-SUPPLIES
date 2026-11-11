<?php

namespace App\Services\MaterialList;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a client's product list for the public quotation form and returns its rows of
 * Description and Quantity (and the unit, when the file has one) for the client to review (see ProductListParser).
 *
 *  - Excel and CSV files are read directly (every worksheet), never with OCR.
 *  - PDFs with a text layer are read from it; scanned PDFs and images go through OCR
 *    (OcrEngine: orientation, deskew, contrast, noise reduction), and the table is rebuilt from
 *    word positions (WordTable), keeping each word's confidence.
 *  - Images and scanned pages must contain writing: product photos are refused.
 *
 * What was read from a file is cached by the file's SHA-256 for 30 minutes, and reading the
 * same file twice at the same time waits for the first read, so a file sent twice (or read
 * again with different columns) is only processed once.
 */
class ProductListExtractor
{
    public const CACHE_MINUTES = 30;

    public function __construct(private FileReader $reader, private OcrEngine $ocr) {}

    /**
     * @param  array<string, int>|null  $mapping  columns chosen by the client: {description, quantity, unit?}
     * @param  int|null  $headerRow  row holding the headers for $mapping (null: no header row)
     * @param  string|null  $sheet  worksheet for $mapping (spreadsheets)
     * @return array{hash: string, type: string, method: string, ocr: bool, status: string, items: list<array>, notes: list<string>, skipped: int, sheet: ?string, choice: ?array}
     *
     * @throws ExtractionException with a message the client can read
     */
    public function extract(string $path, string $originalName, ?array $mapping = null, ?int $headerRow = null, ?string $sheet = null): array
    {
        $type = $this->reader->detectType($path, $originalName);
        $hash = (string) hash_file('sha256', $path);
        $content = $this->content($path, $type, $hash);
        $ocr = in_array($content['method'], ['ocr', 'pdf-ocr'], true);

        $result = $content['tables'] !== []
            ? $this->fromTables($content, $mapping, $headerRow, $sheet)
            : $this->fromLines($content, $mapping, $headerRow);

        // A file with products in it holds writing: it need not be read again when the form is submitted.
        if ($result['status'] === 'ok') {
            Cache::put(self::textCheckKey($hash), true, now()->addHours(2));
        }

        return ['hash' => $hash, 'type' => $type, 'method' => $content['method'], 'ocr' => $ocr] + $result;
    }

    /** Cache key marking a file (by SHA-256) as checked for written text. */
    public static function textCheckKey(string $hash): string
    {
        return 'product-list:has-text:'.$hash;
    }

    /**
     * What the file holds: tables (rows, per worksheet, with OCR confidence) or text lines.
     *
     * @return array{method: string, tables: list<array{sheet: ?string, rows: array, conf: array, ref: string, reliable: bool}>, lines: list<string>, line_conf: list<float>}
     */
    private function content(string $path, string $type, string $hash): array
    {
        $key = 'product-list:content:v1:'.$hash;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            return Cache::lock('product-list:reading:'.$hash, 300)->block(150, function () use ($key, $path, $type) {
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    return $cached; // read by the same request sent a moment earlier
                }
                $content = $this->read($path, $type);
                // Only slow reads (OCR) are worth keeping; spreadsheets are read again quickly.
                if (in_array($content['method'], ['ocr', 'pdf-ocr'], true)) {
                    Cache::put($key, $content, now()->addMinutes(self::CACHE_MINUTES));
                }

                return $content;
            });
        } catch (LockTimeoutException) {
            throw new ExtractionException('This file is still being read. Please wait a moment and try again.', 'busy');
        }
    }

    private function read(string $path, string $type): array
    {
        $isHeader = fn (array $cells, bool $words = false) => ProductListHeaders::isProductHeader($cells, $words);
        $empty = ['tables' => [], 'lines' => [], 'line_conf' => []];

        if ($type === 'csv' || $type === 'xlsx' || $type === 'xls') {
            $read = $this->reader->read($path, $type);
            $tables = [];
            foreach ($read['tables'] ?? [['sheet' => null, 'rows' => $read['rows'] ?? []]] as $table) {
                $tables[] = ['sheet' => $table['sheet'], 'rows' => $table['rows'], 'conf' => [], 'ref' => 'row', 'reliable' => true];
            }
            $hasValues = false;
            foreach ($tables as $table) {
                foreach ($table['rows'] as $row) {
                    if (trim(implode('', array_map(fn ($c) => is_scalar($c) ? (string) $c : '', (array) $row))) !== '') {
                        $hasValues = true;
                        break 2;
                    }
                }
            }
            if (! $hasValues) {
                throw new ExtractionException($type === 'csv' ? 'This CSV file is empty.' : 'This spreadsheet is empty.', 'empty');
            }

            return ['method' => $read['method']] + ['tables' => $tables] + $empty;
        }

        if ($type === 'image') {
            $tsv = $this->ocr->imageToTsv($path);
            $this->assertWriting($tsv);
            $table = ['method' => 'ocr'] + WordTable::build([$tsv], $isHeader, true);
        } else {
            $table = $this->reader->readTable($path, 'pdf', $isHeader);
            if ($table !== null && $table['method'] === 'pdf-ocr') {
                $this->assertWritingInRows($table['rows']);
            }
        }

        if ($table === null) {
            // A text PDF whose word positions are not available: its plain text, line by line.
            $read = $this->reader->read($path, 'pdf');
            $lines = MaterialParser::cleanLines($read['pages'] ?? []);
            if ($lines === []) {
                throw new ExtractionException('No readable text was found in this PDF.', 'no_text');
            }

            return ['method' => $read['method'], 'tables' => [], 'lines' => $lines, 'line_conf' => array_fill(0, count($lines), 100.0)];
        }

        $rows = array_values(array_filter($table['rows'], fn ($r) => trim(implode('', $r)) !== ''));
        if ($rows === []) {
            throw new ExtractionException('No readable text was found in this file. Please upload a clearer copy.', 'no_text');
        }
        $ocr = $table['method'] !== 'pdf-text';
        if ($table['aligned']) {
            return ['method' => $table['method'], 'tables' => [[
                'sheet' => null, 'rows' => $table['rows'], 'conf' => $table['conf'] ?? [], 'ref' => 'line', 'reliable' => ! $ocr,
            ]], 'lines' => [], 'line_conf' => []];
        }

        // No header row: the text lines, with wide gaps kept as tabs.
        $lines = [];
        $lineConf = [];
        foreach ($table['rows'] as $i => $row) {
            if (trim(implode('', $row)) === '') {
                continue;
            }
            $lines[] = implode("\t", $row);
            $lineConf[] = ($table['conf'][$i] ?? []) === [] ? 100.0 : (float) min($table['conf'][$i]);
        }

        return ['method' => $table['method'], 'tables' => [], 'lines' => $lines, 'line_conf' => $lineConf];
    }

    /** OCR output of an image must show a written list, not a product photo. */
    private function assertWriting(string $tsv): void
    {
        [$lines, $coverage] = FileReader::linesFromTsv($tsv);
        if (($coverage !== null && $coverage < FileReader::MIN_IMAGE_TEXT_COVERAGE) || ! FileReader::looksLikeWrittenList($lines)) {
            throw new ExtractionException(self::NO_WRITING, 'no_text');
        }
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function assertWritingInRows(array $rows): void
    {
        if (! FileReader::looksLikeWrittenList(array_map(fn ($r) => implode(' ', $r), $rows))) {
            throw new ExtractionException(self::NO_WRITING, 'no_text');
        }
    }

    public const NO_WRITING = "We couldn't find a written product list in this file. It may be a photo, or too blurry to read. Please upload a clear picture or scan of a typed or handwritten list.";

    /** Every worksheet (or the one table of a CSV, PDF or image) with recognised columns. */
    private function fromTables(array $content, ?array $mapping, ?int $headerRow, ?string $sheet): array
    {
        $tables = $content['tables'];
        $multiple = count($tables) > 1;

        if ($mapping !== null) {
            $table = collect($tables)->firstWhere('sheet', $sheet) ?? $tables[0];
            $parsed = ProductListParser::parseTable($table['rows'], [
                'method' => $content['method'], 'mapping' => $mapping, 'header_index' => $headerRow,
                'conf' => $table['conf'], 'ref' => $table['ref'], 'reliable' => $table['reliable'],
            ]);

            return $this->single($parsed, $multiple ? $table['sheet'] : null);
        }

        $items = [];
        $notes = [];
        $skipped = 0;
        $unread = null;
        foreach ($tables as $table) {
            $parsed = ProductListParser::parseTable($table['rows'], [
                'method' => $content['method'], 'conf' => $table['conf'], 'ref' => $table['ref'], 'reliable' => $table['reliable'],
            ]);
            $prefix = $multiple ? "Sheet \"{$table['sheet']}\": " : '';
            if ($parsed['status'] !== 'ok') {
                // The first worksheet with something in it is offered for choosing columns.
                $unread ??= ['parsed' => $parsed, 'sheet' => $table['sheet']];
                if ($multiple && $parsed['choice']['preview'] !== []) {
                    $notes[] = "Sheet \"{$table['sheet']}\" was skipped: it has no Description and Quantity columns.";
                }

                continue;
            }
            foreach ($parsed['items'] as $item) {
                $item['ref'] = $prefix.$item['ref'];
                $item['duplicate_of'] = $item['duplicate_of'] !== null ? $prefix.$item['duplicate_of'] : null;
                $items[] = $item;
            }
            $notes = [...$notes, ...array_map(fn ($n) => $prefix.$n, $parsed['notes'])];
            $skipped += $parsed['skipped'];
        }

        if ($items !== []) {
            return ['status' => 'ok', 'items' => array_slice($items, 0, ProductListParser::MAX_ITEMS), 'notes' => $notes, 'skipped' => $skipped, 'sheet' => null, 'choice' => null];
        }

        // Nothing read: a table without headers. A PDF or image may still be a plain list.
        if (! $multiple && ! $tables[0]['reliable'] && $content['method'] !== 'csv') {
            $lines = array_map(fn ($r) => implode("\t", $r), $tables[0]['rows']);
            $text = ProductListParser::parseLines($lines, ['method' => $content['method']]);
            if ($text['status'] === 'ok') {
                return $this->single($text, null);
            }
        }

        return $this->single($unread['parsed'], $multiple ? $unread['sheet'] : null, $notes);
    }

    private function fromLines(array $content, ?array $mapping, ?int $headerRow): array
    {
        if ($mapping !== null) {
            $rows = array_map(fn ($l) => explode("\t", $l), $content['lines']);
            $conf = array_map(fn ($row, $i) => array_fill(0, count($row), (float) ($content['line_conf'][$i] ?? 100)), $rows, array_keys($rows));

            return $this->single(ProductListParser::parseTable($rows, [
                'method' => $content['method'], 'mapping' => $mapping, 'header_index' => $headerRow, 'conf' => $conf, 'ref' => 'line', 'reliable' => false,
            ]), null);
        }

        return $this->single(ProductListParser::parseLines($content['lines'], ['method' => $content['method'], 'line_conf' => $content['line_conf']]), null);
    }

    private function single(array $parsed, ?string $sheet, array $notes = []): array
    {
        return [
            'status' => $parsed['status'],
            'items' => $parsed['items'],
            'notes' => [...$notes, ...$parsed['notes']],
            'skipped' => $parsed['skipped'],
            'sheet' => $sheet,
            'choice' => $parsed['status'] === 'ok' ? null : $parsed['choice'],
        ];
    }
}
