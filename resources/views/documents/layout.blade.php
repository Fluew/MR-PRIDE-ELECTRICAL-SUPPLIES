<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 32px 36px 44px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #111827; }
    .bar { height: 4px; background: #F8B803; margin-bottom: 12px; }
    .head { width: 100%; border-bottom: 2px solid #4b5320; padding-bottom: 10px; margin-bottom: 14px; }
    .head td { vertical-align: top; }
    .company { font-size: 15px; font-weight: bold; color: #4b5320; }
    .muted { color: #6b7280; }
    .doc-title { font-size: 14px; font-weight: bold; text-align: right; letter-spacing: 1px; color: #111827; }
    .doc-number { text-align: right; font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; margin-top: 2px; }
    .grid { width: 100%; margin-bottom: 12px; }
    .grid td { vertical-align: top; width: 50%; padding-right: 12px; }
    .label { color: #6b7280; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
    .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.lines th { background: #4b5320; color: #fff; text-align: left; padding: 5px; font-size: 8.5px; }
    table.lines td { padding: 5px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .r { text-align: right; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    table.totals { margin: 10px 0 0 auto; border-collapse: collapse; min-width: 220px; }
    table.totals td { padding: 3px 6px; }
    table.totals tr.grand td { border-top: 2px solid #111827; font-weight: bold; font-size: 11px; }
    .note { margin-top: 14px; padding: 8px; background: #f9fafb; border: 1px solid #e5e7eb; font-size: 8.5px; color: #374151; white-space: pre-line; }
    .legal { margin-top: 16px; font-size: 8px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    .stamp { display: inline-block; border: 2px solid #e11d48; color: #e11d48; font-weight: bold; padding: 2px 8px; letter-spacing: 1px; }
</style>
</head>
<body>
    <div class="bar"></div>
    <table class="head">
        <tr>
            <td>
                <div class="company">{{ strtoupper($company['name']) }}</div>
                @if ($company['tagline'])<div class="muted">{{ $company['tagline'] }}</div>@endif
                @if ($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
                <div class="muted">{{ collect([$company['phone'], $company['email']])->filter()->implode(' | ') }}</div>
                @if ($company['tin'])<div class="muted">TIN: {{ $company['tin'] }}</div>@endif
            </td>
            <td>
                <div class="doc-title">@yield('doc-title')</div>
                <div class="doc-number">@yield('doc-number')</div>
                <div class="muted" style="text-align:right;margin-top:2px;">@yield('doc-date')</div>
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="legal">
        All amounts are in US dollars (USD).
        @yield('legal')
    </div>
</body>
</html>
