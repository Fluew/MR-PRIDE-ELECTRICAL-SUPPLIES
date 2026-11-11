<?php

namespace App\Services\Import;

/**
 * Reads numbers the way suppliers and scanners write them: "₱1,250.00", "PHP 75", "1.250,50",
 * "(15.00)". For OCR text, look-alike letters inside a number are corrected (l5 → 15, 1O → 10),
 * and the correction is reported so staff can check it. Anything else that is not a number is
 * reported as unreadable, never guessed.
 */
class ValueParser
{
    /** @return array{value: ?float, problem: ?string, corrected_from: ?string} */
    public static function number(?string $text, bool $ocr = false): array
    {
        $none = ['value' => null, 'problem' => null, 'corrected_from' => null];
        if ($text === null || trim($text) === '') {
            return $none;
        }
        $original = trim($text);
        $s = preg_replace('/(₱|php|usd|us\$|\$|pesos?|\bp(?=\s*\d))/iu', '', $original) ?? $original;
        // Strip trailing rate suffixes like "/pc", "/roll", "per pc" after a number
        $s = preg_replace('/\s*(?:\/|per\s+)(?:pcs?|pieces?|units?|rolls?|box(?:es)?|sets?|meters?|m|ft|kg|packs?|pairs?|lots?|lengths?)\s*$/iu', '', $s) ?? $s;
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        $negative = (bool) preg_match('/^\(.*\)$/', $s);
        $s = trim($s, '()');
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        }

        $corrected = null;
        if ($ocr && preg_match('/\d/', $s) && preg_match('/^[\d.,OoIl|!SBZ]+$/u', $s) && preg_match('/[OoIl|!SBZ]/u', $s)) {
            $corrected = $original;
            $s = strtr($s, [
                'O' => '0',
                'o' => '0',
                'I' => '1',
                'l' => '1',
                '|' => '1',
                '!' => '1',
                'S' => '5',
                'B' => '8',
                'Z' => '2',
            ]);
        }

        if (str_contains($s, ',') && str_contains($s, '.')) {
            // The last separator is the decimal one: 1,250.50 or 1.250,50.
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif (str_contains($s, ',')) {
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }

        if ($s === '' || ! is_numeric($s)) {
            return ['value' => null, 'problem' => "\"{$original}\" is not a readable number", 'corrected_from' => null];
        }

        return ['value' => ($negative ? -1 : 1) * (float) $s, 'problem' => null, 'corrected_from' => $corrected];
    }

    private const UNIT_MAP = [
        'pc' => 'pcs', 'pcs' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs', 'pce' => 'pcs',
        'unit' => 'unit', 'units' => 'unit', 'ea' => 'ea', 'each' => 'ea',
        'roll' => 'roll', 'rolls' => 'roll', 'reel' => 'reel', 'reels' => 'reel', 'coil' => 'coil', 'coils' => 'coil', 'spool' => 'spool', 'spools' => 'spool',
        'm' => 'm', 'meter' => 'm', 'meters' => 'm', 'metre' => 'm', 'metres' => 'm', 'mtr' => 'm', 'mtrs' => 'm',
        'ft' => 'ft', 'feet' => 'ft',
        'box' => 'box', 'boxes' => 'box', 'bx' => 'box',
        'set' => 'set', 'sets' => 'set',
        'lot' => 'lot', 'lots' => 'lot',
        'pair' => 'pair', 'pairs' => 'pair',
        'kg' => 'kg', 'kgs' => 'kg', 'g' => 'g',
        'length' => 'length', 'lengths' => 'length', 'lgth' => 'length',
        'pack' => 'pack', 'packs' => 'pack', 'pkt' => 'pack',
        'bag' => 'bag', 'bags' => 'bag',
        'bundle' => 'bundle', 'bundles' => 'bundle',
    ];

    /** The system's units of measure, one name per unit ("pcs", "box", "roll", "m"…). */
    public static function units(): array
    {
        return array_values(array_unique(self::UNIT_MAP));
    }

    /** A unit written any common way ("PCS", "Boxes", "meters") as the system's name, or null if unknown. */
    public static function unit(?string $text): ?string
    {
        $key = strtolower(trim((string) $text, " \t\n\r\0\x0B."));

        return $key === '' ? null : (self::UNIT_MAP[$key] ?? null);
    }

    /**
     * Parse a quantity cell that may also include a unit suffix (e.g. "10 pcs", "25 rolls").
     *
     * @return array{value: ?float, unit: ?string, problem: ?string, corrected_from: ?string}
     */
    public static function quantityAndUnit(?string $text, bool $ocr = false): array
    {
        if ($text === null || trim($text) === '') {
            return ['value' => null, 'unit' => null, 'problem' => null, 'corrected_from' => null];
        }

        $trimmed = trim($text);
        if (preg_match('/^([+-]?[\d.,OoIl|!SBZ\s]+)\s*([a-zA-Z]{1,12})\.?$/u', $trimmed, $m)) {
            $unitKey = strtolower($m[2]);
            $unitCandidate = self::UNIT_MAP[$unitKey] ?? null;
            if ($unitCandidate !== null) {
                $num = self::number($m[1], $ocr);
                if ($num['value'] !== null) {
                    return [
                        'value' => $num['value'],
                        'unit' => $unitCandidate,
                        'problem' => null,
                        'corrected_from' => $num['corrected_from'],
                    ];
                }
            }
        }

        $num = self::number($trimmed, $ocr);

        return [
            'value' => $num['value'],
            'unit' => null,
            'problem' => $num['problem'],
            'corrected_from' => $num['corrected_from'],
        ];
    }

    /**
     * Parse a date from common spreadsheet/document formats or Excel serial integers into YYYY-MM-DD.
     *
     * @return array{value: ?string, problem: ?string}
     */
    public static function date(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return ['value' => null, 'problem' => null];
        }

        $raw = trim($text);

        // Excel serial date (between 1980-01-01 = 29221 and 2060-12-31 = 58805)
        if (preg_match('/^\d{5}(?:\.\d+)?$/', $raw)) {
            $serial = (float) $raw;
            if ($serial >= 29221 && $serial <= 58805) {
                $unix = (int) round(($serial - 25569) * 86400);

                return ['value' => gmdate('Y-m-d', $unix), 'problem' => null];
            }
        }

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'd M Y', 'd F Y', 'M d, Y', 'F d, Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!'.$fmt, $raw);
            if ($dt && $dt->format($fmt) === $raw) {
                return ['value' => $dt->format('Y-m-d'), 'problem' => null];
            }
        }

        $ts = strtotime($raw);
        if ($ts !== false && $ts > 0) {
            return ['value' => date('Y-m-d', $ts), 'problem' => null];
        }

        return ['value' => null, 'problem' => "\"{$raw}\" is not a valid date"];
    }

    public static function text(?string $text, int $max = 255): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
