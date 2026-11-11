<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Support\Csv;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inventory list downloads.
 *
 * CSV and Excel hold every column with the same headers the importer reads, so an export can be
 * edited and imported again. PDF is a printable report. PNG/JPG draw the table as an image,
 * ROWS_PER_IMAGE products per image; when that takes more than one image they come as a .zip.
 */
class InventoryExporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf', 'png', 'jpg'];

    public const ROWS_PER_IMAGE = 50;

    /** PDF and image exports are laid out page by page, which is slow for very long lists. */
    public const MAX_DOCUMENT_ROWS = 2000;

    private const COLUMNS = [
        'SKU', 'Product Name', 'Category', 'Brand', 'Unit', 'Cost Price', 'Selling Price',
        'Current Stock', 'Reorder Level', 'Reorder Quantity', 'Supplier', 'Status', 'Description',
    ];

    /**
     * @param  Collection<int, Product>  $products
     * @param  array{team: string, filters: string}  $meta
     */
    public function download(string $format, Collection $products, array $meta): Response
    {
        if (in_array($format, ['pdf', 'png', 'jpg'], true) && $products->count() > self::MAX_DOCUMENT_ROWS) {
            abort(422, "This list has {$products->count()} products, more than the ".self::MAX_DOCUMENT_ROWS.' that fit in a PDF or image export. Filter the list first, or export to Excel or CSV.');
        }

        $rows = $products->map(fn (Product $p) => $this->row($p))->all();
        $name = 'inventory_export_'.now()->format('Y-m-d_His');
        $meta += ['generated' => now()->format('M j, Y g:i A'), 'total' => count($rows)];

        return match ($format) {
            'xlsx' => $this->xlsx($rows, $name),
            'pdf' => $this->pdf($rows, $meta, $name),
            'png', 'jpg' => $this->images($rows, $meta, $format, $name),
            default => $this->csv($rows, $name),
        };
    }

    /** @return array<int, string|int|float> in COLUMNS order */
    private function row(Product $p): array
    {
        $threshold = $p->reorder_level ?: ($p->min_stock ?: 10);
        $status = $p->stock <= 0 ? 'Out of Stock' : ($p->stock <= $threshold ? 'Low Stock' : 'In Stock');

        return [
            (string) $p->sku, (string) $p->name, (string) $p->category, (string) ($p->brand ?? ''), (string) ($p->unit ?: 'pcs'),
            (float) $p->cost_price, (float) $p->price, (int) $p->stock, (int) $threshold, (int) ($p->reorder_quantity ?: 50),
            $p->supplier?->name ?? 'N/A', $status, (string) ($p->description ?? ''),
        ];
    }

    private function csv(array $rows, string $name): Response
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows brand/supplier names correctly
            Csv::put($out, self::COLUMNS);
            foreach ($rows as $r) {
                $r[5] = number_format($r[5], 2, '.', '');
                $r[6] = number_format($r[6], 2, '.', '');
                Csv::put($out, $r);
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(array $rows, string $name): Response
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Inventory');
        $sheet->fromArray(self::COLUMNS, null, 'A1');

        foreach ($rows as $i => $r) {
            foreach ($r as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $i + 2]);
                // Text is stored as text, so a name like "=SUM(...)" is never treated as a formula.
                is_string($value) ? $cell->setValueExplicit($value, DataType::TYPE_STRING) : $cell->setValue($value);
            }
        }

        $last = count($rows) + 1;
        $sheet->getStyle('A1:M1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:M1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4B5320');
        $sheet->getStyle("F2:G{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:M{$last}");
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('M')->setWidth(50);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function pdf(array $rows, array $meta, string $name): Response
    {
        // dompdf needs about 260 MB and 20 seconds for 1,000 products.
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

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('exports.inventory-pdf', ['rows' => $rows, 'meta' => $meta])->render());
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

    private const ROW_H = 30;

    /** [label, width, right-aligned, row index] */
    private const IMG_COLUMNS = [
        ['#', 44, true, null], ['SKU', 150, false, 0], ['Product', 330, false, 1], ['Category', 140, false, 2],
        ['Brand', 120, false, 3], ['Unit', 60, false, 4], ['Cost', 110, true, 5], ['Price', 110, true, 6],
        ['Stock', 76, true, 7], ['Reorder', 80, true, 8], ['Status', 120, false, 11], ['Supplier', 180, false, 10],
    ];

    private function images(array $rows, array $meta, string $ext, string $name): Response
    {
        $pages = array_chunk($rows, self::ROWS_PER_IMAGE) ?: [[]];
        $dir = storage_path('app/private/tmp/export-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir);

        try {
            $files = [];
            foreach ($pages as $i => $page) {
                $img = $this->drawPage($page, $meta, $i + 1, count($pages), $i * self::ROWS_PER_IMAGE);
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

    private function drawPage(array $rows, array $meta, int $page, int $pages, int $offset): \GdImage
    {
        $font = $this->font(false);
        $bold = $this->font(true);
        $top = 118;
        $height = $top + self::ROW_H * (max(1, count($rows)) + 1) + 70;

        $img = imagecreatetruecolor(self::IMG_WIDTH, $height);
        $c = fn (string $hex) => imagecolorallocate($img, ...sscanf($hex, '%02x%02x%02x'));
        $white = $c('ffffff');
        $ink = $c('111827');
        $muted = $c('6b7280');
        $line = $c('e5e7eb');
        $statusColor = ['Out of Stock' => $c('e11d48'), 'Low Stock' => $c('d97706'), 'In Stock' => $c('059669')];
        imagefill($img, 0, 0, $white);

        // Title block
        imagefilledrectangle($img, 0, 0, self::IMG_WIDTH, 6, $c('f8b803'));
        imagettftext($img, 20, 0, self::MARGIN, 52, $ink, $bold, 'Inventory List');
        imagettftext($img, 11, 0, self::MARGIN, 82, $muted, $font, "{$meta['team']}  ·  {$meta['filters']}  ·  Generated {$meta['generated']}");
        $pageLabel = $pages > 1 ? "Image {$page} of {$pages}" : '';
        if ($pageLabel !== '') {
            $this->text($img, 11, $font, $muted, $pageLabel, self::IMG_WIDTH - self::MARGIN - 200, 52, 200, true);
        }

        // Header row
        imagefilledrectangle($img, self::MARGIN, $top, self::IMG_WIDTH - self::MARGIN, $top + self::ROW_H, $c('4b5320'));
        $x = self::MARGIN;
        foreach (self::IMG_COLUMNS as [$label, $w, $right]) {
            $this->text($img, 10, $bold, $white, $label, $x + 8, $top + 20, $w - 16, $right);
            $x += $w;
        }

        // Body
        $y = $top + self::ROW_H;
        if ($rows === []) {
            imagettftext($img, 11, 0, self::MARGIN + 8, $y + 20, $muted, $font, 'No products match the current filters.');
            $y += self::ROW_H;
        }
        foreach ($rows as $n => $r) {
            if ($n % 2 === 1) {
                imagefilledrectangle($img, self::MARGIN, $y, self::IMG_WIDTH - self::MARGIN, $y + self::ROW_H, $c('f9fafb'));
            }
            $x = self::MARGIN;
            foreach (self::IMG_COLUMNS as [$label, $w, $right, $idx]) {
                $value = match ($idx) {
                    null => (string) ($offset + $n + 1),
                    5, 6 => '₱'.number_format($r[$idx], 2),
                    7, 8 => number_format($r[$idx]),
                    default => (string) $r[$idx],
                };
                $color = $idx === 11 ? $statusColor[$value] : ($idx === null ? $muted : $ink);
                $this->text($img, 10, $idx === 11 || $idx === 7 ? $bold : $font, $color, $value, $x + 8, $y + 20, $w - 16, $right);
                $x += $w;
            }
            $y += self::ROW_H;
            imageline($img, self::MARGIN, $y, self::IMG_WIDTH - self::MARGIN, $y, $line);
        }

        $from = $rows === [] ? 0 : $offset + 1;
        imagettftext($img, 10, 0, self::MARGIN, $y + 34, $muted, $font, "Showing products {$from}–".($offset + count($rows))." of {$meta['total']}");

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
