@extends('documents.layout')

@section('doc-title', 'SALES ORDER')
@section('doc-number', $sale->order_number)
@section('doc-date', 'Date: '.$sale->sale_date?->format('M d, Y'))

@section('content')
    <table class="grid">
        <tr>
            <td>
                <div class="label">Customer</div>
                <div class="box">
                    <strong>{{ $sale->client_name }}</strong><br>
                    @if ($sale->contact_person)Attn: {{ $sale->contact_person }}<br>@endif
                    @if ($sale->phone){{ $sale->phone }}<br>@endif
                    @if ($sale->email){{ $sale->email }}<br>@endif
                </div>
            </td>
            <td>
                <div class="label">{{ $sale->isPickup() ? 'Pickup' : 'Deliver to' }}</div>
                <div class="box">
                    {{ $sale->isPickup() ? 'Customer pickup at our warehouse' : ($sale->address ?: 'Address to be confirmed') }}<br>
                    @if ($sale->quotation)Quotation: <span class="mono">{{ $sale->quotation->quotation_number }}</span><br>@endif
                    Status: {{ $sale->status?->label() }} · Payment: {{ $sale->payment_status?->label() }}
                </div>
            </td>
        </tr>
    </table>

    @include('documents.partials.lines', [
        'lines' => $sale->items->map(fn ($i) => ['sku' => $i->sku, 'name' => $i->product_name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'discount' => $i->discount, 'subtotal' => $i->subtotal])->all(),
        'totals' => $sale->only(['subtotal', 'discount', 'tax_amount', 'grand_total']) + ['tax_rate' => $sale->quotation?->tax_rate],
    ])

    <table class="totals">
        <tr><td class="muted">Verified payments</td><td class="r">${{ number_format((float) $sale->amount_paid, 2) }}</td></tr>
        <tr><td class="muted">Balance</td><td class="r"><strong>${{ number_format((float) $sale->outstanding_balance, 2) }}</strong></td></tr>
    </table>

    <div class="note"><strong>Payment terms</strong>
@switch($sale->payment_terms)
@case('down_payment')Down payment of ${{ number_format((float) $sale->required_down_payment, 2) }} before the order is confirmed; the balance as agreed.@break
@case('credit')Credit terms: {{ $sale->credit_days }} days{{ $sale->credit_due_date ? ' (due '.$sale->credit_due_date->format('M d, Y').')' : '' }}.@break
@default Full payment before the order is confirmed.
@endswitch
Accepted payment methods: Bank Transfer, GCash. The order is confirmed only after we have verified the payment.</div>

    @if ($sale->quotation?->terms_and_conditions)
        <div class="note"><strong>Terms and conditions</strong>
{{ $sale->quotation->terms_and_conditions }}</div>
    @endif
@endsection

@section('legal')
    A Sales Order is not an invoice or a receipt.
@endsection
