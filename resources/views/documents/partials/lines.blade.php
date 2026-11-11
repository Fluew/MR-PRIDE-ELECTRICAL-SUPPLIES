{{-- $lines: list of ['sku', 'name', 'quantity', 'unit_price', 'discount', 'subtotal'] (customer prices only) --}}
<table class="lines">
    <thead>
        <tr>
            <th style="width:4%" class="r">#</th>
            <th style="width:14%">SKU</th>
            <th>Description</th>
            <th style="width:8%" class="r">Qty</th>
            <th style="width:13%" class="r">Unit Price</th>
            <th style="width:11%" class="r">Discount</th>
            <th style="width:14%" class="r">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $i => $line)
            <tr>
                <td class="r muted">{{ $i + 1 }}</td>
                <td class="mono">{{ $line['sku'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="r">{{ number_format((float) $line['quantity']) }}</td>
                <td class="r">${{ number_format((float) $line['unit_price'], 2) }}</td>
                <td class="r">{{ (float) ($line['discount'] ?? 0) > 0 ? '$'.number_format((float) $line['discount'], 2) : '—' }}</td>
                <td class="r">${{ number_format((float) $line['subtotal'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="muted">Subtotal</td><td class="r">${{ number_format((float) $totals['subtotal'], 2) }}</td></tr>
    @if ((float) $totals['discount'] > 0)
        <tr><td class="muted">Discount</td><td class="r">-${{ number_format((float) $totals['discount'], 2) }}</td></tr>
    @endif
    <tr><td class="muted">Tax{{ isset($totals['tax_rate']) && (float) $totals['tax_rate'] > 0 ? ' ('.rtrim(rtrim(number_format((float) $totals['tax_rate'], 2), '0'), '.').'% VAT)' : '' }}</td><td class="r">${{ number_format((float) $totals['tax_amount'], 2) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="r">${{ number_format((float) $totals['grand_total'], 2) }}</td></tr>
</table>
