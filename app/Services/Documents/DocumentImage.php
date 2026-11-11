<?php

namespace App\Services\Documents;

use App\Services\MaterialList\OcrEngine;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * PNG/JPG copies of a customer document, rendered from its PDF by Poppler (pdftoppm), so the
 * image is exactly the PDF. Pages are stacked top to bottom in one image, with a light gap.
 */
class DocumentImage
{
    /** 150 DPI: an A4 page is 1240 × 1754 px, sharp on screen and in chat apps. */
    private const DPI = 150;

    private const PAGE_GAP = 24;

    public function __construct(private OcrEngine $ocr) {}

    public function available(): bool
    {
        return function_exists('imagecreatefrompng') && $this->ocr->pdfRendererAvailable();
    }

    /**
     * @param  'png'|'jpg'  $format
     *
     * @throws RuntimeException when the PDF cannot be rendered
     */
    public function fromPdf(string $pdf, string $format): string
    {
        $dir = storage_path('app/private/tmp/doc-image-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($dir);

        try {
            file_put_contents("{$dir}/document.pdf", $pdf);
            $render = Process::timeout(120)->run([
                config('services.ocr.pdftoppm', 'pdftoppm'), '-r', (string) self::DPI, '-png', "{$dir}/document.pdf", "{$dir}/page",
            ]);
            $pages = glob("{$dir}/page*.png") ?: [];
            natsort($pages);
            if (! $render->successful() || ! $pages) {
                Log::warning('Document image export failed: '.mb_substr($render->errorOutput(), 0, 300));
                throw new RuntimeException('The document could not be converted to an image.');
            }

            return $this->stack(array_values($pages), $format);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @param  list<string>  $pages */
    private function stack(array $pages, string $format): string
    {
        $images = array_map(fn ($p) => imagecreatefrompng($p), $pages);
        $width = max(array_map('imagesx', $images));
        $height = array_sum(array_map('imagesy', $images)) + self::PAGE_GAP * (count($images) - 1);

        $sheet = imagecreatetruecolor($width, $height);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 229, 231, 235)); // gap colour between pages
        $y = 0;
        foreach ($images as $image) {
            imagecopy($sheet, $image, 0, $y, 0, 0, imagesx($image), imagesy($image));
            $y += imagesy($image) + self::PAGE_GAP;
            imagedestroy($image);
        }

        ob_start();
        $format === 'jpg' ? imagejpeg($sheet, null, 90) : imagepng($sheet, null, 6);
        imagedestroy($sheet);

        return (string) ob_get_clean();
    }
}
