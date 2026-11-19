<?php

namespace App\Services\Inventory\SupplierCatalog;

/**
 * Cleaning and matching keys for the Supplier Product Catalog.
 *
 * Display names are only trimmed and have repeated spaces collapsed: technical specifications
 * (ratings, poles, sizes, °, ², ×, fractions) are kept exactly as given. Matching keys are
 * lowercase with dash/multiplication-sign variants unified, so "0.5–6.0 mm²" and "0.5-6.0 mm²"
 * match, but "20A" never matches "32A".
 */
class SupplierCatalogNormalizer
{
    /** Brands recognised at the start of a product name (besides the `brands` table). */
    private const NAME_PREFIX_BRANDS = ['Schneider Electric', 'Siemens', 'Fluke', 'CEM', 'Kyoritsu', 'TITAN', 'INGCO'];

    /** Pricing units: source token → stored unit. */
    private const UNITS = [
        'pc' => 'pcs', 'pcs' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs',
        'set' => 'set', 'sets' => 'set',
        'pack' => 'pack', 'packs' => 'pack',
        'm' => 'm', 'meter' => 'm', 'meters' => 'm', 'metre' => 'm', 'metres' => 'm',
        'spool' => 'spool', 'spools' => 'spool',
    ];

    /** Trimmed text with single spaces; nothing else changes. */
    public static function clean(?string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /** Matching key of a product description (specifications kept). */
    public static function productKey(?string $name): string
    {
        $key = mb_strtolower(self::clean($name));
        $key = str_replace(['–', '—', '‒', '−', '‐'], '-', $key);
        $key = str_replace('×', 'x', $key);
        $key = preg_replace('/\s*([-\/,])\s*/u', '$1', $key) ?? $key;
        $key = preg_replace('/\s*,\s*/u', ', ', $key) ?? $key;

        return rtrim($key, ' .');
    }

    /**
     * Matching key of a supplier company: lowercase, "&" = "and", punctuation ignored, so
     * "J.Z. Electrical Supply, Inc." and "JZ Electrical Supply Inc" are the same supplier.
     */
    public static function supplierKey(?string $name): string
    {
        $key = mb_strtolower(self::clean($name));
        $key = str_replace(['–', '—', '‒', '−'], '-', $key);
        $key = str_replace('&', ' and ', $key);
        $key = preg_replace('/[.,()\'"]/u', '', $key) ?? $key;
        $key = preg_replace('/\s*-\s*/u', ' - ', $key) ?? $key;

        return trim(preg_replace('/\s+/u', ' ', $key) ?? $key);
    }

    /**
     * The pricing unit of a label such as "USD/m" or "USD/pc or set (verify UOM)".
     *
     * @return array{unit: string, confirmed: bool, currency: ?string}|null null when the unit is not recognised
     */
    public static function pricingUnit(?string $label): ?array
    {
        $label = self::clean($label);
        $currency = null;
        if (preg_match('/^([A-Za-z]{3})\s*\/\s*(.+)$/u', $label, $m)) {
            $currency = strtoupper($m[1]);
            $label = $m[2];
        }
        $confirmed = ! preg_match('/verify|\bor\b|\?/i', $label);
        $first = mb_strtolower(trim(preg_split('/\s+or\s+|\s*\(|\s*\//i', $label)[0] ?? ''));
        $unit = self::UNITS[$first] ?? null;

        return $unit === null ? null : ['unit' => $unit, 'confirmed' => $confirmed, 'currency' => $currency];
    }

    /**
     * The brand at the start of a product name ("Siemens ED63B050 …" → Siemens), or null. Only a
     * name that begins with a known brand gets one: a brand mentioned inside the description
     * (e.g. the breakers fitted in a panel board) is not the product's brand.
     *
     * @param  list<string>  $knownBrands  e.g. names from the `brands` table
     */
    public static function brand(string $name, array $knownBrands = []): ?string
    {
        $candidates = array_unique(array_merge(self::NAME_PREFIX_BRANDS, $knownBrands));
        usort($candidates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($candidates as $brand) {
            $brand = trim($brand);
            if ($brand !== '' && preg_match('/^'.preg_quote($brand, '/').'(?=\s)/iu', $name)) {
                return mb_substr($name, 0, mb_strlen($brand)); // the casing used in the name
            }
        }

        return null;
    }
}
