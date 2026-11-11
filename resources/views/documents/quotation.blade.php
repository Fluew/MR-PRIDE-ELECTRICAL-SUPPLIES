{{--
    Official MR-PRIDE sales quotation (client-facing). Used for the PDF download, the PDF sent to
    the client, the PNG/JPG export and printing. Data: App\Services\Documents\QuotationDocument.
    The terms and the banking details are the official wording: do not edit them here.
--}}
@php
    $doc ??= \App\Services\Documents\QuotationDocument::data($quotation);
    $money = fn ($amount) => \App\Services\Documents\QuotationDocument::money((float) $amount, $doc['currency']);
    $logoFile = resource_path('images/documents/mr-pride-logo.png');
    $logo = is_file($logoFile) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoFile)) : null;
    $bank = $company['bank'];
    $vatLabel = 'VAT'.($doc['tax_rate'] > 0 ? ' ('.rtrim(rtrim(number_format($doc['tax_rate'], 2), '0'), '.').'%)' : '');
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 34px 40px 54px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #111827; line-height: 1.35; }
    .header { text-align: center; padding-bottom: 8px; border-bottom: 2px solid #4b5320; }
    .header img { height: 58px; }
    .company { font-size: 15px; font-weight: bold; color: #4b5320; margin-top: 3px; letter-spacing: .3px; }
    .header .line { color: #374151; font-size: 9px; }
    .accent { height: 3px; background: #F8B803; margin-top: 2px; }
    .title { text-align: center; font-size: 14px; font-weight: bold; letter-spacing: 4px; margin: 9px 0 8px; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.info td.col { vertical-align: top; width: 50%; }
    table.info td.col-left { padding-right: 18px; }
    .label { font-weight: bold; font-size: 9px; color: #4b5320; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #d1d5db; padding-bottom: 2px; margin-bottom: 4px; }
    table.kv { border-collapse: collapse; width: 100%; }
    table.kv td { padding: 1.5px 0; vertical-align: top; }
    table.kv td.k { width: 96px; color: #4b5563; }
    .intro { margin: 8px 0 5px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { background: #4b5320; color: #ffffff; font-size: 8.5px; padding: 6px 5px; border: 1px solid #4b5320; letter-spacing: .3px; }
    table.items td { padding: 3.5px 5px; border: 1px solid #d1d5db; vertical-align: top; }
    table.items td.desc { word-wrap: break-word; }
    table.items tr { page-break-inside: avoid; }
    .c { text-align: center; }
    .r { text-align: right; white-space: nowrap; }
    table.summary { margin: 8px 0 0 auto; border-collapse: collapse; width: 48%; page-break-inside: avoid; }
    table.summary td { padding: 4px 6px; border: 1px solid #d1d5db; }
    table.summary td.k { font-weight: bold; background: #f9fafb; }
    table.summary tr.total td { font-size: 11px; font-weight: bold; background: #F8B803; border-color: #d9a100; }
    .terms { margin-top: 10px; page-break-inside: avoid; }
    .terms ol { margin: 4px 0 0; padding-left: 16px; }
    .terms li { margin-bottom: 2px; }
    table.bank { border-collapse: collapse; margin-top: 2px; }
    table.bank td { padding: 1px 0; vertical-align: top; }
    table.bank td.k { width: 104px; }
    table.sign { width: 100%; margin-top: 20px; border-collapse: collapse; page-break-inside: avoid; }
    table.sign td { width: 50%; vertical-align: top; }
    table.sign td.col-left { padding-right: 36px; }
    .sign-space { border-bottom: 1px solid #111827; height: 34px; }
    .sign-name { font-weight: bold; padding-top: 3px; height: 12px; }
    .muted { color: #6b7280; font-size: 8px; }
</style>
</head>
<body>
    {{-- 1. Company header --}}
    <div class="header">
        @if ($logo)<img src="{{ $logo }}" alt="MR-PRIDE">@endif
        <div class="company">{{ $company['name'] }}</div>
        <div class="line">{{ $company['address'] }}</div>
        <div class="line">Contact Number: {{ $company['phone'] }}</div>
        <div class="line">Email: {{ $company['email'] }}</div>
    </div>
    <div class="accent"></div>

    <div class="title">QUOTATION</div>

    {{-- 2. Quote to · 3. Quote details --}}
    <table class="info">
        <tr>
            <td class="col col-left">
                <div class="label">Quote To</div>
                <table class="kv">
                    <tr><td class="k">Name:</td><td>{{ $doc['client']['name'] }}</td></tr>
                    <tr><td class="k">Company Name:</td><td>{{ $doc['client']['company'] }}</td></tr>
                    <tr><td class="k">Address:</td><td>{{ $doc['client']['address'] }}</td></tr>
                    <tr><td class="k">Contact Number:</td><td>{{ $doc['client']['phone'] }}</td></tr>
                </table>
            </td>
            <td class="col">
                <div class="label">Quote Details</div>
                <table class="kv">
                    <tr><td class="k">Date:</td><td>{{ $doc['date'] }}</td></tr>
                    <tr><td class="k">Quotation No.:</td><td><strong>{{ $doc['number'] }}</strong></td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- 4. Introduction --}}
    <p class="intro">We are pleased to submit to you the quotation for the following items:</p>

    {{-- 5. Items (the column headings repeat on every page) --}}
    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:9%">ITEM NO</th>
                <th>DESCRIPTION</th>
                <th class="c" style="width:8%">QTY</th>
                <th class="c" style="width:9%">UNIT</th>
                <th class="r" style="width:16%">UNIT PRICE</th>
                <th class="r" style="width:17%">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($doc['rows'] as $row)
                <tr>
                    <td class="c">{{ $row['no'] }}</td>
                    <td class="desc">{{ $row['description'] }}</td>
                    <td class="c">{{ number_format($row['quantity']) }}</td>
                    <td class="c">{{ $row['unit'] }}</td>
                    <td class="r">{{ $money($row['unit_price']) }}</td>
                    <td class="r">{{ $money($row['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="c muted">No items.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- 6. Summary --}}
    <table class="summary">
        <tr><td class="k">Sub Total</td><td class="r">{{ $money($doc['sub_total']) }}</td></tr>
        <tr><td class="k">Less Discount</td><td class="r">{{ $doc['discount'] > 0 ? $money($doc['discount']) : '' }}</td></tr>
        @if ($doc['tax'] > 0)
            <tr><td class="k">{{ $vatLabel }}</td><td class="r">{{ $money($doc['tax']) }}</td></tr>
        @endif
        <tr class="total"><td>TOTAL AMOUNT</td><td class="r">{{ $money($doc['total']) }}</td></tr>
    </table>

    {{-- 7. Terms and conditions (official wording) --}}
    <div class="terms">
        <div class="label">Terms and Conditions</div>
        <ol>
            <li>100% ADVANCED PAYMENT (PROCESSING P.O UPON RECEIVED PAYMENT)</li>
            <li>THIS QUOTATION IS VALID {{ $doc['valid_days'] }} DAYS</li>
            <li>DELIVERY LEAD TIME ON STOCK SUBJECT TO PRIOR SALE</li>
            <li>PRICES QUOTED HEREIN ARE SUBJECT TO CHANGE WITHOUT PRIOR NOTICE</li>
            <li>CANCELLATION CHARGES: A CHARGE WILL BE MADE ON ALL CANCELLED ORDER, TOGETHER WITH THE CHARGE FOR ALL WORK CARRIED OUT TO THE DATE OF WRITTEN CANCELLATION WILL BE CHARGES 25% OF PURCHASE ORDER</li>
            <li>PAYMENT TO:
                <table class="bank">
                    <tr><td class="k">BANK NAME:</td><td>{{ $bank['name'] }}</td></tr>
                    <tr><td class="k">ACCOUNT NAME:</td><td>{{ $bank['account_name'] }}</td></tr>
                    <tr><td class="k">ACCOUNT NO.:</td><td>{{ $bank['account_no'] }}</td></tr>
                    <tr><td class="k">ADDRESS:</td><td>{{ $bank['address'] }}</td></tr>
                    <tr><td class="k">SWIFT CODE:</td><td>{{ $bank['swift'] }}</td></tr>
                </table>
            </li>
        </ol>
    </div>

    {{-- 8. Prepared by and conforme --}}
    <table class="sign">
        <tr>
            <td class="col-left">
                <div class="label">Prepared By:</div>
                <div class="sign-space"></div>
                <div class="sign-name">{{ $doc['prepared_by'] }}</div>
            </td>
            <td>
                <div class="label">Conforme:</div>
                <div class="sign-space"></div>
                <div class="muted" style="padding-top:3px;">Signature over printed name and date</div>
            </td>
        </tr>
    </table>
</body>
</html>
