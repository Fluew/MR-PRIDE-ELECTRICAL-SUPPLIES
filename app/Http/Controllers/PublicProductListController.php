<?php

namespace App\Http\Controllers;

use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\ProductListExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Public quotation form: read an uploaded product list (PDF, image, Excel or CSV) and return its
 * Description and Quantity rows (and the unit, when the file has one) for the client to review and correct.
 *
 * Nothing is saved here. The client confirms the rows in the form, and only those confirmed
 * rows are sent with the request (PublicQuotationController@store), together with the file.
 */
class PublicProductListController extends Controller
{
    /** Shown for every failure we do not explain more precisely. Never a raw exception. */
    public const GENERIC_ERROR = 'Unable to process this file. Please check the document and try again.';

    public const TOO_LARGE = 'File size exceeds the 25 MB limit. Please upload a smaller file.';

    public function extract(Request $request, ProductListExtractor $extractor): JsonResponse
    {
        $max = FileReader::MAX_COLS - 1;
        // This app turns validation errors into redirects outside /api, so this JSON endpoint validates itself.
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:'.(FileReader::MAX_BYTES / 1024), 'extensions:'.implode(',', FileReader::EXTENSIONS)],
            'mapping' => ['nullable', 'array'],
            'mapping.description' => ['required_with:mapping', 'integer', 'min:0', "max:{$max}"],
            'mapping.quantity' => ['required_with:mapping', 'integer', 'min:0', "max:{$max}"],
            'mapping.unit' => ['nullable', 'integer', 'min:0', "max:{$max}"],
            'header_row' => ['nullable', 'integer', 'min:-1', 'max:39'],
            'sheet' => ['nullable', 'string', 'max:100'],
        ], [
            'file.required' => 'Please choose a file to upload.',
            'file.file' => 'The file could not be uploaded. Please try again.',
            'file.uploaded' => self::TOO_LARGE,
            'file.max' => self::TOO_LARGE,
            'file.extensions' => 'This file type is not supported. Please upload a PDF, PNG, JPG, JPEG, XLS, XLSX or CSV file.',
            'mapping.description.required_with' => 'Please choose the column that holds the description.',
            'mapping.quantity.required_with' => 'Please choose the column that holds the quantity.',
            'mapping.*.integer' => 'Please choose the columns from the list.',
            'mapping.*.min' => 'Please choose the columns from the list.',
            'mapping.*.max' => 'Please choose the columns from the list.',
            'header_row.*' => 'Please choose the header row from the list.',
        ]);
        if ($validator->fails()) {
            return $this->failure($validator->errors()->first(), 'invalid');
        }

        $mapping = null;
        if ($request->filled('mapping')) {
            $mapping = array_map('intval', array_filter(
                array_intersect_key((array) $request->input('mapping'), array_flip(['description', 'quantity', 'unit'])),
                fn ($v) => $v !== null && $v !== '',
            ));
            if (count($mapping) !== count(array_unique($mapping))) {
                return $this->failure('Please choose a different column for each.', 'invalid');
            }
        }
        $headerRow = $request->filled('header_row') && (int) $request->input('header_row') >= 0 ? (int) $request->input('header_row') : null;

        $file = $request->file('file');
        $path = $file->getRealPath() ?: $file->getPathname();
        if (! app()->runningUnitTests()) {
            @set_time_limit(300); // OCR of a 25 MB scan (up to 20 pages) can take a few minutes
        }

        try {
            $result = $extractor->extract($path, $file->getClientOriginalName(), $mapping, $headerRow, $request->input('sheet'));
        } catch (ExtractionException $e) {
            return $this->failure(...self::clientMessage($e));
        } catch (Throwable $e) {
            Log::error('Product list could not be read: '.$e->getMessage(), ['exception' => $e, 'file' => mb_substr($file->getClientOriginalName(), 0, 100)]);

            return $this->failure(self::GENERIC_ERROR, 'server_error', keepFile: true, status: 500);
        }

        $status = $result['status'];
        $choice = $result['choice'];
        if ($status === 'no_products' && ($choice['preview'] ?? []) === []) {
            return $this->failure('No products were found in this file. Please check the document and try again.', 'no_products', keepFile: true);
        }

        return response()->json([
            'file' => ['hash' => $result['hash'], 'type' => $result['type']],
            'method' => $result['method'],
            'ocr' => $result['ocr'],
            'status' => $status === 'ok' ? 'ok' : 'needs_mapping',
            'message' => $this->message($result),
            'notes' => array_values(array_unique($result['notes'])),
            'skipped' => $result['skipped'],
            'sheet' => $result['sheet'],
            'choice' => $choice,
            'items' => array_map(fn (array $item) => [
                'ref' => $item['ref'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'quantity_raw' => $item['quantity_raw'],
                'unit' => $item['unit'],
                'errors' => (object) $item['errors'],
                'checks' => (object) $item['checks'],
                'reliable' => $item['reliable'],
                'duplicate_of' => $item['duplicate_of'],
            ], $result['items']),
        ]);
    }

    /** The message above the rows (or the column choice), in the client's terms. */
    private function message(array $result): ?string
    {
        if ($result['status'] === 'no_products' && ($result['choice']['header_index'] ?? null) !== null) {
            return 'We found the column headers, but no products below them. Please check the file, or choose the columns below.';
        }
        if ($result['status'] !== 'ok') {
            $reasons = array_column($result['choice']['ambiguous'] ?? [], 'reason');

            return trim('Unable to identify the required headers. Please include Description and Quantity. '.implode(' ', $reasons))
                .' You can also choose the columns below.';
        }

        $unsure = collect($result['items'])->contains(fn ($i) => $i['errors'] !== [] || $i['checks'] !== []);
        if (! $unsure) {
            return null;
        }

        return $result['ocr']
            ? 'Some handwritten or scanned entries could not be read clearly. Please review the highlighted fields.'
            : 'Some values need your review. Please check the highlighted fields.';
    }

    /**
     * What the client is told when a file cannot be read, and whether the file can still be sent
     * as it is (it is a valid document our team can read; only automatic reading failed).
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    public static function clientMessage(ExtractionException $e): array
    {
        return match ($e->reason) {
            'ocr_unavailable' => ["We can't read scanned documents or pictures automatically right now. You can still send this file with your request and our team will read it.", $e->reason, true],
            'ocr_failed', 'pdf_render_failed' => ['Reading this file took too long or failed. Please try a clearer or smaller file. You can also send it as it is and our team will read it.', $e->reason, true],
            'busy' => [$e->getMessage(), $e->reason, true],
            'too_large' => [str_contains($e->getMessage(), 'megapixels') ? $e->getMessage() : self::TOO_LARGE, $e->reason, false],
            'unsupported', 'corrupted', 'protected', 'empty', 'too_many_pages', 'no_text' => [$e->getMessage(), $e->reason, false],
            default => [self::GENERIC_ERROR, $e->reason, true],
        };
    }

    private function failure(string $message, string $reason, bool $keepFile = false, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message, 'reason' => $reason, 'keep_file' => $keepFile], $status);
    }
}
