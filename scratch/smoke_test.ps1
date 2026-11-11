$urls = @(
    "http://localhost:8000/",
    "http://localhost:8000/login",
    "http://localhost:8000/products",
    "http://localhost:8000/quotation",
    "http://localhost:8000/mr-pride/users",
    "http://localhost:8000/mr-pride/dashboard",
    "http://localhost:8000/mr-pride/inventory",
    "http://localhost:8000/mr-pride/quotations",
    "http://localhost:8000/mr-pride/sales",
    "http://localhost:8000/mr-pride/suppliers",
    "http://localhost:8000/mr-pride/deliveries",
    "http://localhost:8000/mr-pride/procurement",
    "http://localhost:8000/mr-pride/forecast",
    "http://localhost:8000/mr-pride/reports",
    "http://localhost:8000/mr-pride/recycle-bin"
)

foreach ($url in $urls) {
    try {
        $r = Invoke-WebRequest -Uri $url -UseBasicParsing -MaximumRedirection 5 -ErrorAction Stop -TimeoutSec 10
        Write-Host "  OK $($r.StatusCode) $url"
    } catch {
        $status = $_.Exception.Response.StatusCode.value__
        if ($status) {
            Write-Host "  !! $status $url"
        } else {
            Write-Host "  ERR $url - $($_.Exception.Message)"
        }
    }
}
