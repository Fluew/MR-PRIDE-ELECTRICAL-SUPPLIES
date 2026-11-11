<?php

namespace App\Services\Reports;

use App\Support\Csv;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads of a Reports page view: one or more tables ("sections"), exactly as the page shows
 * them with its filters applied, as Excel, PDF, CSV, PNG or JPG.
 *
 * Excel has one sheet per section; CSV puts the sections one after another. PDF is a printable
 * document. PNG/JPG draw the tables as images, LINES_PER_IMAGE lines per image; when that takes
 * more than one image they come as a .zip.
 *
 * Column types: text, number, money (money values are already in the page's display currency,
 * which the column label names).
 */
class ReportTableExporter
{
    public const FORMATS = ['xlsx', 'pdf', 'csv', 'png', 'jpg'];

    public const TYPES = ['text', 'number', 'money'];

    public const MAX_SECTIONS = 12;

    public const MAX_COLUMNS = 16;

    public const MAX_ROWS = 20000;

    /** PDF and image exports are laid out page by page, which is slow for very long reports. */
    public const MAX_DOCUMENT_ROWS = 2000;

    public const LINES_PER_IMAGE = 55;

    /**
     * Check and clean what the page sent. Rows are checked here rather than with wildcard
     * validation rules, which are very slow for thousands of cells.
     *
     * @param  array<int, mixed>  $sections
     * @return list<array{title: string, columns: list<array{label: string, type: string}>, rows: list<list<string|int|float|null>>}>
     */
    public function normalize(array $sections): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['sections' => $message]);
        $clean = [];
        $total = 0;

        foreach (array_values($sections) as $s => $section) {
            if (! is_array($section) || ! is_array($section['columns'] ?? null) || ! is_array($section['rows'] ?? null)) {
                $fail('The report data is incomplete.');
            }
            $columns = [];
            foreach (array_values($section['columns']) as $column) {
                $type = is_array($column) ? ($column['type'] ?? 'text') : 'text';
                $columns[] = [
                    'label' => Str::limit(trim((string) (is_array($column) ? ($column['label'] ?? '') : $column)), 80, ''),
                    'type' => in_array($type, self::TYPES, true) ? $type : 'text',
                ];
            }
            if ($columns === [] || count($columns) > self::MAX_COLUMNS) {
                $fail('Each report table needs between 1 and '.self::MAX_COLUMNS.' columns.');
            }

            $rows = [];
            foreach ($section['rows'] as $row) {
                if (! is_array($row) || count($row) !== count($columns)) {
                    $fail('A report row does not match its columns.');
                }
                $cells = [];
                foreach (array_values($row) as $c => $value) {
                    if (! is_scalar($value) && $value !== null) {
                        $fail('A report cell is not a plain value.');
                    }
                    $cells[] = $this->cell($value, $columns[$c]['type']);
                }
                $rows[] = $cells;
            }

            $total += count($rows);
            if ($total > self::MAX_ROWS) {
                $fail('This report has more than '.number_format(self::MAX_ROWS).' rows. Narrow the filters first.');
            }
            $clean[] = [
                'title' => Str::limit(trim((string) ($section['title'] ?? '')) ?: 'Section '.($s + 1), 120, ''),
                'columns' => $columns,
                'rows' => $rows,
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array{title: string, columns: list<array{label: string, type: string}>, rows: list<list<string|int|float|null>>}>  $sections
     * @param  array{title: string, team: string, scope: string}  $meta
     */
    public function download(string $format, array $sections, array $meta): Response
    {
        $rowCount = array_sum(array_map(fn ($s) => count($s['rows']), $sections));
        if (in_array($format, ['pdf', 'png', 'jpg'], true) && $rowCount > self::MAX_DOCUMENT_ROWS) {
            throw ValidationException::withMessages(['format' => "This report has {$rowCount} rows, more than the ".number_format(self::MAX_DOCUMENT_ROWS).' that fit in a PDF or image export. Narrow the filters first, or export to Excel or CSV.']);
        }

        $meta += ['generated' => now()->format('M j, Y g:i A'), 'rows' => $rowCount];
        $name = 'report_'.(Str::slug($meta['title'], '_') ?: 'export').'_'.now()->format('Y-m-d_His');

        return match ($format) {
            'xlsx' => $this->xlsx($sections, $meta, $name),
            'pdf' => $this->pdf($sections, $meta, $name),
            'png', 'jpg' => $this->images($sections, $meta, $format, $name),
            default => $this->csv($sections, $name),
        };
    }

    private function cell(string|int|float|bool|null $value, string $type): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($type !== 'text' && is_numeric($value)) {
            return $value + 0;
        }

        return Str::limit((string) $value, 500);
    }

    /** A cell as it reads on paper or in an image. */
    private function shown(string|int|float|null $value, string $type): string
    {
        return match (true) {
            $value === null => '—',
            is_string($value) => $value,
            $type === 'money' => number_format($value, 2),
            default => floor($value) == $value ? number_format($value) : number_format($value, 2),
        };
    }

    // ------------------------------------------------------------------------------------ csv

    private function csv(array $sections, string $name): Response
    {
        return response()->streamDownload(function () use ($sections) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows names correctly
            $many = count($sections) > 1;
            foreach ($sections as $i => $section) {
                if ($many) {
                    if ($i > 0) {
                        fwrite($out, "\n");
                    }
                    Csv::put($out, [$section['title']]);
                }
                Csv::put($out, array_column($section['columns'], 'label'));
                foreach ($section['rows'] as $row) {
                    Csv::put($out, array_map(
                        fn ($v, $c) => $v === null ? '' : ($c['type'] === 'money' && ! is_string($v) ? number_format($v, 2, '.', '') : $v),
                        $row,
                        $section['columns'],
                    ));
                }
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------------------------ xlsx

    private function xlsx(array $sections, array $meta, string $name): Response
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $used = [];

        foreach ($sections as $section) {
            $sheet = $book->createSheet()->setTitle($this->sheetTitle($section['title'], $used));
            $last = Coordinate::stringFromColumnIndex(count($section['columns']));

            $sheet->setCellValueExplicit('A1', $meta['title'].' — '.$section['title'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('A2', "{$meta['team']} · {$meta['scope']} · Generated {$meta['generated']}", DataType::TYPE_STRING);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $sheet->getStyle('A2')->getFont()->getColor()->setRGB('6B7280');

            $head = 4;
            foreach ($section['columns'] as $c => $column) {
                $sheet->getCell([$c + 1, $head])->setValueExplicit($column['label'], DataType::TYPE_STRING);
            }
            foreach ($section['rows'] as $r => $row) {
                foreach ($row as $c => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $cell = $sheet->getCell([$c + 1, $head + 1 + $r]);
                    // Text is stored as text, so a name like "=SUM(...)" is never treated as a formula.
                    is_string($value) ? $cell->setValueExplicit($value, DataType::TYPE_STRING) : $cell->setValue($value);
                }
            }

            $end = $head + max(1, count($section['rows']));
            $sheet->getStyle("A{$head}:{$last}{$head}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$head}:{$last}{$head}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4B5320');
            foreach ($section['columns'] as $c => $column) {
                $col = Coordinate::stringFromColumnIndex($c + 1);
                if ($column['type'] !== 'text') {
                    $sheet->getStyle("{$col}".($head + 1).":{$col}{$end}")->getNumberFormat()->setFormatCode($column['type'] === 'money' ? '#,##0.00' : '#,##0.##');
                    $sheet->getStyle("{$col}{$head}:{$col}{$end}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            $sheet->freezePane('A'.($head + 1));
            if ($section['rows'] !== []) {
                $sheet->setAutoFilter("A{$head}:{$last}{$end}");
            }
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Excel sheet names: at most 31 characters, none of : \ / ? * [ ], and unique. */
    private function sheetTitle(string $title, array &$used): string
    {
        $base = Str::limit(trim(preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $title)) ?: 'Sheet', 28, '');
        $name = $base;
        for ($n = 2; in_array(mb_strtolower($name), $used, true); $n++) {
            $name = "{$base} {$n}";
        }
        $used[] = mb_strtolower($name);

        return $name;
    }

    // ------------------------------------------------------------------------------------ pdf

    private function pdf(array $sections, array $meta, string $name): Response
    {
        if ((int) ini_get('memory_limit') !== -1 && $this->bytes(ini_get('memory_limit')) < 768 * 1024 * 1024) {
            ini_set('memory_limit', '768M');
        }
        if (! app()->runningUnitTests()) { // the timer would also limit the rest of a test run
            set_time_limit(180);
        }

        $tmp = storage_path('app/private/tmp');
        File::ensureDirectoryExists($tmp);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans'); // has the peso sign
        $options->setIsRemoteEnabled(false);
        $options->setTempDir($tmp);
        $options->setChroot(base_path());

        $shown = fn ($value, $type) => $this->shown($value, $type);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('exports.report-pdf', compact('sections', 'meta', 'shown'))->render());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(760, 570, 'Page {PAGE_NUM} of {PAGE_COUNT}', 'DejaVu Sans', 7, [0.45, 0.45, 0.45]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
        ]);
    }

    // ------------------------------------------------------------------------------------ images

    private const IMG_WIDTH = 1600;

    private const MARGIN = 40;

    private const TOP = 112;

    private const LINE_H = ['title' => 40, 'header' => 30, 'row' => 30, 'empty' => 30];

    private function images(array $sections, array $meta, string $ext, string $name): Response
    {
        $pages = $this->layout($sections);
        $dir = storage_path('app/private/tmp/export-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir);

        try {
            $files = [];
            foreach ($pages as $i => $lines) {
                $img = $this->drawPage($lines, $meta, $i + 1, count($pages));
                $file = $dir.'/'.$name.(count($pages) > 1 ? '_page'.($i + 1) : '').'.'.$ext;
                $ext === 'png' ? imagepng($img, $file, 6) : imagejpeg($img, $file, 90);
                imagedestroy($img);
                $files[] = $file;
            }

            if (count($files) === 1) {
                return response((string) file_get_contents($files[0]), 200, [
                    'Content-Type' => $ext === 'png' ? 'image/png' : 'image/jpeg',
                    'Content-Disposition' => 'attachment; filename="'.basename($files[0]).'"',
                ]);
            }

            $zipPath = $dir.'/'.$name.'.zip';
            $zip = new \ZipArchive;
            $zip->open($zipPath, \ZipArchive::CREATE);
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();

            return response((string) file_get_contents($zipPath), 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => "attachment; filename=\"{$name}_{$ext}.zip\"",
            ]);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Split the sections into image pages of lines (section title, column header, row). A section
     * that continues on the next image repeats its title and header there.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function layout(array $sections): array
    {
        $font = $this->font(false);
        $bold = $this->font(true);
        $pages = [];
        $page = [];

        foreach ($sections as $section) {
            $widths = $this->columnWidths($section, $font, $bold);
            $title = ['kind' => 'title', 'text' => $section['title']];
            $header = ['kind' => 'header', 'columns' => $section['columns'], 'widths' => $widths];

            // Keep a section title with its header and at least one row.
            if (count($page) + 3 > self::LINES_PER_IMAGE && $page !== []) {
                $pages[] = $page;
                $page = [];
            }
            array_push($page, $title, $header);
            if ($section['rows'] === []) {
                $page[] = ['kind' => 'empty'];
            }
            foreach ($section['rows'] as $n => $row) {
                if (count($page) >= self::LINES_PER_IMAGE) {
                    $pages[] = $page;
                    $page = [['kind' => 'title', 'text' => $section['title'].' (continued)'], $header];
                }
                $page[] = ['kind' => 'row', 'cells' => $row, 'columns' => $section['columns'], 'widths' => $widths, 'odd' => $n % 2 === 1];
            }
        }
        $pages[] = $page;

        return $pages;
    }

    /**
     * Widths from each column's longest text, scaled to the image width. Small tables (up to three
     * columns, e.g. a status summary) keep a compact width instead of stretching across it.
     *
     * @return list<int>
     */
    private function columnWidths(array $section, string $font, string $bold): array
    {
        $measure = fn (string $text, string $f) => ($b = imagettfbbox(10, 0, $f, $text)) ? $b[2] - $b[0] : 0;
        $natural = [];
        foreach ($section['columns'] as $c => $column) {
            $w = $measure($column['label'], $bold);
            foreach (array_slice($section['rows'], 0, 300) as $row) { // a sample is enough to size columns
                $w = max($w, $measure($this->shown($row[$c], $column['type']), $font));
            }
            $natural[] = min(420, max(56, $w + 20));
        }
        $total = array_sum($natural);
        $full = self::IMG_WIDTH - 2 * self::MARGIN;
        $scale = (count($natural) > 3 ? $full : min($full, max($total, 640))) / $total;

        return array_map(fn ($w) => (int) floor($w * $scale), $natural);
    }

    private function drawPage(array $lines, array $meta, int $page, int $pages): \GdImage
    {
        $font = $this->font(false);
        $bold = $this->font(true);
        $height = self::TOP + array_sum(array_map(fn ($l) => self::LINE_H[$l['kind']], $lines)) + 60;

        $img = imagecreatetruecolor(self::IMG_WIDTH, $height);
        $c = fn (string $hex) => imagecolorallocate($img, ...sscanf($hex, '%02x%02x%02x'));
        $white = $c('ffffff');
        $ink = $c('111827');
        $muted = $c('6b7280');
        $rule = $c('e5e7eb');
        $stripe = $c('f9fafb');
        $brand = $c('4b5320');
        imagefill($img, 0, 0, $white);

        imagefilledrectangle($img, 0, 0, self::IMG_WIDTH, 6, $c('f8b803'));
        imagettftext($img, 20, 0, self::MARGIN, 50, $ink, $bold, $meta['title']);
        $this->text($img, 11, $font, $muted, "{$meta['team']}  ·  {$meta['scope']}  ·  Generated {$meta['generated']}", self::MARGIN, 80, self::IMG_WIDTH - 2 * self::MARGIN - 160);
        if ($pages > 1) {
            $this->text($img, 11, $font, $muted, "Image {$page} of {$pages}", self::IMG_WIDTH - self::MARGIN - 160, 50, 160, true);
        }

        $y = self::TOP;
        foreach ($lines as $line) {
            $h = self::LINE_H[$line['kind']];
            $right = isset($line['widths']) ? self::MARGIN + array_sum($line['widths']) : self::IMG_WIDTH - self::MARGIN;
            switch ($line['kind']) {
                case 'title':
                    $this->text($img, 13, $bold, $ink, $line['text'], self::MARGIN, $y + 28, $right - self::MARGIN);
                    break;
                case 'header':
                    imagefilledrectangle($img, self::MARGIN, $y, $right, $y + $h, $brand);
                    $x = self::MARGIN;
                    foreach ($line['columns'] as $i => $column) {
                        $this->text($img, 10, $bold, $white, $column['label'], $x + 8, $y + 20, $line['widths'][$i] - 16, $column['type'] !== 'text');
                        $x += $line['widths'][$i];
                    }
                    break;
                case 'empty':
                    imagettftext($img, 11, 0, self::MARGIN + 8, $y + 20, $muted, $font, 'No records match the current filters.');
                    break;
                default:
                    if ($line['odd']) {
                        imagefilledrectangle($img, self::MARGIN, $y, $right, $y + $h, $stripe);
                    }
                    $x = self::MARGIN;
                    foreach ($line['columns'] as $i => $column) {
                        $value = $line['cells'][$i];
                        $this->text($img, 10, $font, $value === null ? $muted : $ink, $this->shown($value, $column['type']), $x + 8, $y + 20, $line['widths'][$i] - 16, $column['type'] !== 'text');
                        $x += $line['widths'][$i];
                    }
                    imageline($img, self::MARGIN, $y + $h, $right, $y + $h, $rule);
            }
            $y += $h;
        }

        imagettftext($img, 10, 0, self::MARGIN, $y + 34, $muted, $font, number_format($meta['rows']).' rows in this report');

        return $img;
    }

    /** Draw text cut to fit $maxWidth (with an ellipsis), left- or right-aligned in its column. */
    private function text(\GdImage $img, float $size, string $font, int $color, string $text, int $x, int $y, int $maxWidth, bool $right = false): void
    {
        $width = fn (string $s) => ($b = imagettfbbox($size, 0, $font, $s)) ? $b[2] - $b[0] : 0;
        if ($width($text) > $maxWidth) {
            while (mb_strlen($text) > 1 && $width($text.'…') > $maxWidth) {
                $text = mb_substr($text, 0, -1);
            }
            $text = rtrim($text).'…';
        }
        imagettftext($img, $size, 0, $right ? $x + $maxWidth - $width($text) : $x, $y, $color, $font, $text);
    }

    private function bytes(string $limit): int
    {
        $n = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** DejaVu Sans ships with dompdf, so images render the same on any server. */
    private function font(bool $bold): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans'.($bold ? '-Bold' : '').'.ttf');
    }
}
