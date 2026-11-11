<?php

namespace App\Services\MaterialList;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Tesseract OCR for images and scanned PDF pages, and Poppler for PDFs.
 *
 * Tesseract and Poppler's `pdftoppm` (PDF pages to images) and `pdftotext` (word positions in
 * text PDFs) run as separate processes with time limits; their locations are configurable
 * (services.ocr). Images are preprocessed with GD first: grayscale, upscaling small scans,
 * light-on-dark bands (table header bars) flipped to dark-on-light, deskew (pages tilted up to
 * 5°), contrast, light denoise; Tesseract then thresholds (adaptive Sauvola on the retry pass).
 *
 * Text comes back either plain (imageToText, pdfToText) or as TSV with each word's position
 * (imageToTsv, pdfToTsv, pdfTextTsv) for rebuilding tables, see WordTable.
 */
class OcrEngine
{
    /**
     * Narrower images are enlarged (up to 3x) to this width before OCR: an A4 page at 300 DPI,
     * where Tesseract reads best. Screenshots and exports are often 1,200-1,600 px wide with
     * letters too small to read reliably ("60A" read as "608").
     */
    private const OCR_WIDTH = 2480;

    /**
     * Larger pictures (phone photos of 48+ megapixels) are shrunk to fit this box before OCR:
     * well above 300 DPI for a page, and much faster and lighter on memory than the original.
     */
    private const OCR_MAX_WIDTH = 3600;

    private const OCR_MAX_HEIGHT = 5000;

    /** @var array<string, bool> tool availability, checked once per request */
    private array $probes = [];

    public function tesseractAvailable(): bool
    {
        return $this->probe(config('services.ocr.tesseract', 'tesseract'), ['--version']);
    }

    public function pdfRendererAvailable(): bool
    {
        return $this->probe(config('services.ocr.pdftoppm', 'pdftoppm'), ['-v']);
    }

    public function pdfTextAvailable(): bool
    {
        return $this->probe($this->pdftotextBinary(), ['-v']);
    }

    /** OCR one image file. Throws ExtractionException with a client-safe message on failure. */
    public function imageToText(string $imagePath): string
    {
        return $this->tesseract($imagePath, []);
    }

    /** OCR one image file, returning Tesseract's TSV (one row per word, with its position). */
    public function imageToTsv(string $imagePath): string
    {
        return $this->tesseract($imagePath, ['tsv']);
    }

    /**
     * Render scanned PDF pages to images and OCR them.
     *
     * @return array<int, string> text per page
     */
    public function pdfToText(string $pdfPath, int $pages): array
    {
        return $this->ocrPdf($pdfPath, $pages, fn (string $image) => $this->imageToText($image));
    }

    /** @return array<int, string> Tesseract TSV per scanned page */
    public function pdfToTsv(string $pdfPath, int $pages): array
    {
        return $this->ocrPdf($pdfPath, $pages, fn (string $image) => $this->imageToTsv($image));
    }

    /** Word positions in a PDF's text layer (pdftotext -tsv, all pages). Null when unavailable or failed. */
    public function pdfTextTsv(string $pdfPath): ?string
    {
        if (! $this->pdfTextAvailable()) {
            return null;
        }

        try {
            $result = Process::timeout((int) config('services.ocr.timeout', 60))->run([$this->pdftotextBinary(), '-tsv', '-enc', 'UTF-8', $pdfPath, '-']);
        } catch (\Throwable $e) {
            Log::warning('pdftotext failed: '.$e->getMessage());

            return null;
        }

        return $result->successful() ? $result->output() : null;
    }

    /** @param  array<int, string>  $configs  Tesseract output configs, e.g. ['tsv'] */
    private function tesseract(string $imagePath, array $configs): string
    {
        if (! $this->tesseractAvailable()) {
            throw new ExtractionException('Text recognition (OCR) is not available on the server right now. Please type your material list below instead.', 'ocr_unavailable');
        }

        $work = $this->preprocess($imagePath, false);
        $enhancedWork = null;
        $isTsv = in_array('tsv', $configs, true);
        $output = null;
        try {
            // Single-column mode (--psm 4) runs Tesseract's line finder, which removes table
            // borders first. Uniform-block mode (--psm 6) does not, and loses whole rows of
            // bordered tables.
            $result = $this->runTesseractProcess($work, '4', $configs);
            $read = $work;

            // If it found no usable words on a blurry, faded, or multi-block layout, fall back
            // to enhanced sharpening + automatic page segmentation (--psm 3).
            if ($result->successful() && ! $this->hasUsableOutput($result->output(), $isTsv)) {
                $enhancedWork = $this->preprocess($imagePath, true);
                // Adaptive (Sauvola) thresholding copes with uneven lighting and faded print
                // better than Tesseract's default global Otsu threshold.
                $retry = $this->runTesseractProcess($enhancedWork, '3', $configs, ['-c', 'thresholding_method=2']);
                if ($retry->successful() && $this->hasUsableOutput($retry->output(), $isTsv)) {
                    $result = $retry;
                    $read = $enhancedWork;
                }
            }

            // The line finder can drop a lone short number standing far from the rest of its
            // row (a handwritten "8" in a Qty column). A second, uniform-block pass on the same
            // picture adds such numbers where nothing was read at all (see addMissedNumbers).
            if ($isTsv && $result->successful() && $this->hasUsableOutput($result->output(), true)) {
                $second = $this->runTesseractProcess($read, '6', $configs);
                $output = $second->successful() ? self::addMissedNumbers($result->output(), $second->output()) : null;
            }
        } catch (\Throwable $e) {
            Log::warning('Tesseract OCR failed: '.$e->getMessage());
            throw new ExtractionException('Text recognition took too long or failed. Please try a clearer image, or type your material list below.', 'ocr_failed');
        } finally {
            if ($work !== $imagePath) {
                @unlink($work);
            }
            if ($enhancedWork !== null && $enhancedWork !== $imagePath) {
                @unlink($enhancedWork);
            }
        }

        if (! $result->successful()) {
            Log::warning('Tesseract OCR exited with an error: '.mb_substr($result->errorOutput(), 0, 300));
            throw new ExtractionException('Text recognition failed for this file. Please try a clearer image, or type your material list below.', 'ocr_failed');
        }

        return $output ?? $result->output();
    }

    /** Confidence given to numbers added by the second pass: below ProductListParser::LOW_CONFIDENCE, so they are reviewed. */
    public const ADDED_NUMBER_CONFIDENCE = 50;

    /**
     * Add to Tesseract TSV the numbers a second pass read where the first read nothing: only
     * plain numbers ("8", "15", "2.5"), read with 60%+ confidence, that touch no word of the
     * first pass. Each becomes its own line (WordTable joins it to its row) with a lowered
     * confidence, so the value is shown but flagged for the client to check.
     */
    public static function addMissedNumbers(string $tsv, string $secondTsv): string
    {
        $boxes = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($tsv)) ?: [] as $i => $row) {
            $c = explode("\t", $row);
            if ($i > 0 && count($c) >= 12 && $c[0] === '5' && trim($c[11]) !== '') {
                $boxes[] = [(float) $c[6], (float) $c[7], (float) $c[6] + (float) $c[8], (float) $c[7] + (float) $c[9]];
            }
        }

        $added = '';
        $n = 0;
        foreach (preg_split('/\r\n|\n|\r/', trim($secondTsv)) ?: [] as $i => $row) {
            $c = explode("\t", $row);
            if ($i === 0 || count($c) < 12 || $c[0] !== '5') {
                continue;
            }
            $text = trim($c[11]);
            if (! preg_match('/^\d{1,7}(?:[.,]\d{1,3})?$/', $text) || (float) $c[10] < 60) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = [(float) $c[6], (float) $c[7], (float) $c[6] + (float) $c[8], (float) $c[7] + (float) $c[9]];
            foreach ($boxes as [$a0, $b0, $a1, $b1]) {
                if ($x0 < $a1 && $a0 < $x1 && $y0 < $b1 && $b0 < $y1) {
                    continue 2; // the first pass read something here
                }
            }
            $n++;
            $added .= implode("\t", [5, $c[1], 9000 + $n, 1, 1, 1, $c[6], $c[7], $c[8], $c[9], self::ADDED_NUMBER_CONFIDENCE, $text])."\n";
        }

        return $added === '' ? $tsv : rtrim($tsv, "\r\n")."\n".$added;
    }

    /**
     * @param  array<int, string>  $configs
     * @param  array<int, string>  $options  extra Tesseract options (before the config files)
     */
    private function runTesseractProcess(string $workPath, string $psm, array $configs, array $options = []): ProcessResult
    {
        return Process::timeout((int) config('services.ocr.timeout', 60))->run([
            config('services.ocr.tesseract', 'tesseract'),
            $workPath,
            'stdout',
            '--psm',
            $psm,
            '--dpi',
            '300',
            '-l',
            config('services.ocr.lang', 'eng'),
            ...$options,
            ...$configs,
        ]);
    }

    private function hasUsableOutput(string $output, bool $isTsv): bool
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return false;
        }

        if (! $isTsv) {
            return (bool) preg_match('/[A-Za-z0-9]{2,}/u', $trimmed);
        }

        foreach (preg_split('/\R/u', $trimmed) ?: [] as $i => $line) {
            if ($i === 0 || $line === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) >= 12 && (int) $cols[0] === 5 && trim($cols[11]) !== '' && (float) $cols[10] >= 25) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function ocrPdf(string $pdfPath, int $pages, callable $ocrImage): array
    {
        if (! $this->pdfRendererAvailable() || ! $this->tesseractAvailable()) {
            throw new ExtractionException('This PDF looks scanned (it contains images, not text), and text recognition is not available on the server right now. Please type your material list below, or upload an Excel/CSV file.', 'ocr_unavailable');
        }

        $dir = storage_path('app/private/tmp/ocr-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir);

        try {
            $render = Process::timeout((int) config('services.ocr.timeout', 60) * 2)->run([
                config('services.ocr.pdftoppm', 'pdftoppm'), '-r', '300', '-gray', '-png', '-f', '1', '-l', (string) $pages, $pdfPath, $dir.'/page',
            ]);
            if (! $render->successful()) {
                Log::warning('pdftoppm failed: '.mb_substr($render->errorOutput(), 0, 300));
                if (stripos($render->errorOutput(), 'password') !== false || stripos($render->errorOutput(), 'encrypt') !== false) {
                    throw new ExtractionException('This PDF is password-protected. Please remove the password and upload it again.', 'protected');
                }
                throw new ExtractionException('We could not read this scanned PDF. Please upload a clearer copy, or type your material list below.', 'pdf_render_failed');
            }

            $texts = [];
            $images = glob($dir.'/page*.png') ?: [];
            natsort($images);
            foreach ($images as $image) {
                $texts[] = $ocrImage($image);
            }

            return $texts;
        } catch (ExtractionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Scanned PDF OCR failed: '.$e->getMessage());
            throw new ExtractionException('Reading this scanned PDF took too long or failed. Please try a smaller file, or type your material list below.', 'ocr_failed');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Auto-orient EXIF + grayscale + upscale small images + flip dark bands + contrast + light denoise (or sharpening on retry). Returns a temp PNG path. */
    private function preprocess(string $path, bool $enhanceBlurry = false): string
    {
        $info = @getimagesize($path);
        if (! $info || ! function_exists('imagecreatefromstring')) {
            return $path;
        }
        // Too big to process in memory: Tesseract reads the original image instead.
        $width = max(1, (int) $info[0]);
        $scale = $width < self::OCR_WIDTH ? min(3, self::OCR_WIDTH / $width) : 1;
        if (! $this->makeRoomFor((int) ($width * $info[1] * $scale ** 2))) {
            return $path;
        }

        $img = @imagecreatefromstring((string) file_get_contents($path));
        if (! $img) {
            return $path;
        }

        $img = $this->autoOrient($img, $path, (int) ($info[2] ?? 0));
        $w = imagesx($img);
        $h = imagesy($img);

        // Tesseract reads best at roughly 300 DPI; small scans or phone crops are upscaled cleanly,
        // and very large photos are shrunk (keeping their proportions).
        $shrink = min(1, self::OCR_MAX_WIDTH / max(1, $w), self::OCR_MAX_HEIGHT / max(1, $h));
        if ($shrink < 1) {
            $scaled = imagescale($img, max(1, (int) ($w * $shrink)), max(1, (int) ($h * $shrink)), IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
                $w = imagesx($img);
                $h = imagesy($img);
            }
        } elseif ($w < self::OCR_WIDTH && $w > 0) {
            $scale = min(3, self::OCR_WIDTH / $w);
            $scaled = imagescale($img, (int) ($w * $scale), (int) ($h * $scale), IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
            }
        }

        imagefilter($img, IMG_FILTER_GRAYSCALE);
        $this->invertDarkBands($img);
        $img = $this->deskew($img);

        if ($enhanceBlurry) {
            $this->normalizeLevels($img);
            imagefilter($img, IMG_FILTER_CONTRAST, -20);
            if (function_exists('imageconvolution')) {
                @imageconvolution($img, [
                    [0.0, -0.5, 0.0],
                    [-0.5, 3.0, -0.5],
                    [0.0, -0.5, 0.0],
                ], 1.0, 0.0);
            }
        } else {
            imagefilter($img, IMG_FILTER_CONTRAST, -25);
            imagefilter($img, IMG_FILTER_SMOOTH, 6);
        }

        $out = storage_path('app/private/tmp/ocr-'.bin2hex(random_bytes(8)).'.png');
        File::ensureDirectoryExists(dirname($out));
        imagepng($img, $out);
        imagedestroy($img);

        return $out;
    }

    /**
     * GD keeps 4 bytes per pixel and its filters work on a copy, so one 300 DPI A4 page
     * (8.7 million pixels) needs about 100 MB more than PHP's usual 128 MB limit leaves.
     * Raise the limit for this request when needed (up to 1 GB). False when there is no room.
     */
    private function makeRoomFor(int $pixels): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        $needed = memory_get_usage(true) + $pixels * 4 * 3 + 32 * 1024 ** 2;
        if ($needed <= $bytes) {
            return true;
        }
        if ($needed > 1024 ** 3) {
            return false;
        }

        return ini_set('memory_limit', (string) (int) ceil($needed / 1024 ** 2).'M') !== false;
    }

    /** Rotate JPEG photos that carry EXIF orientation metadata (common on mobile phone captures). */
    private function autoOrient(\GdImage $img, string $path, int $imageType): \GdImage
    {
        if ($imageType !== IMAGETYPE_JPEG || ! function_exists('exif_read_data') || ! function_exists('imagerotate')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $img;
        }

        $rotated = @imagerotate($img, $angle, 0xFFFFFF);
        if ($rotated) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }

    /** Stretch dim or washed-out grayscale scans so background approaches white and ink approaches black. */
    private function normalizeLevels(\GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $stepX = max(1, intdiv($w, 120));
        $stepY = max(1, intdiv($h, 120));

        $samples = [];
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $samples[] = imagecolorat($img, $x, $y) & 0xFF;
            }
        }

        if (count($samples) < 16) {
            return;
        }

        sort($samples);
        $low = $samples[(int) floor(count($samples) * 0.05)];
        $high = $samples[(int) floor(count($samples) * 0.95)];

        // If the scan is noticeably dark/gray in the paper background, brighten it before contrast boost.
        if ($high < 215 && $high > $low) {
            $boost = min(60, 235 - $high);
            imagefilter($img, IMG_FILTER_BRIGHTNESS, $boost);
        }
    }

    /**
     * Tesseract misses light text on a dark background, such as a table's header bar. Rows of
     * pixels that are mostly dark (median brightness, so the light letters do not count) are
     * found by sampling, and each such band is inverted between the bar's left and right ends.
     * The light page margins beside the bar are left alone: inverted, they turn into black
     * blocks that OCR reads as "|" or "a" and that become extra columns.
     */
    private function invertDarkBands(\GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, intdiv($w, 200));

        $dark = [];
        for ($y = 0; $y < $h; $y++) {
            $samples = [];
            for ($x = 0; $x < $w; $x += $step) {
                $samples[] = imagecolorat($img, $x, $y) & 0xFF; // grayscale: any channel is the brightness
            }
            sort($samples);
            $dark[$y] = $samples[intdiv(count($samples), 2)] < 110;
        }

        $start = null;
        for ($y = 0; $y <= $h; $y++) {
            if ($y < $h && $dark[$y]) {
                $start ??= $y;

                continue;
            }
            if ($start !== null && $y - $start >= 8) {
                [$x0, $x1] = $this->darkExtent($img, $start, $y, $step);
                $band = imagecreatetruecolor($x1 - $x0, $y - $start);
                imagecopy($band, $img, 0, 0, $x0, $start, $x1 - $x0, $y - $start);
                imagefilter($band, IMG_FILTER_NEGATE);
                // An inverted coloured bar turns light grey, not white, and Tesseract skips a
                // grey block with its header words in it. Its background is made white.
                $background = $this->medianBrightness($band);
                if ($background < 250) {
                    imagefilter($band, IMG_FILTER_BRIGHTNESS, 255 - $background);
                }
                imagecopy($img, $band, $x0, $start, 0, 0, $x1 - $x0, $y - $start);
                imagedestroy($band);
            }
            $start = null;
        }
    }

    /**
     * Straighten a page scanned or photographed at a slight angle (up to 5°), so each table row
     * stays on one line and its dates and numbers stay in the right row and column (WordTable).
     * The angle is the one at which the dark pixels line up best into horizontal rows (the
     * projection profile), measured on a small copy. Pages that are already straight (under
     * 0.3°) are left untouched.
     */
    public function deskew(\GdImage $img): \GdImage
    {
        $angle = $this->skewAngle($img);
        if (abs($angle) < 0.3) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, imagecolorallocate($img, 255, 255, 255));
        if (! $rotated) {
            return $img;
        }
        imagedestroy($img);

        return $rotated;
    }

    /** The angle (degrees, counter-clockwise) that straightens the text lines of a grayscale image. */
    public function skewAngle(\GdImage $img): float
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sw = min(800, $w);
        $sh = max(1, (int) round($h * $sw / max(1, $w)));
        $small = imagescale($img, $sw, $sh, IMG_BILINEAR_FIXED);
        if (! $small) {
            return 0.0;
        }
        imagepalettetotruecolor($small);
        $dark = (int) ($this->medianBrightness($small) * 0.6);
        $points = [];
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x += 2) {
                if ((imagecolorat($small, $x, $y) & 0xFF) < $dark) {
                    $points[] = [$x - $sw / 2, $y - $sh / 2];
                }
            }
        }
        imagedestroy($small);
        if (count($points) < 300) {
            return 0.0;
        }
        if (count($points) > 40000) {
            $step = (int) ceil(count($points) / 40000);
            $points = array_values(array_filter($points, fn ($k) => $k % $step === 0, ARRAY_FILTER_USE_KEY));
        }

        // Sharper row profile (larger sum of squared row counts) = better aligned text lines.
        $score = function (float $deg) use ($points): float {
            $sin = sin(deg2rad($deg));
            $cos = cos(deg2rad($deg));
            $rows = [];
            foreach ($points as [$x, $y]) {
                $k = (int) round($y * $cos - $x * $sin);
                $rows[$k] = ($rows[$k] ?? 0) + 1;
            }

            return (float) array_sum(array_map(fn ($n) => $n * $n, $rows));
        };

        $best = 0.0;
        $bestScore = $score(0.0);
        foreach ([range(-5.0, 5.0, 0.5), null] as $pass) {
            $angles = $pass ?? range($best - 0.4, $best + 0.4, 0.1);
            foreach ($angles as $a) {
                $s = $score((float) $a);
                if ($s > $bestScore * 1.0001) {
                    $best = (float) $a;
                    $bestScore = $s;
                }
            }
        }

        // A text line at angle θ in image coordinates (y down) is level after rotating it
        // counter-clockwise on screen by θ, which is what imagerotate() does.
        return round($best, 1);
    }

    /** Median brightness of a grayscale image, from about 2,000 samples. */
    private function medianBrightness(\GdImage $img): int
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $samples = [];
        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 20))) {
            for ($x = 0; $x < $w; $x += max(1, intdiv($w, 100))) {
                $samples[] = imagecolorat($img, $x, $y) & 0xFF;
            }
        }
        sort($samples);

        return $samples[intdiv(count($samples), 2)] ?? 255;
    }

    /**
     * Left and right end of a dark band (rows $top to $bottom): the outermost sampled columns
     * that are dark in most of the band's rows.
     *
     * @return array{0: int, 1: int}
     */
    private function darkExtent(\GdImage $img, int $top, int $bottom, int $step): array
    {
        $w = imagesx($img);
        $rowStep = max(1, intdiv($bottom - $top, 12));
        $isDark = function (int $x) use ($img, $top, $bottom, $rowStep): bool {
            $darkRows = 0;
            $rows = 0;
            for ($y = $top; $y < $bottom; $y += $rowStep) {
                $rows++;
                $darkRows += (imagecolorat($img, $x, $y) & 0xFF) < 110 ? 1 : 0;
            }

            return $darkRows * 2 > $rows;
        };

        $first = null;
        $last = null;
        for ($x = 0; $x < $w; $x += $step) {
            if ($isDark($x)) {
                $first ??= $x;
                $last = $x;
            }
        }
        if ($first === null) {
            return [0, $w];
        }

        // Find the bar's exact edges between the samples, so no strip of it (or of the margin) is left over.
        while ($first > 0 && $isDark($first - 1)) {
            $first--;
        }
        while ($last < $w - 1 && $isDark($last + 1)) {
            $last++;
        }

        return [$first, $last + 1];
    }

    /** pdftotext sits next to pdftoppm in every Poppler install, so it defaults to that folder. */
    private function pdftotextBinary(): string
    {
        $configured = config('services.ocr.pdftotext');
        if ($configured) {
            return $configured;
        }
        $pdftoppm = (string) config('services.ocr.pdftoppm', 'pdftoppm');

        return preg_replace('/pdftoppm(\.exe)?$/i', 'pdftotext$1', $pdftoppm) ?: 'pdftotext';
    }

    private function probe(string $binary, array $args): bool
    {
        $key = $binary.' '.implode(' ', $args);
        if (array_key_exists($key, $this->probes)) {
            return $this->probes[$key];
        }

        try {
            $this->probes[$key] = Process::timeout(10)->run([$binary, ...$args])->successful();
        } catch (\Throwable) {
            $this->probes[$key] = false;
        }

        return $this->probes[$key];
    }
}
