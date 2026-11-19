@extends('documents.layout')

@section('doc-title', 'REQUEST FOR QUOTATION')
@section('doc-number', $event->reference)
@section('doc-date', 'Issued: '.($event->rfq_issued_at ?? now())->format('M d, Y'))

@section('content')
    <table class="grid">
        <tr>
            <td>
                <div class="label">Invited suppliers</div>
                <div class="box">
                    @foreach ($event->invitations as $invitation)
                        {{ $invitation->supplier->name }}<br>
                    @endforeach
                </div>
            </td>
            <td>
                <div class="label">Submit your quotation by</div>
                <div class="box">
                    <strong>{{ $event->submission_deadline->format('M d, Y g:i A') }}</strong><br>
                    Send it to {{ $company['email'] ?: 'the contact below' }}@if ($company['phone']) or call {{ $company['phone'] }}@endif.<br>
                    Quotations received after this time cannot be accepted.
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th class="r">Quantity requested</th>
                <th>Unit</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $item['description'] }}</td>
                <td class="mono">{{ $item['sku'] ?: '—' }}</td>
                <td class="r mono">{{ number_format($item['quantity']) }}</td>
                <td>{{ $item['unit'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        Please include in your quotation:
        1. Unit price (in US dollars) and the quantity you can supply.
        2. Delivery lead time in days after the order is placed.
        3. Quotation date and how long the price is valid.
        4. Your quotation reference number, if any.
        @if ($event->rfq_notes)

        Notes from MR-PRIDE: {{ $event->rfq_notes }}
        @endif
    </div>
@endsection

@section('legal', 'This is a request for quotation, not a purchase order. No order is placed until a purchase order is issued.')
