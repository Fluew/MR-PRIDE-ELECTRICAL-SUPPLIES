<?php

namespace App\Services\MaterialList;

use App\Models\ImportBatch;
use App\Services\Import\ColumnSchema;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Validates an uploaded material list by its actual contents (not just the name) and reads it:
 * Excel/CSV directly as tables, PDFs via their text layer (OCR only for scanned pages), images
 * via OCR.
 */
class FileReader
{
    /** Largest file the public quotation form accepts (each file). */
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MAX_PDF_PAGES = 20;

    public const MAX_ROWS = 5000;

    public const MAX_COLS = 40;

    public const MAX_SHEETS = 10;

    /** Largest image we decode, to avoid "decompression bomb" images exhausting memory. */
    public const MAX_IMAGE_PIXELS = 100_000_000; // a 25 MB phone photo can be 48+ megapixels; OCR shrinks it first

    public const EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'jpg', 'jpeg', 'png'];

    /** Limits for this reader. The defaults are the public quotation form's; see forStaffImports(). */
    private int $maxBytes = self::MAX_BYTES;

    private int $maxPdfPages = self::MAX_PDF_PAGES;

    private ?int $maxRows = self::MAX_ROWS;

    private int $maxSheets = self::MAX_SHEETS;

    /** Return the rows of every sheet (each keeps its own header row) instead of the best one. */
    private bool $allSheets = false;

    /** Staff document imports: 25 MB per file, no row limit, every sheet. */
    public const STAFF_MAX_BYTES = 25 * 1024 * 1024;

    /** A technical cap only: each scanned page takes seconds of OCR. */
    public const STAFF_MAX_PDF_PAGES = 300;

    public function __construct(private OcrEngine $ocr) {}

    public function forStaffImports(): static
    {
        $copy = clone $this;
        $copy->maxBytes = self::STAFF_MAX_BYTES;
        $copy->maxPdfPages = self::STAFF_MAX_PDF_PAGES;
        $copy->maxRows = null;
        $copy->maxSheets = 100;
        $copy->allSheets = true;

        return $copy;
    }

    /**
     * Check the file really is what its extension says. Returns the detected type.
     *
     * @return 'pdf'|'xlsx'|'xls'|'csv'|'image'
     */
    public function detectType(string $path, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new ExtractionException('This file type is not supported. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.', 'unsupported');
        }

        $size = @filesize($path);
        if (! $size) {
            throw new ExtractionException('The file is empty.', 'empty');
        }
        if ($size > $this->maxBytes) {
            throw new ExtractionException('The file is larger than '.intdiv($this->maxBytes, 1048576).' MB. Please upload a smaller file.', 'too_large');
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);
        $bad = fn () => new ExtractionException("This file doesn't look like a valid .{$ext} file. It may be damaged, or saved in a different format.", 'corrupted');

        // Programs and scripts renamed to a document name are refused outright.
        if (self::isExecutable($path)) {
            throw new ExtractionException('This file is not a document or spreadsheet. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.', 'unsupported');
        }

        switch ($ext) {
            case 'pdf':
                if (! str_starts_with($head, '%PDF-')) {
                    throw $bad();
                }

                return 'pdf';

            case 'xlsx':
                if (! str_starts_with($head, "PK\x03\x04")) {
                    // Password-protected .xlsx files are stored as encrypted OLE containers.
                    if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                        throw new ExtractionException('This Excel file is password-protected. Please remove the password and upload it again.', 'protected');
                    }
                    throw $bad();
                }
                $zip = new \ZipArchive;
                if ($zip->open($path) !== true || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                    throw $bad();
                }
                $zip->close();

                return 'xlsx';

            case 'xls':
                if (! str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                    throw $bad();
                }

                return 'xls';

            case 'jpg':
            case 'jpeg':
            case 'png':
                $isJpeg = str_starts_with($head, "\xFF\xD8\xFF");
                $isPng = str_starts_with($head, "\x89PNG\r\n\x1A\n");
                if (! ($ext === 'png' ? $isPng : $isJpeg)) {
                    throw $bad();
                }
                $info = @getimagesize($path);
                if (! $info || $info[0] < 1 || $info[1] < 1) {
                    throw $bad();
                }
                if ($info[0] * $info[1] > self::MAX_IMAGE_PIXELS) {
                    throw new ExtractionException('This image is too large to process. Please upload a smaller image (under 100 megapixels).', 'too_large');
                }

                return 'image';

            case 'csv':
            default:
                $sample = (string) file_get_contents($path, false, null, 0, 65536);
                $utf16 = str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF");
                if (! $utf16 && str_contains($sample, "\0")) {
                    throw $bad(); // binary data, not text
                }

                return 'csv';
        }
    }

    /** MIME types (detected from the content) that are programs or web pages, never product lists. */
    private const EXECUTABLE_MIMES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib',
        'application/x-mach-binary', 'application/x-elf', 'application/java-archive', 'application/x-msi',
        'text/x-php', 'application/x-php', 'text/x-shellscript', 'application/x-sh', 'text/x-python', 'text/x-script.python',
        'text/x-perl', 'text/html', 'application/javascript', 'text/javascript', 'application/x-bat',
    ];

    /**
     * True when the file is a program or script, whatever its name: Windows/Linux/macOS
     * executables, shell/PHP scripts and web pages, by their first bytes and detected MIME type.
     */
    public static function isExecutable(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 512);
        $start = ltrim(substr($head, str_starts_with($head, "\xEF\xBB\xBF") ? 3 : 0));
        // "MZ" alone could start a CSV row ("MZ-100 breaker"); a Windows program also has binary bytes.
        if (str_starts_with($head, 'MZ') && str_contains($head, "\0")) {
            return true;
        }
        foreach (["\x7FELF", "\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE", "\xFE\xED\xFA", '#!'] as $signature) {
            if (str_starts_with($head, $signature)) {
                return true;
            }
        }
        if (preg_match('/^(?:<\?php|<\?=|<script\b|<!doctype\s+html|<html\b)/i', $start)) {
            return true;
        }

        $mime = function_exists('finfo_open') ? (string) @mime_content_type($path) : '';

        return in_array(strtolower($mime), self::EXECUTABLE_MIMES, true);
    }

    /**
     * Read the file. Tables come back as rows; documents as text per page.
     *
     * @return array{method: string, rows?: array, pages?: array<int, string>, sheet?: ?string, sheets?: array<int, string>}
     */
    public function read(string $path, string $type): array
    {
        return match ($type) {
            'csv' => ['method' => 'csv', 'rows' => $this->readCsv($path)],
            'xlsx', 'xls' => $this->readSpreadsheet($path, $type),
            'pdf' => $this->readPdf($path),
            'image' => ['method' => 'ocr', 'pages' => [$this->ocr->imageToText($path)]],
        };
    }

    /**
     * Check that a file holds written content (words or a numbered list), not just a picture.
     * Returns false when that cannot be checked because OCR is not installed on the server.
     *
     * @throws ExtractionException 'no_text' when nothing readable is found
     */
    public function assertHasText(string $path, string $type): bool
    {
        $coverage = null;
        try {
            if ($type === 'image') {
                [$lines, $coverage] = self::linesFromTsv($this->ocr->imageToTsv($path));
            } else {
                $content = $this->read($path, $type);
                $lines = isset($content['rows'])
                    ? array_map(fn ($row) => implode("\t", array_map(fn ($c) => is_scalar($c) ? (string) $c : '', (array) $row)), $content['rows'])
                    : MaterialParser::cleanLines($content['pages'] ?? []);
            }
        } catch (ExtractionException $e) {
            if ($e->reason === 'ocr_unavailable') {
                return false;
            }
            throw $e;
        }

        // A photo can show a little text (a sign, a label); a list or document fills more of the picture.
        $tooLittleText = $coverage !== null && $coverage < self::MIN_IMAGE_TEXT_COVERAGE;

        if ($tooLittleText || ! self::looksLikeWrittenList($lines)) {
            throw new ExtractionException(
                "We couldn't find any written text in this file. It looks like a photo. Please upload a document or a picture of a typed or handwritten list of the products you need.",
                'no_text',
            );
        }

        return true;
    }

    /**
     * Lines of confidently read words from Tesseract TSV, and the share of the picture they cover.
     *
     * The share is null when the TSV has no page row (size unknown).
     *
     * @return array{0: array<int, string>, 1: ?float}
     */
    public static function linesFromTsv(string $tsv): array
    {
        $pageArea = 0;
        $textArea = 0;
        $lines = [];
        foreach (explode("\n", $tsv) as $i => $row) {
            $c = explode("\t", rtrim($row, "\r"));
            if ($i === 0 || count($c) < 12) {
                continue;
            }
            if ($c[0] === '1') {
                $pageArea += (int) $c[8] * (int) $c[9];

                continue;
            }
            $word = trim($c[11]);
            if ($c[0] !== '5' || $word === '' || (float) $c[10] < 50) {
                continue;
            }
            $textArea += (int) $c[8] * (int) $c[9];
            $lines["{$c[1]}-{$c[2]}-{$c[3]}-{$c[4]}"][] = $word;
        }

        return [array_values(array_map(fn ($words) => implode(' ', $words), $lines)), $pageArea > 0 ? $textArea / $pageArea : null];
    }

    /** Minimum share of an image covered by readable words for it to count as a list, not a photo. */
    public const MIN_IMAGE_TEXT_COVERAGE = 0.015;

    /**
     * True when the lines read like a list or text: at least two numbered/bulleted lines, or a few
     * real words spread over two or more lines that are not mostly OCR noise.
     *
     * @param  array<int, string>  $lines
     */
    public static function looksLikeWrittenList(array $lines): bool
    {
        $numbered = 0;
        $wordLines = 0;
        $words = 0;
        $tokens = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:\d{1,3}[.)\]]|[-*•])\s*\p{L}{2,}/u', $line)) {
                $numbered++;
            }
            $lineTokens = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens += count($lineTokens);
            $lineWords = 0;
            foreach ($lineTokens as $token) {
                // A word: 3+ letters with a vowel (OCR noise from photos is mostly consonant runs and symbols).
                if (preg_match('/^[^\p{L}\p{N}]*(\p{L}{3,})[^\p{L}\p{N}]*$/u', $token, $m) && preg_match('/[aeiouy]/i', $m[1])) {
                    $lineWords++;
                } elseif (preg_match('/^\d+(?:[.,]\d+)?[a-z]{0,4}$/i', $token)) {
                    $lineWords += 0.5; // quantities and sizes ("10", "2.5mm", "100m")
                }
            }
            $words += $lineWords;
            if ($lineWords >= 1) {
                $wordLines++;
            }
        }

        if ($numbered >= 2) {
            return true;
        }

        return $wordLines >= 2 && $words >= 4 && $tokens > 0 && $words / $tokens >= 0.4;
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(string $path): array
    {
        $bytes = (string) file_get_contents($path);

        // Encoding: UTF-8 (with/without BOM), UTF-16 with BOM, otherwise assume Windows-1252 (Excel).
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        } elseif (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        if (trim($bytes) === '') {
            throw new ExtractionException('The CSV file has no content.', 'empty');
        }

        // Delimiter: the candidate that splits the most lines into the same number of columns.
        // Single-cell lines (titles like "Project: ...") are ignored when scoring.
        $sampleLines = array_slice(array_filter(preg_split('/\r\n|\n|\r/', $bytes) ?: [], fn ($l) => trim($l) !== ''), 0, 20);
        $best = [',', 0];
        foreach ([',', ';', "\t", '|'] as $d) {
            $counts = array_filter(array_map(fn ($l) => count(str_getcsv($l, $d, '"', '')), $sampleLines), fn ($c) => $c > 1);
            if ($counts === []) {
                continue;
            }
            $frequency = array_count_values($counts);
            arsort($frequency);
            $columns = (int) array_key_first($frequency);
            $score = $frequency[$columns] * 100 + $columns;
            if ($score > $best[1]) {
                $best = [$d, $score];
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, $best[0], '"', '')) !== false && ($this->maxRows === null || count($rows) < $this->maxRows)) {
            $rows[] = array_slice(array_map(fn ($c) => (string) $c, $row), 0, self::MAX_COLS);
        }
        fclose($stream);

        return $rows;
    }

    private function readSpreadsheet(string $path, string $type): array
    {
        try {
            $reader = IOFactory::createReader($type === 'xlsx' ? 'Xlsx' : 'Xls');
            // Cell formats are read too, so a date cell can be told apart from a plain number.
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            if ($this->maxRows !== null) {
                // Only the cells that are read are loaded, so a large workbook does not exhaust memory.
                $reader->setReadFilter(new class($this->maxRows, self::MAX_COLS) implements IReadFilter
                {
                    public function __construct(private int $rows, private int $cols) {}

                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return $row <= $this->rows && Coordinate::columnIndexFromString($columnAddress) <= $this->cols;
                    }
                });
            }
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            $protected = stripos($e->getMessage(), 'password') !== false || stripos($e->getMessage(), 'encrypt') !== false;
            throw new ExtractionException(
                $protected
                    ? 'This Excel file is password-protected. Please remove the password and upload it again.'
                    : 'We could not open this Excel file. It may be damaged. Please re-save it in Excel, or upload it as CSV.',
                $protected ? 'protected' : 'corrupted'
            );
        }

        $sheets = [];
        $tables = [];
        $best = null;
        $all = [];
        foreach (array_slice($book->getAllSheets(), 0, $this->maxSheets) as $sheet) {
            $rows = [];
            $maxRow = $this->maxRows === null ? $sheet->getHighestDataRow() : min($sheet->getHighestDataRow(), $this->maxRows);
            $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLS);
            for ($r = 1; $r <= $maxRow; $r++) {
                $row = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $cell = $sheet->getCell([$c, $r]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $value = $cell->getOldCalculatedValue();
                        if ($value === null) {
                            try {
                                $value = $cell->getCalculatedValue();
                            } catch (\Throwable) {
                                $value = $cell->getValue();
                            }
                        }
                    } else {
                        $value = $cell->getValue();
                    }
                    $value = $value instanceof RichText ? $value->getPlainText() : $value;
                    $row[] = is_scalar($value) ? $this->excelDate($cell, $value) : '';
                }
                $rows[] = $row;
            }

            $found = HeaderMapper::findHeaderRow($rows);
            $score = count($found['mapping']) + (HeaderMapper::isConfident($found['mapping']) ? 10 : 0);
            if ($score === 0) {
                foreach (array_slice($rows, 0, 50) as $candidateRow) { // as far down as HeaderMapper::findHeaderRow() looks
                    $strRow = array_map(fn ($v) => (string) $v, $candidateRow);
                    $invMap = ColumnSchema::headerMap(ImportBatch::INVENTORY, $strRow);
                    $sqMap = ColumnSchema::headerMap(ImportBatch::SUPPLIER_QUOTATION, $strRow);
                    $altScore = max($invMap ? count($invMap) + 10 : 0, $sqMap ? count($sqMap) + 10 : 0);
                    if ($altScore > $score) {
                        $score = $altScore;
                    }
                }
            }
            $sheets[] = $sheet->getTitle();
            $tables[] = ['sheet' => $sheet->getTitle(), 'rows' => $rows];
            if ($this->allSheets) {
                array_push($all, ...$rows);
            }
            if ($best === null || $score > $best['score']) {
                $best = ['score' => $score, 'rows' => $rows, 'title' => $sheet->getTitle()];
            }
        }
        $book->disconnectWorksheets();

        // 'tables': every sheet's rows on their own, for readers that handle each sheet separately.
        return ['method' => 'spreadsheet', 'rows' => $this->allSheets ? $all : ($best['rows'] ?? []), 'sheet' => $best['title'] ?? null, 'sheets' => $sheets, 'tables' => $tables];
    }

    /**
     * A number in a date-formatted Excel cell is a date serial (46329 = 2026-11-03), which is
     * unambiguous: it is returned as ISO YYYY-MM-DD (with the time when there is one). Plain
     * numbers, text and time-only cells are returned unchanged.
     */
    private function excelDate(Cell $cell, mixed $value): mixed
    {
        if ((! is_int($value) && ! is_float($value)) || $value < 1) {
            return $value;
        }
        try {
            if (! ExcelDate::isDateTime($cell, $value)) {
                return $value;
            }
            $dt = ExcelDate::excelToDateTimeObject($value);
        } catch (\Throwable) {
            return $value;
        }

        return $dt->format('H:i:s') === '00:00:00' ? $dt->format('Y-m-d') : $dt->format('Y-m-d H:i:s');
    }

    /**
     * Read a PDF or image as a table, using word positions to keep its columns apart (see
     * WordTable). $isHeader picks the header row. Null for other types, or when a text PDF's
     * word positions are unavailable (then read() is the fallback).
     *
     * @param  callable(array<int, string>): bool  $isHeader
     * @return array{method: string, aligned: bool, rows: array<int, array<int, string>>}|null
     */
    public function readTable(string $path, string $type, callable $isHeader): ?array
    {
        if ($type === 'image') {
            return ['method' => 'ocr'] + WordTable::build([$this->ocr->imageToTsv($path)], $isHeader, true);
        }
        if ($type !== 'pdf') {
            return null;
        }

        ['pages' => $pages, 'scanned' => $scanned] = $this->inspectPdf($path);
        if ($scanned) {
            return ['method' => 'pdf-ocr'] + WordTable::build($this->ocr->pdfToTsv($path, count($pages)), $isHeader, true);
        }

        $tsv = $this->ocr->pdfTextTsv($path);

        return $tsv === null ? null : ['method' => 'pdf-text'] + WordTable::build([$tsv], $isHeader, false);
    }

    /**
     * The words of a PDF or image with their positions (Tesseract or pdftotext TSV; one string per
     * scanned page, or one for all pages of a text PDF), for documents read by their layout, such as
     * a label with its value beside or below it. Null for other types, or for a text PDF when
     * pdftotext is not installed.
     *
     * @return array{method: 'ocr'|'pdf-ocr'|'pdf-text', tsv: list<string>}|null
     */
    public function readWords(string $path, string $type): ?array
    {
        if ($type === 'image') {
            return ['method' => 'ocr', 'tsv' => [$this->ocr->imageToTsv($path)]];
        }
        if ($type !== 'pdf') {
            return null;
        }

        ['pages' => $pages, 'scanned' => $scanned] = $this->inspectPdf($path);
        if ($scanned) {
            return ['method' => 'pdf-ocr', 'tsv' => array_values($this->ocr->pdfToTsv($path, count($pages)))];
        }
        $tsv = $this->ocr->pdfTextTsv($path);

        return $tsv === null ? null : ['method' => 'pdf-text', 'tsv' => [$tsv]];
    }

    private function readPdf(string $path): array
    {
        ['pages' => $texts, 'scanned' => $scanned] = $this->inspectPdf($path);

        // Little or no text layer: a scanned document. Render the pages and OCR them.
        if ($scanned) {
            return ['method' => 'pdf-ocr', 'pages' => $this->ocr->pdfToText($path, count($texts))];
        }

        return ['method' => 'pdf-text', 'pages' => $texts];
    }

    /**
     * Open a PDF, check its page count, and tell whether it is scanned (little or no text layer).
     *
     * @return array{pages: array<int, string>, scanned: bool} text per page
     */
    private function inspectPdf(string $path): array
    {
        try {
            $pdf = (new PdfParser)->parseFile($path);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'secured') || str_contains($msg, 'encrypt') || str_contains($msg, 'password')) {
                throw new ExtractionException('This PDF is password-protected. Please remove the password and upload it again.', 'protected');
            }
            throw new ExtractionException('We could not open this PDF. It may be damaged. Please re-save it, or upload an Excel/CSV file.', 'corrupted');
        }

        $pages = $pdf->getPages();
        if (count($pages) > $this->maxPdfPages) {
            throw new ExtractionException('This PDF has '.count($pages).' pages. Please upload a file with at most '.$this->maxPdfPages.' pages.', 'too_many_pages');
        }
        if ($pages === []) {
            throw new ExtractionException('This PDF has no pages.', 'empty');
        }

        $texts = array_map(fn ($p) => (string) $p->getText(), $pages);
        $letters = preg_match_all('/\p{L}/u', implode(' ', $texts));

        return ['pages' => $texts, 'scanned' => $letters < 25 * count($pages)];
    }
}
