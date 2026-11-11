<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Inventory List</title>
<style>
    @page { margin: 28px 28px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #111827; }
    .bar { height: 4px; background: #F8B803; margin-bottom: 10px; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .meta { color: #6b7280; font-size: 8px; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { background: #4b5320; color: #fff; text-align: left; padding: 5px 4px; font-size: 7.5px; }
    td { padding: 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    tr:nth-child(even) td { background: #f9fafb; }
    td { white-space: nowrap; overflow: hidden; }
    .r { text-align: right; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5px; }
    .muted { color: #6b7280; }
    .b { font-weight: bold; }
    .s-out { color: #e11d48; font-weight: bold; }
    .s-low { color: #d97706; font-weight: bold; }
    .s-in { color: #059669; font-weight: bold; }
</style>
</head>
<body>
    <div class="bar"></div>
    <h1>Inventory List</h1>
    <div class="meta">{{ $meta['team'] }} &middot; {{ $meta['filters'] }} &middot; Generated {{ $meta['generated'] }} &middot; {{ number_format($meta['total']) }} products</div>

    {{-- One small table per page: dompdf needs far less memory and time than for one long table. --}}
    @forelse (array_chunk($rows, 28, true) as $chunk)
        <table @if (! $loop->last) style="page-break-after: always" @endif>
            <thead>
                <tr>
                    <th class="r" style="width: 3%">#</th><th style="width: 10%">SKU</th><th style="width: 24%">Product</th>
                    <th style="width: 9%">Category</th><th style="width: 8%">Brand</th><th style="width: 4%">Unit</th>
                    <th class="r" style="width: 8%">Cost</th><th class="r" style="width: 8%">Price</th><th class="r" style="width: 5%">Stock</th>
                    <th class="r" style="width: 5%">Reorder</th><th style="width: 7%">Status</th><th style="width: 9%">Supplier</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($chunk as $i => $r)
                    <tr>
                        <td class="r muted">{{ $i + 1 }}</td>
                        <td class="mono">{{ Str::limit($r[0], 24) }}</td>
                        <td>{{ Str::limit($r[1], 60) }}</td>
                        <td>{{ Str::limit($r[2], 20) }}</td>
                        <td>{{ Str::limit($r[3], 18) }}</td>
                        <td>{{ Str::limit($r[4], 8) }}</td>
                        <td class="r">&#8369;{{ number_format($r[5], 2) }}</td>
                        <td class="r">&#8369;{{ number_format($r[6], 2) }}</td>
                        <td class="r b">{{ number_format($r[7]) }}</td>
                        <td class="r">{{ number_format($r[8]) }}</td>
                        <td class="{{ ["Out of Stock" => "s-out", "Low Stock" => "s-low"][$r[11]] ?? "s-in" }}">{{ $r[11] }}</td>
                        <td>{{ Str::limit($r[10], 22) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="muted">No products match the current filters.</p>
    @endforelse
</body>
</html>
