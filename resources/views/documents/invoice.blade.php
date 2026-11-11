@extends('documents.layout')

@section('doc-title', 'SALES INVOICE')
@section('doc-number', $invoice->invoice_number)
@section('doc-date', 'Invoice date: '.$invoice->issued_at?->format('M d, Y'))

@section('content')
    @if ($invoice->status === 'cancelled')
        <p><span class="stamp">CANCELLED</span> <span class="muted">{{ $invoice->cancelled_at?->format('M d, Y') }} — {{ $invoice->cancellation_reason }}</span></p>
    @endif

    <table class="grid">
        <tr>
            <td>
                <div class="label">Sold to</div>
                <div class="box">
                    <strong>{{ $invoice->customer['name'] ?? '' }}</strong><br>
                    @if (! empty($invoice->customer['contact_person'])){{ $invoice->customer['contact_person'] }}<br>@endif
                    @if (! empty($invoice->customer['phone'])){{ $invoice->customer['phone'] }}<br>@endif
                    @if (! empty($invoice->customer['email'])){{ $invoice->customer['email'] }}<br>@endif
                    @if (! empty($invoice->customer['address']))<span class="muted">{{ $invoice->customer['address'] }}</span>@endif
                </div>
            </td>
            <td>
                <div class="label">Reference</div>
                <div class="box">
                    Sales Order: <span class="mono">{{ $sale->order_number }}</span><br>
                    @if ($invoice->replaces)Replaces invoice: <span class="mono">{{ $invoice->replaces->invoice_number }}</span><br>@endif
                    Invoice date: {{ $invoice->issued_at?->format('M d, Y') }}
                </div>
            </td>
        </tr>
    </table>

    @include('documents.partials.lines', [
        'lines' => collect($invoice->items)->map(fn ($i) => ['sku' => $i['sku'] ?? '', 'name' => $i['product_name'] ?? '', 'quantity' => $i['quantity'] ?? 0, 'unit_price' => $i['unit_price'] ?? 0, 'discount' => $i['discount'] ?? 0, 'subtotal' => $i['subtotal'] ?? 0])->all(),
        'totals' => $invoice->only(['subtotal', 'discount', 'tax_rate', 'tax_amount', 'grand_total']),
    ])
@endsection

@section('legal')
    @if (! $company['bir_registered'])
        System-generated document. This is not a BIR-registered invoice and is not valid for claiming input tax.
    @endif
    Payments received are acknowledged separately with acknowledgment receipts.
@endsection
