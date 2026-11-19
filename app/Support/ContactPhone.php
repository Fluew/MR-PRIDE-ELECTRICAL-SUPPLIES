<?php

namespace App\Support;

/**
 * Contact numbers on the public quotation request. The country code is chosen from a fixed list
 * (never typed) and the number itself is digits only, validated for that country. The server applies
 * these rules; the form (resources/js/lib/validation.ts) mirrors them from the same lists.
 *
 * Philippines (+63):
 *  - mobile: 10 digits starting with 9 (9171234567);
 *  - landline / fax: area code + subscriber number, 9 digits: Metro Manila "2" + 8 digits (281234567) or a
 *    2-digit provincial area code + 7 digits.
 * The number is entered without the leading 0 (the country code replaces it).
 */
class ContactPhone
{
    public const DEFAULT_COUNTRY = '+63';

    /** Area codes (no leading 0) of the Philippine landline numbering plan: Metro Manila, then provinces. */
    public const PH_AREA_CODES = [
        '2',
        '32', '33', '34', '35', '36', '38',
        '42', '43', '44', '45', '46', '47', '48', '49',
        '52', '53', '54', '55', '56',
        '62', '63', '64', '65', '68',
        '72', '74', '75', '77', '78',
        '82', '83', '84', '85', '86', '87', '88',
    ];

    /** @var array<string, array{iso: string, name: string, min: int, max: int}> digits allowed after the country code */
    public const COUNTRIES = [
        '+63' => ['iso' => 'PH', 'name' => 'Philippines', 'min' => 9, 'max' => 10],
        '+1' => ['iso' => 'US', 'name' => 'United States / Canada', 'min' => 10, 'max' => 10],
        '+44' => ['iso' => 'GB', 'name' => 'United Kingdom', 'min' => 10, 'max' => 10],
        '+61' => ['iso' => 'AU', 'name' => 'Australia', 'min' => 9, 'max' => 9],
        '+65' => ['iso' => 'SG', 'name' => 'Singapore', 'min' => 8, 'max' => 8],
        '+60' => ['iso' => 'MY', 'name' => 'Malaysia', 'min' => 8, 'max' => 10],
        '+62' => ['iso' => 'ID', 'name' => 'Indonesia', 'min' => 8, 'max' => 12],
        '+66' => ['iso' => 'TH', 'name' => 'Thailand', 'min' => 8, 'max' => 9],
        '+84' => ['iso' => 'VN', 'name' => 'Vietnam', 'min' => 9, 'max' => 10],
        '+81' => ['iso' => 'JP', 'name' => 'Japan', 'min' => 9, 'max' => 10],
        '+82' => ['iso' => 'KR', 'name' => 'South Korea', 'min' => 8, 'max' => 10],
        '+86' => ['iso' => 'CN', 'name' => 'China', 'min' => 11, 'max' => 11],
        '+852' => ['iso' => 'HK', 'name' => 'Hong Kong', 'min' => 8, 'max' => 8],
        '+886' => ['iso' => 'TW', 'name' => 'Taiwan', 'min' => 8, 'max' => 9],
        '+91' => ['iso' => 'IN', 'name' => 'India', 'min' => 10, 'max' => 10],
        '+971' => ['iso' => 'AE', 'name' => 'United Arab Emirates', 'min' => 8, 'max' => 9],
        '+966' => ['iso' => 'SA', 'name' => 'Saudi Arabia', 'min' => 8, 'max' => 9],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    /** For the form's dropdown and its checks. */
    public static function rules(): array
    {
        return [
            'default' => self::DEFAULT_COUNTRY,
            'countries' => array_map(fn ($code, $c) => ['code' => $code, 'iso' => $c['iso'], 'name' => $c['name'], 'min' => $c['min'], 'max' => $c['max']], array_keys(self::COUNTRIES), self::COUNTRIES),
            'phAreaCodes' => self::PH_AREA_CODES,
        ];
    }

    /** The message to show when the number is not acceptable for that country, or null when it is. */
    public static function error(?string $country, ?string $digits): ?string
    {
        if ($country === null || ! isset(self::COUNTRIES[$country])) {
            return 'Please choose the country code from the list.';
        }
        $digits = (string) $digits;
        if ($digits === '') {
            return 'Enter your contact number.';
        }
        if (! preg_match('/^\d+$/', $digits)) {
            return 'The contact number can only contain digits (0-9).';
        }
        if ($digits[0] === '0') {
            return 'Enter the number without the leading 0: the country code replaces it.';
        }

        if ($country === '+63') {
            if ($digits[0] === '9') {
                return strlen($digits) === 10 ? null : 'A Philippine mobile number has 10 digits and starts with 9 (for example 9171234567).';
            }
            $area = self::phAreaCode($digits);
            if ($area === null) {
                return 'Enter a Philippine mobile number (10 digits starting with 9) or a landline with its area code (for example 281234567).';
            }

            return strlen($digits) === 9 ? null : 'A Philippine landline is the area code plus the number, 9 digits in all (for example 281234567 for Metro Manila).';
        }

        $c = self::COUNTRIES[$country];
        $length = strlen($digits);
        if ($length < $c['min'] || $length > $c['max']) {
            return $c['min'] === $c['max']
                ? "A {$c['name']} number has {$c['min']} digits."
                : "A {$c['name']} number has {$c['min']} to {$c['max']} digits.";
        }

        return null;
    }

    /** The Philippine area code a landline number starts with, or null if it starts with none. */
    public static function phAreaCode(string $digits): ?string
    {
        if (in_array(substr($digits, 0, 2), self::PH_AREA_CODES, true)) {
            return substr($digits, 0, 2);
        }

        return $digits !== '' && $digits[0] === '2' ? '2' : null;
    }

    /** "+63 9171234567": how the number is saved with the quotation. */
    public static function format(string $country, string $digits): string
    {
        return "{$country} {$digits}";
    }
}
