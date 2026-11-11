@extends('documents.layout')

@section('doc-title', $release->type === 'pickup' ? 'RELEASE SLIP' : 'DELIVERY RECEIPT')
@section('doc-number', $release->release_number)
@section('doc-date', 'Sales Order: '.$sale->order_number)

@section('content')
    <table class="grid">
        <tr>
            <td>
                <div class="label">Customer</div>
                <div class="box">
                    <strong>{{ $sale->client_name }}</strong><br>
                    {{ $release->fulfillment?->recipient_name }}<br>
                    @if ($release->type !== 'pickup')<span class="muted">{{ $release->fulfillment?->delivery_address }}</span>@endif
                </div>
            </td>
            <td>
                <div class="label">Release</div>
                <div class="box">
                    @if ($release->dispatched_at){{ $release->type === 'pickup' ? 'Released' : 'Dispatched' }}: {{ $release->dispatched_at->format('M d, Y h:i A') }}<br>@endif
                    @if ($release->delivered_at)Received: {{ $release->delivered_at->format('M d, Y h:i A') }}<br>@endif
                    @if ($release->received_by_name)Received by: {{ $release->received_by_name }}@endif
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr><th>SKU</th><th>Description</th><th class="r">Released</th><th class="r">Received</th><th class="r">Returned</th></tr></thead>
        <tbody>
            @foreach ($release->items as $item)
                <tr>
                    <td class="mono">{{ $item->saleItem?->sku }}</td>
                    <td>{{ $item->saleItem?->product_name }}</td>
                    <td class="r">{{ $item->quantity }}</td>
                    <td class="r">{{ $item->delivered_quantity }}</td>
                    <td class="r">{{ $item->returned_quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="grid" style="margin-top:40px;">
        <tr>
            <td>________________________________<br><span class="muted">Released by</span></td>
            <td>________________________________<br><span class="muted">Received by (signature over printed name)</span></td>
        </tr>
    </table>
@endsection

@section('legal')
    Quantities only. This document is not an invoice or a receipt for payment.
@endsection
