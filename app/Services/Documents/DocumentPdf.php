<?php

namespace App\Services\Documents;

use App\Models\DeliveryRelease;
use App\Models\PaymentReceipt;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-facing PDFs. They never contain supplier costs, margins or internal notes.
 * Receipts and invoices are stored when issued, so the stored file is exactly what was issued.
 */
class DocumentPdf
{
    public function quotation(Quotation $quotation): string
    {
        // The official MR-PRIDE quotation template; every page's footer names the quotation.
        return $this->render(
            'documents.quotation',
            ['quotation' => $quotation, 'doc' => QuotationDocument::data($quotation)],
            'Quotation '.$quotation->quotation_number,
            'Quotation No. '.$quotation->quotation_number,
        );
    }

    public function salesOrder(Sale $sale): string
    {
        $sale->loadMissing(['items', 'quotation']);

        return $this->render('documents.sales-order', ['sale' => $sale], 'Sales Order '.$sale->order_number);
    }

    public function receipt(PaymentReceipt $receipt): string
    {
        $receipt->loadMissing(['sale', 'payment']);

        return $this->render('documents.receipt', ['receipt' => $receipt, 'sale' => $receipt->sale], 'Acknowledgment Receipt '.$receipt->receipt_number);
    }

    public function invoice(SalesInvoice $invoice): string
    {
        $invoice->loadMissing('sale');

        return $this->render('documents.invoice', ['invoice' => $invoice, 'sale' => $invoice->sale], 'Sales Invoice '.$invoice->invoice_number);
    }

    public function deliveryReceipt(DeliveryRelease $release): string
    {
        $release->loadMissing(['items.saleItem', 'sale', 'fulfillment']);

        return $this->render('documents.delivery-receipt', ['release' => $release, 'sale' => $release->sale], 'Delivery Receipt '.$release->release_number);
    }

    /** Save a PDF on the private disk and return its path. */
    public function store(string $pdf, string $folder, string $number): string
    {
        $path = "documents/{$folder}/".preg_replace('/[^A-Za-z0-9-]/', '', $number).'-'.bin2hex(random_bytes(4)).'.pdf';
        Storage::disk('local')->put($path, $pdf);

        return $path;
    }

    /** @param  array<string, mixed>  $data */
    private function render(string $view, array $data, string $title, ?string $footer = null): string
    {
        $tmp = storage_path('app/private/tmp');
        File::ensureDirectoryExists($tmp);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setTempDir($tmp);
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data + ['company' => config('company'), 'title' => $title])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(500, 815, 'Page {PAGE_NUM} of {PAGE_COUNT}', 'DejaVu Sans', 7, [0.45, 0.45, 0.45]);
        if ($footer !== null) {
            $dompdf->getCanvas()->page_text(40, 815, $footer, 'DejaVu Sans', 7, [0.45, 0.45, 0.45]);
        }

        return (string) $dompdf->output();
    }
}
