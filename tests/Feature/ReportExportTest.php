<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reports page toolbar export: the selected view's tables (filters already applied by the page)
 * downloaded as Excel, PDF, CSV, JPG or PNG.
 */
function rptActor(Team $team, string $role = 'staff_reports'): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

/** @param  list<list<mixed>>  $rows */
function rptPayload(string $format, array $rows = [['BRK-1', 'Circuit Breaker 60A', 12, 1520.5]]): array
{
    return [
        'format' => $format,
        'title' => 'Inventory Status',
        'scope' => 'Period: All-Time Records · Status: Low Stock',
        'sections' => [
            ['title' => 'Status Distribution', 'columns' => [['label' => 'Status', 'type' => 'text'], ['label' => 'Products', 'type' => 'number']], 'rows' => [['Low Stock', 1]]],
            ['title' => 'Inventory Status Manifest', 'columns' => [
                ['label' => 'SKU', 'type' => 'text'], ['label' => 'Product', 'type' => 'text'],
                ['label' => 'Stock', 'type' => 'number'], ['label' => 'Total Valuation (PHP)', 'type' => 'money'],
            ], 'rows' => $rows],
        ],
    ];
}

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'rpt-team', 'name' => 'Reports Team']);
    [$this->user, $this->cookies] = rptActor($this->team);
    $this->url = "/{$this->team->slug}/reports/export";
});

it('exports the selected report view in every format', function (string $format, string $type, string $magic) {
    $res = $this->actingAs($this->user)->withCookies($this->cookies)->withCredentials()->postJson($this->url, rptPayload($format))->assertOk();

    expect($res->headers->get('Content-Type'))->toContain($type)
        ->and($res->headers->get('Content-Disposition'))->toContain('report_inventory_status_')->toContain(".{$format}")
        ->and($res->getContent() === false ? $res->streamedContent() : $res->getContent())->toStartWith($magic);
})->with([
    'excel' => ['xlsx', 'spreadsheetml', "PK\x03\x04"],
    'pdf' => ['pdf', 'application/pdf', '%PDF-'],
    'csv' => ['csv', 'text/csv', "\xEF\xBB\xBF"],
    'jpg' => ['jpg', 'image/jpeg', "\xFF\xD8\xFF"],
    'png' => ['png', 'image/png', "\x89PNG"],
]);

it('writes every section with its rows to CSV', function () {
    $csv = $this->actingAs($this->user)->withCookies($this->cookies)->withCredentials()->postJson($this->url, rptPayload('csv'))->assertOk()->streamedContent();

    expect($csv)->toContain('Status Distribution')->toContain('Inventory Status Manifest')
        ->toContain('SKU,Product,Stock,"Total Valuation (PHP)"')
        ->toContain('BRK-1,"Circuit Breaker 60A",12,1520.50');
});

it('gives each section its own Excel sheet, with numbers as numbers and text as text', function () {
    $payload = rptPayload('xlsx', [['=HYPERLINK("http://evil.test","x")', 'Wire', 300, 99.5]]);
    $content = $this->actingAs($this->user)->withCookies($this->cookies)->withCredentials()->postJson($this->url, $payload)->assertOk()->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'rpt');
    file_put_contents($path, $content);
    $book = IOFactory::load($path);
    $sheet = $book->getSheetByName('Inventory Status Manifest');

    expect($book->getSheetNames())->toBe(['Status Distribution', 'Inventory Status Manifest'])
        ->and($sheet->getCell('A5')->getDataType())->toBe('s')
        ->and($sheet->getCell('A5')->getValue())->toStartWith('=HYPERLINK')
        ->and($sheet->getCell('C5')->getValue())->toBe(300)
        ->and($sheet->getCell('D5')->getValue())->toBe(99.5)
        ->and($sheet->getCell('A2')->getValue())->toContain('Status: Low Stock');
});

it('splits long image exports into a zip of images', function () {
    $rows = array_map(fn ($i) => ["SKU-{$i}", "Product {$i}", $i, $i * 10], range(1, 80));
    $res = $this->actingAs($this->user)->withCookies($this->cookies)->withCredentials()->postJson($this->url, rptPayload('png', $rows))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'rpt');
    file_put_contents($path, $res->getContent());
    $zip = new ZipArchive;
    $zip->open($path);

    expect($res->headers->get('Content-Type'))->toBe('application/zip')->and($zip->numFiles)->toBe(2);
});

it('refuses unknown formats, malformed rows and documents that are too long', function () {
    $as = fn () => $this->actingAs($this->user)->withCookies($this->cookies)->withCredentials();

    $as()->postJson($this->url, rptPayload('exe'))->assertUnprocessable()->assertJsonValidationErrors('format');
    $as()->postJson($this->url, rptPayload('csv', [['only', 'three', 'cells']]))->assertUnprocessable()->assertJsonValidationErrors('sections');
    $as()->postJson($this->url, rptPayload('csv', [['a', ['nested'], 1, 2]]))->assertUnprocessable()->assertJsonValidationErrors('sections');

    $long = array_fill(0, 2001, ['SKU', 'Item', 1, 1]);
    $as()->postJson($this->url, rptPayload('pdf', $long))->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'more than the 2,000'));
    $as()->postJson($this->url, rptPayload('csv', $long))->assertOk();
});

it('is limited to people who can see reports', function () {
    [$sales, $salesCookies] = rptActor($this->team, 'staff_sales');

    $this->actingAs($sales)->withCookies($salesCookies)->withCredentials()->postJson($this->url, rptPayload('csv'))->assertForbidden();
});
