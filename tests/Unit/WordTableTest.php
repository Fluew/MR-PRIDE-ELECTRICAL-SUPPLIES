<?php

use App\Services\MaterialList\WordTable;

/** Rebuilding tables from word positions (see ocrTsv in Pest.php for the layout helper). */
$isHeader = fn (array $cells) => in_array('SKU', $cells, true);

it('splits columns at the gaps the rows leave, not halfway between headers', function () use ($isHeader) {
    // Long names run far past the middle between "Name" and "Category"; numbers are right-aligned.
    $table = WordTable::build([ocrTsv([
        'SKU     Name                     Category     Price    Stock   Status',
        'A-1     Molded Case Breaker 3P   Breakers  1,450.00        5   Low Stock',
        'A-2     Tape                     Tools        35.00      240   In Stock',
    ])], $isHeader, true);

    expect($table['aligned'])->toBeTrue()
        ->and($table['rows'])->toBe([
            ['SKU', 'Name', 'Category', 'Price', 'Stock', 'Status'],
            ['A-1', 'Molded Case Breaker 3P', 'Breakers', '1,450.00', '5', 'Low Stock'],
            ['A-2', 'Tape', 'Tools', '35.00', '240', 'In Stock'],
        ]);
});

it('keeps multi-word headers together and joins a split header over short values', function () use ($isHeader) {
    $table = WordTable::build([ocrTsv([
        'SKU    Product Name   Unit Price',
        'B-1    Wire           12.00',
        'B-2    Fuse           3.50',
    ])], $isHeader, true);

    expect($table['rows'][0])->toBe(['SKU', 'Product Name', 'Unit Price'])
        ->and($table['rows'][1])->toBe(['B-1', 'Wire', '12.00']);
});

it('keeps titles above the table and skips footers and page numbers below it', function () use ($isHeader) {
    $table = WordTable::build([ocrTsv([
        'STOCK LIST OCTOBER',
        'SKU     Name          Stock',
        'C-1     Cable Tie        40',
        'Prepared by the warehouse team for audit',
        '                    Page 1 of 2',
    ])], $isHeader, true);

    expect($table['rows'])->toBe([
        ['STOCK LIST OCTOBER'],
        ['SKU', 'Name', 'Stock'],
        ['C-1', 'Cable Tie', '40'],
    ]);
});

it('joins a cell that wrapped onto the next line', function () use ($isHeader) {
    $tsv = ocrTsv([
        'SKU     Name                Stock',
        'D-1     Industrial LED         7',
        '        High Bay 200W',
    ]);
    // Put the wrapped line right under the row, as wrapped text is.
    $tsv = preg_replace_callback('/^(5\t1\t1\t1\t3\t\d+\t\d+\t)80\t/m', fn ($m) => $m[1]."64\t", $tsv);

    expect(WordTable::build([$tsv], $isHeader, true)['rows'][1])->toBe(['D-1', 'Industrial LED High Bay 200W', '7']);
});

it('reports no table when no line is a header', function () use ($isHeader) {
    $table = WordTable::build([ocrTsv(['hello world', 'nothing here'])], $isHeader, true);

    expect($table['aligned'])->toBeFalse()->and($table['rows'])->toBe([['hello world'], ['nothing here']]);
});
