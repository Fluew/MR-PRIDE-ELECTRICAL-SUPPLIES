<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Support\Collection;

/**
 * Validation for one import module. analyze() fills each row's values, value sources, issues
 * and status from what was extracted plus what staff edited. Issues have a level:
 *  - error: the row cannot be imported until it is fixed or skipped;
 *  - warning: imported, but staff should check it (OCR corrections, incomplete product);
 *  - info: explains what will happen (generated SKU, calculated amount, ignored stock).
 */
abstract class RowRules
{
    /** @var list<string> fields holding numbers */
    protected const NUMERIC = [];

    /** @param  Collection<int, ImportRow>  $rows */
    abstract public function analyze(ImportBatch $batch, Collection $rows, bool $withMatching = false): void;

    /** Field labels for messages. */
    abstract public function label(string $field): string;

    /**
     * A field's current value: the staff edit when there is one, otherwise the document's value
     * (numbers parsed, OCR look-alikes corrected and reported).
     *
     * @return array{value: mixed, problem: ?string, corrected_from: ?string, source: string}
     */
    protected function field(ImportRow $row, string $field, bool $ocr): array
    {
        $sources = $row->sources ?? [];
        $numeric = in_array($field, static::NUMERIC, true);

        if (($sources[$field] ?? null) === 'edited') {
            $v = $row->values[$field] ?? null;
            if ($numeric) {
                $parsed = ValueParser::number($v === null ? null : (string) $v);

                return $parsed + ['source' => 'edited'];
            }

            return ['value' => ValueParser::text($v === null ? null : (string) $v, 1000), 'problem' => null, 'corrected_from' => null, 'source' => 'edited'];
        }

        $raw = $row->extracted[$field] ?? null;
        if ($numeric) {
            return ValueParser::number($raw, $ocr) + ['source' => 'document'];
        }

        return ['value' => ValueParser::text($raw, 1000), 'problem' => null, 'corrected_from' => null, 'source' => 'document'];
    }

    /** @param  list<array{level: string, field: ?string, message: string}>  $issues */
    protected function status(ImportRow $row, array $issues, ?string $forced = null): string
    {
        if ($row->status === ImportRow::IMPORTED) {
            return ImportRow::IMPORTED;
        }
        if ($row->skipped) {
            return ImportRow::SKIPPED;
        }
        if ($forced) {
            return $forced;
        }

        return collect($issues)->contains('level', 'error') ? ImportRow::NEEDS_REVIEW : ImportRow::READY;
    }

    /**
     * Compute extraction confidence ('high', 'medium', 'low') from actual extraction facts.
     *
     * @param  list<array{level: string, field: ?string, message: string}>  $issues
     * @param  array<string, ?string>  $sources
     */
    protected function confidence(bool $ocr, ImportRow $row, array $issues, array $sources, bool $hadOcrCorrection): string
    {
        if (($row->extracted['_no_header'] ?? false) || $hadOcrCorrection || collect($issues)->contains('level', 'error')) {
            return 'low';
        }
        if ($ocr || in_array('calculated', $sources, true) || collect($issues)->contains('level', 'warning')) {
            return 'medium';
        }

        return 'high';
    }

    protected static function issue(string $level, ?string $field, string $message): array
    {
        return compact('level', 'field', 'message');
    }
}
