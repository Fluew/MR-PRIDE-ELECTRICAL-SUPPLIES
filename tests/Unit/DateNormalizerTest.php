<?php

use App\Services\MaterialList\OcrEngine;
use App\Support\DateNormalizer;

it('normalizes the supported formats to YYYY-MM-DD', function (string $text, string $iso, string $format) {
    $r = DateNormalizer::parse($text);

    expect($r['status'])->toBe(DateNormalizer::OK)
        ->and($r['value'])->toBe($iso)
        ->and($r['format'])->toBe($format)
        ->and($r['original'])->toBe($text)
        ->and($r['by_preference'])->toBeFalse();
})->with([
    'YYYY-MM-DD' => ['2026-11-03', '2026-11-03', 'YYYY-MM-DD'],
    'YYYY/MM/DD' => ['2026/11/03', '2026-11-03', 'YYYY-MM-DD'],
    'ISO date-time' => ['2026-11-03 14:22:00', '2026-11-03', 'YYYY-MM-DD'],
    'MM-DD-YYYY (day above 12)' => ['12-25-2025', '2025-12-25', 'MM-DD-YYYY'],
    'DD-MM-YYYY (day above 12)' => ['25-12-2025', '2025-12-25', 'DD-MM-YYYY'],
    'MM/DD/YYYY' => ['11/30/2026', '2026-11-30', 'MM-DD-YYYY'],
    'DD.MM.YYYY' => ['30.11.2026', '2026-11-30', 'DD-MM-YYYY'],
    'same day and month' => ['04-04-2026', '2026-04-04', 'MM-DD-YYYY'],
    'spaced separators' => ['25 - 12 - 2025', '2025-12-25', 'DD-MM-YYYY'],
    'en dash from a PDF' => ["25\u{2013}12\u{2013}2025", '2025-12-25', 'DD-MM-YYYY'],
    'month name first' => ['Nov 3, 2026', '2026-11-03', 'MMM D YYYY'],
    'day first, full month' => ['3rd November 2026', '2026-11-03', 'D MMM YYYY'],
    'year, month name, day' => ['2026-Nov-03', '2026-11-03', 'YYYY-MMM-DD'],
    'leap day' => ['02-29-2028', '2028-02-29', 'MM-DD-YYYY'],
    'leap day, day first' => ['29-02-2024', '2024-02-29', 'DD-MM-YYYY'],
]);

it('flags ambiguous dates instead of guessing', function (string $text) {
    $r = DateNormalizer::parse($text);

    expect($r['status'])->toBe(DateNormalizer::AMBIGUOUS)
        ->and($r['value'])->toBeNull()
        ->and($r['original'])->toBe($text)
        ->and($r['problem'])->toBe("Date could not be interpreted reliably: {$text}. Please verify the intended date format.");
})->with(['05-06-2026', '11-03-2026', '03-11-2026', '01/02/2026']);

it('reads an ambiguous date with a format preference that applies to the document', function () {
    expect(DateNormalizer::parse('05-06-2026', DateNormalizer::MDY))->toMatchArray(['value' => '2026-05-06', 'format' => 'MM-DD-YYYY', 'by_preference' => true])
        ->and(DateNormalizer::parse('05-06-2026', DateNormalizer::DMY))->toMatchArray(['value' => '2026-06-05', 'format' => 'DD-MM-YYYY', 'by_preference' => true])
        // A part above 12 decides the order, whatever the preference.
        ->and(DateNormalizer::parse('25-12-2025', DateNormalizer::MDY))->toMatchArray(['value' => '2025-12-25', 'by_preference' => false])
        ->and(DateNormalizer::parse('12-25-2025', DateNormalizer::DMY))->toMatchArray(['value' => '2025-12-25', 'by_preference' => false]);
});

it('rejects invalid and incomplete dates without turning them into valid ones', function (string $text, string $problem) {
    $r = DateNormalizer::parse($text, DateNormalizer::MDY);

    expect($r['status'])->toBe(DateNormalizer::INVALID)
        ->and($r['value'])->toBeNull()
        ->and($r['original'])->toBe($text)
        ->and($r['problem'])->toContain($problem);
})->with([
    'day 31 in February' => ['31-02-2026', 'February 2026 has 28 days'],
    '29 February in a common year' => ['02-29-2026', 'February 2026 has 28 days'],
    '30 February, ISO' => ['2026-02-30', 'February 2026 has 28 days'],
    '31 April' => ['04-31-2026', 'April 2026 has 30 days'],
    'month 13 (both parts)' => ['13-13-2026', 'no valid month'],
    'month 13, ISO' => ['2026-13-01', 'no valid month'],
    'month 00' => ['00-11-2026', '00'],
    'day 00' => ['2026-11-00', 'day of 00'],
    'two-digit year' => ['05-06-26', 'four-digit year'],
    'no year' => ['11-03', 'incomplete'],
    'month and year only' => ['Nov 2026', 'incomplete'],
    'misread year' => ['11-30-7026', 'looks misread'],
    'not a date' => ['N/A', 'not a recognisable date'],
    'a price' => ['1,250.00', 'not a recognisable date'],
    'a number' => ['46329', 'not a recognisable date'],
]);

it('returns empty for blank values', function () {
    expect(DateNormalizer::parse('  ')['status'])->toBe(DateNormalizer::EMPTY)
        ->and(DateNormalizer::parse(null)['status'])->toBe(DateNormalizer::EMPTY);
});

it('corrects OCR digit look-alikes inside a date and reports it, but only in OCR text', function () {
    expect(DateNormalizer::parse('2O26-11-O3', null, true))->toMatchArray(['value' => '2026-11-03', 'corrected_from' => '2O26-11-O3'])
        ->and(DateNormalizer::parse('25-l2-2025', null, true))->toMatchArray(['value' => '2025-12-25', 'corrected_from' => '25-l2-2025'])
        ->and(DateNormalizer::parse('2O26-11-O3')['status'])->toBe(DateNormalizer::INVALID);
});

it('finds labelled dates in document rows and ignores unrelated numbers', function () {
    $rows = [
        ['ABC Electrical Trading', '', ''],
        ['Quotation No: 11-03-2026', '', ''],          // a reference number shaped like a date
        ['Delivery Date: 01-01-2027', '', ''],         // a different date field
        ['Quotation Date:', '25/12/2025', ''],         // label and value in separate cells
        ['Valid until 31 Jan 2026 (30 days)', '', ''],
        ['Description', 'Qty', 'Unit Price'],
        ['Breaker 60A', '12', '1,250.00'],
        ['Date', '', 'Remarks'],                       // a heading with no date next to it
    ];

    $quoted = DateNormalizer::findLabeled($rows, ['quotation date', 'date']);
    $valid = DateNormalizer::findLabeled($rows, ['valid until']);

    expect($quoted)->toMatchArray(['value' => '2025-12-25', 'original' => '25/12/2025', 'label' => 'Quotation Date', 'row' => 4])
        ->and($valid)->toMatchArray(['value' => '2026-01-31', 'original' => '31 Jan 2026'])
        ->and(DateNormalizer::findLabeled([['Ref 1234'], ['Total', '25-12-2025']], ['date']))->toBeNull();
});

it('reports an ambiguous or conflicting labelled date with its original text', function () {
    $ambiguous = DateNormalizer::findLabeled([['Date: 05-06-2026']], ['date']);
    $conflict = DateNormalizer::findLabeled([['Date: 2026-10-01'], ['Date: 2026-10-05']], ['date']);

    expect($ambiguous)->toMatchArray(['status' => DateNormalizer::AMBIGUOUS, 'value' => null, 'original' => '05-06-2026'])
        ->and($ambiguous['problem'])->toBe('Date could not be interpreted reliably: 05-06-2026. Please verify the intended date format.')
        ->and($conflict)->toMatchArray(['status' => DateNormalizer::AMBIGUOUS, 'value' => null, 'original' => '2026-10-01, 2026-10-05']);
});

it('measures the skew of a tilted page so OCR can straighten it', function (float $tilt) {
    // Rows of word-sized dark blocks, like lines of text, tilted by $tilt degrees.
    $img = imagecreatetruecolor(1600, 1000);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $black = imagecolorallocate($img, 0, 0, 0);
    for ($line = 0; $line < 12; $line++) {
        for ($word = 0; $word < 9; $word++) {
            imagefilledrectangle($img, 80 + $word * 160, 80 + $line * 70, 80 + $word * 160 + 120, 80 + $line * 70 + 22, $black);
        }
    }
    if ($tilt != 0.0) {
        $img = imagerotate($img, $tilt, imagecolorallocate($img, 255, 255, 255));
    }

    expect(abs((new OcrEngine)->skewAngle($img) + $tilt))->toBeLessThanOrEqual(0.2);
})->with([0.0, 2.0, -3.5, 4.5]);
