<?php

namespace App\Services\MaterialList;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use ZipArchive;

/**
 * Formats beyond PDF, spreadsheets and JPG/PNG for the public quotation request: validated by their
 * contents (signature and structure, never the name alone) and read WITHOUT changing how rows are interpreted.
 * The result has the same shape the rest of the product-list pipeline already uses: tables (rows) and text lines.
 *
 *  - Text formats (TXT, RTF, HTML, SVG, CSV) and Office/OpenDocument files (DOCX, PPTX, ODT, ODP) are read directly,
 *    keeping tables as rows and text as lines, in document order. They are not treated as pictures.
 *  - Pictures inside Office documents are handed back one at a time for OCR (see eachEmbeddedImage()).
 *  - ODS is read as a spreadsheet by FileReader (PhpSpreadsheet).
 *  - Pictures in formats Tesseract cannot take (PBM, PGM, PPM, HEIC/HEIF) are converted to PNG first.
 *  - Legacy DOC and PPT, and HEIC/HEIF, need an external converter (LibreOffice, ImageMagick or libheif). When none is
 *    configured the file is refused with a clear message; nothing is guessed.
 *
 * Nothing here runs a shell: converters are started with an argument array, a timeout, and a randomly named copy of
 * the upload in a private temporary folder that is always removed.
 */
class DocumentReader
{
    /** Types read by this class (the spreadsheet formats are FileReader's). */
    public const TEXT_TYPES = ['txt', 'rtf', 'html', 'svg', 'docx', 'pptx', 'odt', 'odp'];

    /** Largest total size of a ZIP-based document once unpacked, and the most entries: guards against zip bombs. */
    public const MAX_UNPACKED_BYTES = 200 * 1024 * 1024;

    public const MAX_ZIP_ENTRIES = 3000;

    /** The biggest single XML part we parse. */
    public const MAX_XML_BYTES = 40 * 1024 * 1024;

    /** At most this many slides, and this many embedded pictures are read from one document. */
    public const MAX_SLIDES = 100;

    public const MAX_EMBEDDED_IMAGES = 10;

    public const MAX_LINES = 20000;

    /** Picture types inside Office files that Tesseract (through the OCR preprocessing) can read. */
    private const EMBEDDED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'tif', 'tiff'];

    // ───────────────────────────── Detection ─────────────────────────────

    /**
     * Confirm the file is what its extension says, from its first bytes and structure.
     *
     * @return string the type: one of TEXT_TYPES, 'ods', 'doc', 'ppt', or 'image' for the picture formats
     *
     * @throws ExtractionException
     */
    public function detect(string $path, string $ext): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $bad = fn () => new ExtractionException("This file doesn't look like a valid .{$ext} file. It may be damaged, or saved in a different format.", 'corrupted');

        switch ($ext) {
            case 'gif':
                return str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a') ? $this->picture($path, $bad) : throw $bad();
            case 'bmp':
                return str_starts_with($head, 'BM') ? $this->picture($path, $bad) : throw $bad();
            case 'webp':
                return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' ? $this->picture($path, $bad) : throw $bad();
            case 'tif':
            case 'tiff':
                // Classic TIFF only ("II*\0" little-endian, "MM\0*" big-endian). Tesseract reads it directly.
                return str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*") ? 'image' : throw $bad();
            case 'pbm':
            case 'pgm':
            case 'ppm':
                return $this->netpbm($path, $ext) ? 'image' : throw $bad();
            case 'heic':
            case 'heif':
                return substr($head, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)/', substr($head, 8, 4)) ? 'image' : throw $bad();

            case 'txt':
                return $this->isPlainText($path) ? 'txt' : throw $bad();
            case 'rtf':
                return str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), '{\\rtf') ? 'rtf' : throw $bad();
            case 'html':
            case 'htm':
                return $this->looksLikeHtml($path) ? 'html' : throw $bad();
            case 'svg':
                return $this->looksLikeSvg($path) ? 'svg' : throw $bad();

            case 'docx':
                return $this->openXml($path, 'word/document.xml') ? 'docx' : throw $bad();
            case 'pptx':
                return $this->openXml($path, 'ppt/presentation.xml') ? 'pptx' : throw $bad();
            case 'odt':
                return $this->openDocument($path, 'application/vnd.oasis.opendocument.text') ? 'odt' : throw $bad();
            case 'odp':
                return $this->openDocument($path, 'application/vnd.oasis.opendocument.presentation') ? 'odp' : throw $bad();
            case 'ods':
                return $this->openDocument($path, 'application/vnd.oasis.opendocument.spreadsheet') ? 'ods' : throw $bad();

            case 'doc':
            case 'ppt':
                // The legacy binary format: a compound file. The converter (LibreOffice) checks the rest.
                return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") ? $ext : throw $bad();
        }

        throw new ExtractionException('This file type is not supported.', 'unsupported');
    }

    /** A picture GD (and so the OCR preprocessing) can decode, with sane dimensions. */
    private function picture(string $path, callable $bad): string
    {
        $info = @getimagesize($path);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            throw $bad();
        }
        if ($info[0] * $info[1] > FileReader::MAX_IMAGE_PIXELS) {
            throw new ExtractionException('This image is too large to process. Please upload a smaller image (under 100 megapixels).', 'too_large');
        }

        return 'image';
    }

    /** PBM/PGM/PPM: the "P1"-"P6" magic number matching the extension, then whitespace and the sizes. */
    private function netpbm(string $path, string $ext): bool
    {
        $magic = ['pbm' => ['P1', 'P4'], 'pgm' => ['P2', 'P5'], 'ppm' => ['P3', 'P6']][$ext] ?? [];
        $head = (string) file_get_contents($path, false, null, 0, 64);

        return in_array(substr($head, 0, 2), $magic, true) && preg_match('/^P[1-6]\s+(?:#[^\n]*\n\s*)*\d+\s+\d+/', $head) === 1;
    }

    /** Text: no NUL bytes (UTF-16 with a byte-order mark excepted) and valid in a common encoding. */
    private function isPlainText(string $path): bool
    {
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        $utf16 = str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF");

        return $utf16 || ! str_contains($sample, "\0");
    }

    private function looksLikeHtml(string $path): bool
    {
        $start = ltrim((string) file_get_contents($path, false, null, 0, 4096), "\xEF\xBB\xBF \t\r\n");
        // Server-side code and scripts renamed to .html are refused; ordinary markup is only ever read as text.
        if (preg_match('/^(?:<\?|#!|<%)/', $start) || str_contains($start, "\0")) {
            return false;
        }

        return (bool) preg_match('/<(?:!doctype|html|head|body|table|div|p|span|h[1-6]|ul|ol|li|br|meta|title)\b/i', $start);
    }

    private function looksLikeSvg(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);

        return (bool) preg_match('/<svg\b/i', $head) && ! str_contains($head, "\0");
    }

    /** An Office Open XML file: a ZIP with its content types, the main part, and safe sizes. */
    private function openXml(string $path, string $mainPart): bool
    {
        $zip = $this->zip($path);

        return $zip !== null && $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($mainPart) !== false && $this->zipIsSafe($zip);
    }

    /** An OpenDocument file: a ZIP whose "mimetype" entry names the kind, with content.xml. */
    private function openDocument(string $path, string $mime): bool
    {
        $zip = $this->zip($path);
        if ($zip === null || $zip->locateName('content.xml') === false || ! $this->zipIsSafe($zip)) {
            return false;
        }

        return trim((string) $zip->getFromName('mimetype')) === $mime;
    }

    private function zip(string $path): ?ZipArchive
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if (! str_starts_with($head, "PK\x03\x04")) {
            return null;
        }
        $zip = new ZipArchive;

        return $zip->open($path) === true ? $zip : null;
    }

    /** Refuse zip bombs and path tricks before anything is read: entry count, unpacked size and entry names. */
    private function zipIsSafe(ZipArchive $zip): bool
    {
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            return false;
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return false;
            }
            $name = (string) $stat['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name)) {
                return false;
            }
            $total += (int) $stat['size'];
            if ($total > self::MAX_UNPACKED_BYTES) {
                return false;
            }
        }

        return true;
    }

    // ───────────────────────────── Reading ─────────────────────────────

    /**
     * Read a detected file.
     *
     * @return array{method: string, tables: list<array{sheet: ?string, rows: array<int, array<int, string>>}>, lines: list<string>, notes: list<string>}
     *
     * @throws ExtractionException
     */
    public function read(string $path, string $type): array
    {
        return match ($type) {
            'txt' => $this->readText($path),
            'rtf' => $this->readRtf($path),
            'html' => $this->readHtml($path),
            'svg' => $this->readSvg($path),
            'docx' => $this->readDocx($path),
            'pptx' => $this->readPptx($path),
            'odt', 'odp' => $this->readOpenDocument($path, $type),
            default => throw new ExtractionException('This file type is not supported.', 'unsupported'),
        };
    }

    private function result(string $method, array $tables, array $lines, array $notes = []): array
    {
        $lines = array_slice(array_values(array_filter(array_map(fn ($l) => trim((string) $l), $lines), fn ($l) => $l !== '')), 0, self::MAX_LINES);
        $tables = array_values(array_filter($tables, fn ($t) => count($t['rows']) >= 2));

        if ($lines === [] && $tables === []) {
            throw new ExtractionException('No readable text was found in this file.', 'no_text');
        }

        return ['method' => $method, 'tables' => $tables, 'lines' => $lines, 'notes' => $notes];
    }

    /** Text decoded the way CSV files are: UTF-8 (with or without BOM), UTF-16 with BOM, otherwise Windows-1252. */
    private function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private function readText(string $path): array
    {
        $text = $this->decode((string) file_get_contents($path));

        return $this->result('text', [], preg_split('/\r\n|\n|\r/', $text) ?: []);
    }

    private function readHtml(string $path): array
    {
        $html = $this->decode((string) file_get_contents($path));
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // NONET: no network access. Markup is read for its text only; nothing is rendered or run.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//head|//noscript|//template|//iframe|//object|//embed') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Tables keep their rows and cells; the rest of the page becomes lines.
        $tables = [];
        foreach ($xpath->query('//table[not(ancestor::table)]') ?: [] as $table) {
            $rows = [];
            foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
                $cells = [];
                foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
                    $cells[] = $this->collapse($cell->textContent);
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }
            $tables[] = ['sheet' => null, 'rows' => $rows];
            $table->parentNode?->removeChild($table);
        }

        $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        $markup = $body ? (string) $dom->saveHTML($body) : '';
        $markup = preg_replace('/<(?:br|\/p|\/div|\/li|\/h[1-6]|\/tr|\/pre|\/section|\/article)\b[^>]*>/i', "\n", $markup) ?? $markup;
        $text = html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->result('text', $tables, preg_split('/\r\n|\n|\r/', $text) ?: []);
    }

    private function readSvg(string $path): array
    {
        $xml = $this->safeXml((string) file_get_contents($path, false, null, 0, self::MAX_XML_BYTES));
        $xpath = new DOMXPath($xml);
        $lines = [];
        // Each <text> element is one line of writing; the order is the order in the file.
        foreach ($xpath->query('//*[local-name()="text"]') ?: [] as $text) {
            $lines[] = $this->collapse($text->textContent);
        }

        return $this->result('text', [], $lines, ['Text was read from the SVG file itself; drawings and pictures inside it were not read.']);
    }

    /** Strip RTF control words and groups that hold no body text; keep paragraphs, tabs and table cells as lines/tabs. */
    private function readRtf(string $path): array
    {
        $rtf = (string) file_get_contents($path);
        $skip = ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer', 'footnote', 'listtable', 'listoverridetable', 'generator', 'themedata', 'datastore', 'latentstyles', 'rsidtbl', 'fldinst'];
        $out = '';
        $stack = [];       // per group: whether it is being skipped
        $skipDepth = null;
        $uc = 1;
        $len = strlen($rtf);
        for ($i = 0; $i < $len; $i++) {
            $c = $rtf[$i];
            if ($c === '{') {
                $stack[] = $skipDepth !== null;
                if ($skipDepth !== null) {
                    continue;
                }
                // A group starting "{\*" or with a known destination holds no body text.
                if (preg_match('/\G\\\\\*/', $rtf, $m, 0, $i + 1) || (preg_match('/\G\\\\([a-z]+)/i', $rtf, $m, 0, $i + 1) && in_array(strtolower($m[1]), $skip, true))) {
                    $skipDepth = count($stack);
                }
            } elseif ($c === '}') {
                $depth = count($stack);
                array_pop($stack);
                if ($skipDepth !== null && $depth <= $skipDepth) {
                    $skipDepth = null;
                }
            } elseif ($c === '\\') {
                $next = $rtf[$i + 1] ?? '';
                if ($next === '\\' || $next === '{' || $next === '}') {
                    if ($skipDepth === null) {
                        $out .= $next;
                    }
                    $i++;
                } elseif ($next === "'") {
                    $hex = substr($rtf, $i + 2, 2);
                    if ($skipDepth === null && ctype_xdigit($hex)) {
                        $out .= mb_convert_encoding(chr(hexdec($hex)), 'UTF-8', 'Windows-1252');
                    }
                    $i += 3;
                } elseif (ctype_alpha($next)) {
                    preg_match('/\G([a-z]+)(-?\d+)? ?/i', $rtf, $m, 0, $i + 1);
                    $word = strtolower($m[1]);
                    $arg = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
                    $i += strlen($m[0]);
                    if ($skipDepth !== null) {
                        continue;
                    }
                    if ($word === 'uc' && $arg !== null) {
                        $uc = $arg;
                    } elseif ($word === 'u' && $arg !== null) {
                        $out .= mb_chr($arg < 0 ? $arg + 65536 : $arg, 'UTF-8');
                        $i += $uc; // the plain-text fallback characters that follow are skipped
                    } elseif (in_array($word, ['par', 'line', 'row', 'sect', 'page'], true)) {
                        $out .= "\n";
                    } elseif (in_array($word, ['tab', 'cell'], true)) {
                        $out .= "\t";
                    } elseif ($word === 'emdash') {
                        $out .= '—';
                    } elseif ($word === 'endash') {
                        $out .= '–';
                    }
                } else {
                    $i++; // a control symbol such as \~ or \-
                }
            } elseif ($skipDepth === null && $c !== "\r" && $c !== "\n") {
                $out .= $c;
            }
        }

        return $this->result('text', [], preg_split('/\n/', $out) ?: []);
    }

    // ───────────────────────────── Office Open XML / OpenDocument ─────────────────────────────

    private function readDocx(string $path): array
    {
        $zip = $this->openZip($path);
        $xml = $this->safeXml($this->part($zip, 'word/document.xml'));
        $lines = [];
        $tables = [];
        $body = $xml->getElementsByTagNameNS('*', 'body')->item(0);
        foreach ($body?->childNodes ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                $lines[] = $this->wordParagraph($node);
            } elseif ($node->localName === 'tbl') {
                $tables[] = ['sheet' => null, 'rows' => $this->wordTable($node)];
            }
        }
        $zip->close();

        return $this->result('docx', $tables, $lines);
    }

    /** One Word paragraph: its runs' text, tabs and line breaks in order. */
    private function wordParagraph(DOMElement $p): string
    {
        $text = '';
        $walk = function (DOMNode $node) use (&$walk, &$text): void {
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                match ($child->localName) {
                    't' => $text .= $child->textContent,
                    'tab' => $text .= "\t",
                    'br', 'cr' => $text .= "\n",
                    default => $walk($child),
                };
            }
        };
        $walk($p);

        return $text;
    }

    /** A Word table as rows of cell text (a cell's paragraphs joined by a space); nested tables are read in place. */
    private function wordTable(DOMElement $tbl): array
    {
        $rows = [];
        foreach ($tbl->childNodes as $tr) {
            if (! $tr instanceof DOMElement || $tr->localName !== 'tr') {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $tc) {
                if (! $tc instanceof DOMElement || $tc->localName !== 'tc') {
                    continue;
                }
                $parts = [];
                foreach ($tc->childNodes as $inner) {
                    if ($inner instanceof DOMElement && $inner->localName === 'p') {
                        $parts[] = trim($this->wordParagraph($inner));
                    } elseif ($inner instanceof DOMElement && $inner->localName === 'tbl') {
                        foreach ($this->wordTable($inner) as $nested) {
                            $parts[] = implode(' ', $nested);
                        }
                    }
                }
                $cells[] = $this->collapse(implode(' ', array_filter($parts, fn ($p) => $p !== '')));
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function readPptx(string $path): array
    {
        $zip = $this->openZip($path);
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', (string) $zip->getNameIndex($i), $m)) {
                $slides[(int) $m[1]] = $zip->getNameIndex($i);
            }
        }
        ksort($slides); // slide order
        if (count($slides) > self::MAX_SLIDES) {
            throw new ExtractionException('This presentation has '.count($slides).' slides. Please upload one with at most '.self::MAX_SLIDES.'.', 'too_many_pages');
        }

        $lines = [];
        $tables = [];
        foreach ($slides as $number => $name) {
            $xml = $this->safeXml($this->part($zip, (string) $name));
            $xpath = new DOMXPath($xml);
            foreach ($xpath->query('//*[local-name()="tbl"]') ?: [] as $tbl) {
                $rows = [];
                foreach ($xpath->query('./*[local-name()="tr"]', $tbl) ?: [] as $tr) {
                    $cells = [];
                    foreach ($xpath->query('./*[local-name()="tc"]', $tr) ?: [] as $tc) {
                        $cells[] = $this->collapse($tc->textContent);
                    }
                    $rows[] = $cells;
                }
                $tables[] = ['sheet' => "Slide {$number}", 'rows' => $rows];
                $tbl->parentNode?->removeChild($tbl);
            }
            foreach ($xpath->query('//*[local-name()="p"]') ?: [] as $p) {
                $lines[] = $this->collapse($p->textContent);
            }
        }
        $zip->close();

        return $this->result('pptx', $tables, $lines);
    }

    private function readOpenDocument(string $path, string $type): array
    {
        $zip = $this->openZip($path);
        $xml = $this->safeXml($this->part($zip, 'content.xml'));
        $xpath = new DOMXPath($xml);

        $tables = [];
        foreach ($xpath->query('//*[local-name()="table" and not(ancestor::*[local-name()="table"])]') ?: [] as $table) {
            $rows = [];
            foreach ($xpath->query('.//*[local-name()="table-row"]', $table) ?: [] as $tr) {
                $cells = [];
                foreach ($xpath->query('./*[local-name()="table-cell"]', $tr) ?: [] as $tc) {
                    $repeat = min(50, max(1, (int) $tc->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:table:1.0', 'number-columns-repeated')));
                    $text = $this->collapse($tc->textContent);
                    for ($k = 0; $k < $repeat; $k++) {
                        $cells[] = $text;
                    }
                }
                if (trim(implode('', $cells)) !== '') {
                    $rows[] = $cells;
                }
            }
            $tables[] = ['sheet' => null, 'rows' => $rows];
            $table->parentNode?->removeChild($table);
        }
        $lines = [];
        foreach ($xpath->query('//*[local-name()="p" or local-name()="h"]') ?: [] as $p) {
            $lines[] = $this->collapse($p->textContent);
        }
        $zip->close();

        return $this->result($type, $tables, $lines);
    }

    // ───────────────────────────── Pictures inside documents ─────────────────────────────

    /**
     * Hand each picture embedded in a DOCX, PPTX, ODT or ODP to $callback as a temporary file, in document order, and
     * remove it afterwards. Used to OCR scanned content that lives in pictures. Other types have none.
     *
     * @param  callable(string $imagePath, string $label): void  $callback
     * @return int how many pictures were passed
     */
    public function eachEmbeddedImage(string $path, string $type, callable $callback): int
    {
        $prefix = ['docx' => 'word/media/', 'pptx' => 'ppt/media/', 'odt' => 'Pictures/', 'odp' => 'Pictures/'][$type] ?? null;
        if ($prefix === null) {
            return 0;
        }
        $zip = $this->zip($path);
        if ($zip === null) {
            return 0;
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (str_starts_with($name, $prefix) && in_array($ext, self::EMBEDDED_IMAGE_EXTENSIONS, true)) {
                $names[] = $name;
            }
        }
        natsort($names);

        $count = 0;
        foreach (array_slice(array_values($names), 0, self::MAX_EMBEDDED_IMAGES) as $name) {
            $stat = $zip->statName($name);
            if ($stat === false || $stat['size'] < 200 || $stat['size'] > FileReader::MAX_BYTES) {
                continue; // an icon, or too large
            }
            $tmp = $this->tempDir().'/'.bin2hex(random_bytes(8)).'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
            try {
                file_put_contents($tmp, (string) $zip->getFromName($name));
                $info = @getimagesize($tmp);
                // Only real pictures of a sane size go to OCR (the file's own name is never trusted).
                if (! $info && ! $this->looksLikeTiff($tmp)) {
                    continue;
                }
                if ($info && $info[0] * $info[1] > FileReader::MAX_IMAGE_PIXELS) {
                    continue;
                }
                $callback($tmp, basename($name));
                $count++;
            } finally {
                @unlink($tmp);
            }
        }
        $zip->close();

        return $count;
    }

    private function looksLikeTiff(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);

        return str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*");
    }

    // ───────────────────────────── Conversions ─────────────────────────────

    /**
     * A PNG made from a picture format that Tesseract and the OCR preprocessing cannot take (PBM/PGM/PPM, HEIC/HEIF),
     * as a temporary file the caller removes, or null when the file needs no conversion.
     *
     * @throws ExtractionException when conversion is needed but impossible
     */
    public function toPng(string $path): ?string
    {
        // Decided from the file's own bytes: this is only called for files already validated as pictures.
        $head = (string) file_get_contents($path, false, null, 0, 16);

        if (preg_match('/^P[1-6]\s/', $head) === 1) {
            return $this->netpbmToPng($path);
        }
        if (substr($head, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)/', substr($head, 8, 4))) {
            return $this->heifToPng($path);
        }

        return null;
    }

    /** An animated GIF shows only its first frame to the OCR (GD decodes one frame): say so, never silently. */
    public function pictureNotes(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 6);
        if (! str_starts_with($head, 'GIF8')) {
            return [];
        }
        $frames = preg_match_all('/\x00\x21\xF9\x04[\x00-\xFF]{4}\x00\x2C/s', (string) file_get_contents($path));

        return $frames > 1 ? ["This GIF has {$frames} frames. Only the first frame was read: please check that the list is complete."] : [];
    }

    /** Decode PBM, PGM and PPM (ASCII and binary, 8 or 16 bit) to PNG with GD. */
    private function netpbmToPng(string $path): string
    {
        $bad = new ExtractionException("This file doesn't look like a valid image. It may be damaged.", 'corrupted');
        $data = (string) file_get_contents($path);
        $pos = 0;
        $token = function () use (&$pos, $data): ?string {
            $n = strlen($data);
            while ($pos < $n) {
                if ($data[$pos] === '#') {
                    while ($pos < $n && $data[$pos] !== "\n") {
                        $pos++;
                    }
                } elseif (ctype_space($data[$pos])) {
                    $pos++;
                } else {
                    break;
                }
            }
            $start = $pos;
            while ($pos < $n && ! ctype_space($data[$pos]) && $data[$pos] !== '#') {
                $pos++;
            }

            return $pos > $start ? substr($data, $start, $pos - $start) : null;
        };

        $magic = $token();
        $w = (int) $token();
        $h = (int) $token();
        if (! in_array($magic, ['P1', 'P2', 'P3', 'P4', 'P5', 'P6'], true) || $w < 1 || $h < 1 || $w * $h > FileReader::MAX_IMAGE_PIXELS) {
            throw $bad;
        }
        $max = in_array($magic, ['P1', 'P4'], true) ? 1 : (int) $token();
        if ($max < 1 || $max > 65535) {
            throw $bad;
        }
        $binary = in_array($magic, ['P4', 'P5', 'P6'], true);
        $channels = in_array($magic, ['P3', 'P6'], true) ? 3 : 1;
        $img = imagecreatetruecolor($w, $h);
        $scale = fn (int $v) => (int) round($v * 255 / $max);

        if ($magic === 'P4') {
            $pos++; // the single whitespace after the header
            $rowBytes = intdiv($w + 7, 8);
            if (strlen($data) - $pos < $rowBytes * $h) {
                throw $bad;
            }
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $bit = (ord($data[$pos + $y * $rowBytes + ($x >> 3)]) >> (7 - ($x & 7))) & 1;
                    imagesetpixel($img, $x, $y, $bit ? 0x000000 : 0xFFFFFF);
                }
            }
        } elseif ($binary) {
            $pos++;
            $bytes = $max > 255 ? 2 : 1;
            if (strlen($data) - $pos < $w * $h * $channels * $bytes) {
                throw $bad;
            }
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $v = [];
                    for ($c = 0; $c < $channels; $c++) {
                        $o = $pos + (($y * $w + $x) * $channels + $c) * $bytes;
                        $v[] = $scale($bytes === 2 ? (ord($data[$o]) << 8) | ord($data[$o + 1]) : ord($data[$o]));
                    }
                    imagesetpixel($img, $x, $y, $channels === 3 ? ($v[0] << 16) | ($v[1] << 8) | $v[2] : ($v[0] << 16) | ($v[0] << 8) | $v[0]);
                }
            }
        } else {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $v = [];
                    for ($c = 0; $c < $channels; $c++) {
                        $t = $token();
                        if ($t === null || ! ctype_digit($t)) {
                            throw $bad;
                        }
                        $v[] = $magic === 'P1' ? ($t === '1' ? 0 : 255) : $scale((int) $t);
                    }
                    imagesetpixel($img, $x, $y, $channels === 3 ? ($v[0] << 16) | ($v[1] << 8) | $v[2] : ($v[0] << 16) | ($v[0] << 8) | $v[0]);
                }
            }
        }

        $out = $this->tempDir().'/'.bin2hex(random_bytes(8)).'.png';
        imagepng($img, $out);
        imagedestroy($img);

        return $out;
    }

    /** HEIC/HEIF → PNG with ImageMagick or libheif, if one is configured. Otherwise the file is refused, never guessed at. */
    private function heifToPng(string $path): string
    {
        $tool = (string) config('services.ocr.heif');
        if ($tool === '' || ! $this->toolAvailable($tool)) {
            throw new ExtractionException('HEIC photos cannot be read on this server. Please save the photo as JPG or PNG and upload that.', 'converter_unavailable');
        }

        $dir = $this->tempDir();
        $in = $dir.'/'.bin2hex(random_bytes(8)).'.heic';
        $out = $dir.'/'.bin2hex(random_bytes(8)).'.png';
        try {
            copy($path, $in);
            // ImageMagick is "magick in out"; libheif's heif-convert is "heif-convert in out". Both take the two paths.
            $result = Process::timeout((int) config('services.ocr.timeout', 60))->run([$tool, $in, $out]);
            if (! $result->successful() || ! is_file($out) || filesize($out) < 100) {
                Log::warning('HEIC conversion failed: '.mb_substr($result->errorOutput(), 0, 300));
                throw new ExtractionException('We could not convert this HEIC photo. Please save it as JPG or PNG and upload that.', 'corrupted');
            }

            return $out;
        } catch (ExtractionException $e) {
            @unlink($out);
            throw $e;
        } catch (\Throwable $e) {
            @unlink($out);
            Log::warning('HEIC conversion failed: '.$e->getMessage());
            throw new ExtractionException('We could not convert this HEIC photo. Please save it as JPG or PNG and upload that.', 'corrupted');
        } finally {
            @unlink($in);
        }
    }

    /**
     * Legacy .doc / .ppt → .docx / .pptx with LibreOffice, if configured; the converted file is read as usual and removed.
     * Returns the converted file's path and its type.
     *
     * @return array{0: string, 1: string}
     *
     * @throws ExtractionException
     */
    public function convertLegacyOffice(string $path, string $type): array
    {
        $office = (string) config('services.ocr.office');
        $label = strtoupper($type);
        if ($office === '' || ! $this->toolAvailable($office)) {
            throw new ExtractionException("Older .{$type} files cannot be read on this server. Please save it as ".($type === 'doc' ? '.docx' : '.pptx').', PDF or Excel/CSV and upload that.', 'converter_unavailable');
        }

        $target = $type === 'doc' ? 'docx' : 'pptx';
        $work = $this->tempDir().'/office-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($work);
        $in = $work.'/in.'.$type;
        try {
            copy($path, $in);
            // A private profile folder keeps parallel conversions from clashing.
            $result = Process::timeout((int) config('services.ocr.timeout', 60) * 2)->run([
                $office, '--headless', '--norestore', '--nolockcheck', '-env:UserInstallation=file:///'.ltrim(str_replace('\\', '/', $work.'/profile'), '/'),
                '--convert-to', $target, '--outdir', $work, $in,
            ]);
            $out = $work.'/in.'.$target;
            if (! $result->successful() || ! is_file($out) || filesize($out) < 100) {
                Log::warning("{$label} conversion failed: ".mb_substr($result->errorOutput(), 0, 300));
                throw new ExtractionException("We could not open this .{$type} file. Please save it as .{$target}, PDF or Excel/CSV and upload that.", 'corrupted');
            }
            // The converted file lives in $work, which the caller cleans up through cleanup().
            $kept = $this->tempDir().'/'.bin2hex(random_bytes(8)).'.'.$target;
            copy($out, $kept);

            return [$kept, $target];
        } catch (ExtractionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning("{$label} conversion failed: ".$e->getMessage());
            throw new ExtractionException("We could not open this .{$type} file. Please save it as .{$target}, PDF or Excel/CSV and upload that.", 'corrupted');
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Whether a configured converter can be started. A bare name is looked up on the PATH by the operating system. */
    private function toolAvailable(string $binary): bool
    {
        try {
            return Process::timeout(15)->run([$binary, '--version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function openZip(string $path): ZipArchive
    {
        $zip = $this->zip($path);
        if ($zip === null || ! $this->zipIsSafe($zip)) {
            throw new ExtractionException("We could not open this file. It may be damaged. Please re-save it, or upload a PDF or Excel/CSV file.", 'corrupted');
        }

        return $zip;
    }

    /** One XML part of a document, refusing a part that is far too large. */
    private function part(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new ExtractionException('We could not open this file. It may be damaged.', 'corrupted');
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw new ExtractionException('This document is too large to read. Please upload a smaller file.', 'too_large');
        }

        return (string) $zip->getFromName($name);
    }

    /** Parse XML without ever loading external entities or the network; a DOCTYPE with entities (entity bombs) is refused. */
    private function safeXml(string $xml): DOMDocument
    {
        if (preg_match('/<!ENTITY/i', $xml)) {
            throw new ExtractionException("We could not open this file. It may be damaged.", 'corrupted');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok) {
            throw new ExtractionException("We could not open this file. It may be damaged.", 'corrupted');
        }

        return $dom;
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function tempDir(): string
    {
        $dir = storage_path('app/private/tmp');
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
