<?php

namespace App\Services\Import;

use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\MaterialParser;

/**
 * Reads any supported document into rows of text cells, choosing the method from the file's
 * real content (not its name):
 *  - CSV: parsed directly; XLS/XLSX: every sheet via PhpSpreadsheet;
 *  - digital PDF: Poppler's word positions (pdftotext), so columns stay apart;
 *  - scanned PDF: every page rendered by Poppler (pdftoppm) and read by Tesseract;
 *  - PNG/JPG: Tesseract.
 * Pages of multi-page documents are combined into one list of rows.
 */
class DocumentExtractor
{
    public function __construct(private FileReader $reader) {}

    /**
     * @return array{type: string, method: string, pages: int|null, rows: array<int, array<int, string>>}
     *
     * @throws ExtractionException when the file is invalid or cannot be read
     */
    public function extract(string $path, string $originalName, string $module): array
    {
        $reader = $this->reader->forStaffImports();
        $type = $reader->detectType($path, $originalName);
        $isHeader = fn (array $cells, bool $words = false) => ColumnSchema::headerMap($module, array_map('strval', $cells), $words) !== null;

        try {
            $read = $reader->readTable($path, $type, $isHeader) ?? $reader->read($path, $type);
        } catch (ExtractionException $e) {
            throw new ExtractionException(match ($e->reason) {
                'ocr_unavailable' => 'Text recognition (Tesseract OCR) is not available on the server, so photos and scanned PDFs cannot be read right now. Upload an Excel, CSV or digital PDF, or ask an administrator to check the OCR installation.',
                'ocr_failed' => 'Text recognition failed or took too long for this file. Try a clearer scan or photo.',
                'pdf_render_failed' => 'Poppler could not convert this scanned PDF into images. Try re-saving the PDF.',
                default => $e->getMessage(),
            }, $e->reason);
        }

        $pages = isset($read['pages']) ? count($read['pages']) : null;
        $rows = isset($read['rows'])
            ? $read['rows']
            // Plain text (no table found): one row per line, cells split on tabs or wide gaps.
            : array_map(fn ($line) => preg_split('/\t| {3,}/u', $line) ?: [$line], MaterialParser::cleanLines($read['pages']));

        $rows = array_map(fn ($row) => array_map(function ($cell) {
            if (is_float($cell) && floor($cell) === $cell) {
                $cell = (int) $cell;
            }

            return is_scalar($cell) && ! is_bool($cell) ? trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell) ?? '') : '';
        }, array_values((array) $row)), $rows);

        return ['type' => $type, 'method' => $read['method'], 'pages' => $pages ?? $this->pdfPages($path, $type), 'rows' => $rows];
    }

    private function pdfPages(string $path, string $type): ?int
    {
        if ($type !== 'pdf') {
            return null;
        }
        $content = (string) @file_get_contents($path);

        return preg_match_all('/\/Type\s*\/Page(?!s)/', $content) ?: null;
    }
}
