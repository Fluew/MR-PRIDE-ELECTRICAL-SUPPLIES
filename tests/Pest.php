<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createTrustedDeviceFor(?User $user = null): array
{
    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Test Authorized Device',
        'user_id' => $user?->id,
        'status' => 'approved',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    return [
        'device' => $device,
        'raw_credential' => $rawCredential,
        'cookies' => [
            'mrpride_device_uuid' => $device->device_uuid,
            'mrpride_device_credential' => $rawCredential,
        ],
    ];
}

/**
 * Tesseract-style TSV for a text layout, for faking OCR in tests. Each character is 5px wide
 * (a space is about 0.4 of the 12px letter height, as in real text) and each line 30px tall, so columns can be
 * drawn with spaces; two or more spaces read as a column gap:
 *
 *     ocrTsv(["SKU      Product Name", "BRK-60   Circuit Breaker"])
 *
 * @param  array<int, string>  $lines
 */
function ocrTsv(array $lines): string
{
    $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
    foreach ($lines as $n => $line) {
        preg_match_all('/\S+/u', $line, $words, PREG_OFFSET_CAPTURE);
        foreach ($words[0] as $i => [$word, $byte]) {
            $x = mb_strlen(substr($line, 0, $byte)) * 5;
            $tsv .= implode("\t", [5, 1, 1, 1, $n + 1, $i + 1, $x, 20 + $n * 30, mb_strlen($word) * 5, 12, 95, $word])."\n";
        }
    }

    return $tsv;
}

/** A one-page PDF with a real text layer (Helvetica), one line of text per entry. */
function textPdf(array $lines, string $name = 'list.pdf'): UploadedFile
{
    $y = 780;
    $stream = "BT /F1 11 Tf\n";
    foreach ($lines as $line) {
        $stream .= sprintf("1 0 0 1 50 %d Tm (%s) Tj\n", $y, str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line));
        $y -= 18;
    }
    $stream .= 'ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }
    $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    return UploadedFile::fake()->createWithContent($name, $pdf);
}

/**
 * Replace OCR for uploaded images: images narrower than 500 px read as a product list,
 * wider ones as a photo with no text. Pass false to simulate OCR not being installed.
 */
function fakeImageOcr(bool $available = true): void
{
    app()->instance(OcrEngine::class, new class($available) extends OcrEngine
    {
        public function __construct(private bool $available) {}

        public function tesseractAvailable(): bool
        {
            return $this->available;
        }

        public function imageToTsv(string $imagePath): string
        {
            if (! $this->available) {
                throw new ExtractionException('OCR unavailable', 'ocr_unavailable');
            }
            $page = "1\t1\t0\t0\t0\t0\t0\t0\t400\t300\t-1\t\n";
            $isList = (getimagesize($imagePath)[0] ?? 0) < 500;
            $tsv = ocrTsv($isList ? ['1. Circuit breaker 2P 30A - 20 pcs', '2. THHN wire 3.5mm - 100 meters', '3. PVC conduit 20mm - 50 pieces'] : []);
            [$header, $rest] = explode("\n", $tsv, 2) + [1 => ''];

            return $header."\n".$page.$rest;
        }
    });
}
