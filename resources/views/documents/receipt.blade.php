@extends('documents.layout')

@section('doc-title', 'ACKNOWLEDGMENT RECEIPT')
@section('doc-number', $receipt->receipt_number)
@section('doc-date', 'Generated: '.$receipt->generated_at?->format('M d, Y h:i A'))

@section('content')
    <table class="grid">
        <tr>
            <td>
                <div class="label">Received from</div>
                <div class="box">
                    <strong>{{ $sale->client_name }}</strong><br>
                    @if ($sale->contact_person){{ $sale->contact_person }}<br>@endif
                    @if ($sale->email){{ $sale->email }}@endif
                </div>
            </td>
            <td>
                <div class="label">For</div>
                <div class="box">
                    Sales Order: <span class="mono">{{ $sale->order_number }}</span><br>
                    Payment method: {{ $receipt->method }}<br>
                    Transaction reference: <span class="mono">{{ $receipt->reference ?: '—' }}</span><br>
                    Payment date: {{ $receipt->payment_date?->format('M d, Y') }}
                </div>
            </td>
        </tr>
    </table>

    <table class="totals" style="margin:4px 0 0 0;width:60%;">
        <tr class="grand"><td>Amount received</td><td class="r">${{ number_format((float) $receipt->amount_received, 2) }}</td></tr>
        <tr><td class="muted">Sales Order total</td><td class="r">${{ number_format((float) $receipt->order_total, 2) }}</td></tr>
        <tr><td class="muted">Previously paid</td><td class="r">${{ number_format((float) $receipt->previously_paid, 2) }}</td></tr>
        <tr><td class="muted">Remaining balance</td><td class="r"><strong>${{ number_format((float) $receipt->balance_after, 2) }}</strong></td></tr>
    </table>
@endsection

@section('legal')
    This acknowledges a payment we received and verified. It is a system-generated document and
    @if (! $company['bir_registered']) is not a BIR official receipt or sales invoice. @else is not a sales invoice. @endif
    It covers only the amount received shown above.
@endsection
