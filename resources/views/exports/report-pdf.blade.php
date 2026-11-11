<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $meta['title'] }}</title>
<style>
    @page { margin: 28px 28px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #111827; }
    .bar { height: 4px; background: #F8B803; margin-bottom: 10px; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 14px 0 6px; }
    .meta { color: #6b7280; font-size: 8px; margin-bottom: 6px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { background: #4b5320; color: #fff; text-align: left; padding: 5px 4px; font-size: 7.5px; }
    td { padding: 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    tr:nth-child(even) td { background: #f9fafb; }
    .r { text-align: right; }
    .muted { color: #6b7280; }
</style>
</head>
<body>
    <div class="bar"></div>
    <h1>{{ $meta['title'] }}</h1>
    <div class="meta">{{ $meta['team'] }} &middot; {{ $meta['scope'] }} &middot; Generated {{ $meta['generated'] }}</div>

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        {{-- Small tables instead of one long one: dompdf needs far less memory and time. --}}
        @forelse (array_chunk($section['rows'], 26) as $chunk)
            <table @if (count($section['columns']) <= 3) style="width: 50%" @endif>
                <thead>
                    <tr>
                        @foreach ($section['columns'] as $column)
                            <th class="{{ $column['type'] !== 'text' ? 'r' : '' }}">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chunk as $row)
                        <tr>
                            @foreach ($row as $i => $value)
                                <td class="{{ $section['columns'][$i]['type'] !== 'text' ? 'r' : '' }} {{ $value === null ? 'muted' : '' }}">{{ Str::limit($shown($value, $section['columns'][$i]['type']), 90) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @empty
            <p class="muted">No records match the current filters.</p>
        @endforelse
    @endforeach
</body>
</html>
