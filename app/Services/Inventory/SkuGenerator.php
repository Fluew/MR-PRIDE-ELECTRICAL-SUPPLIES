<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Category-based SKUs: <PREFIX>-<6 digits>, numbered per category and team (BRK-000001,
 * LGT-000001, WIR-000001, …). Numbers come from the per-team sequence table shared with document
 * numbers: the UPDATE locks the category's row until the surrounding transaction ends, so two
 * products created at the same time never get the same number. The sequence never goes below the
 * highest number already used by a product (archived ones included), and products.sku is unique
 * per team as the final guard. A SKU never changes after the product is created.
 */
class SkuGenerator
{
    /** Prefixes of the standard categories (matched on the lowercase category name). */
    private const PREFIXES = [
        'breaker' => 'BRK', 'breakers' => 'BRK', 'circuit breakers' => 'BRK',
        'lighting' => 'LGT', 'lights' => 'LGT', 'light' => 'LGT',
        'wiring' => 'WIR', 'wires' => 'WIR', 'wire' => 'WIR', 'wire & cable' => 'WIR', 'wires & cables' => 'WIR',
        'controls' => 'CTL', 'control' => 'CTL',
        'tools' => 'TLS', 'tool' => 'TLS',
        'general' => 'GEN',
    ];

    /** Sequence rows are stored as "SKU:<PREFIX>", apart from document-number prefixes. */
    private const SEQUENCE = 'SKU:';

    /**
     * The 3-letter prefix of a category: the standard one, otherwise the first letter and the
     * next consonants of the name ("Conduits" → CND, "Panel Boards" → PNL). No category → GEN.
     */
    public static function prefix(?string $category): string
    {
        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $category) ?? ''));
        if ($key === '') {
            return 'GEN';
        }
        if (isset(self::PREFIXES[$key])) {
            return self::PREFIXES[$key];
        }

        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key) ?? '');
        if ($letters === '') {
            return 'GEN';
        }
        $code = $letters[0].preg_replace('/[AEIOU]/', '', substr($letters, 1));
        if (strlen($code) < 3) {
            $code = $letters; // short names: keep the vowels
        }

        return str_pad(substr($code, 0, 3), 3, 'X');
    }

    /** Reserve and return the next SKU for the category. Call inside the transaction that saves the product. */
    public function next(int $teamId, ?string $category): string
    {
        $prefix = self::prefix($category);

        return DB::transaction(function () use ($teamId, $prefix) {
            do {
                $sku = $this->format($prefix, $this->bump($teamId, $prefix));
            } while (Product::withTrashed()->where('team_id', $teamId)->where('sku', $sku)->exists());

            return $sku;
        });
    }

    /** The SKU the next product of this category would get (shown in the form; reserved only on save). */
    public function peek(int $teamId, ?string $category): string
    {
        $prefix = self::prefix($category);
        $last = (int) DB::table('quotation_number_sequences')->where('team_id', $teamId)->where('prefix', self::SEQUENCE.$prefix)->value('last_value');

        return $this->format($prefix, max($last, $this->highestUsed($teamId, $prefix)) + 1);
    }

    private function bump(int $teamId, string $prefix): int
    {
        $floor = $this->highestUsed($teamId, $prefix);
        $row = fn () => DB::table('quotation_number_sequences')->where('team_id', $teamId)->where('prefix', self::SEQUENCE.$prefix);
        $update = fn () => $row()->update([
            'last_value' => DB::raw("(CASE WHEN last_value > {$floor} THEN last_value ELSE {$floor} END) + 1"),
            'updated_at' => now(),
        ]);

        if ($update() === 0) {
            $created = DB::table('quotation_number_sequences')->insertOrIgnore([
                'team_id' => $teamId, 'prefix' => self::SEQUENCE.$prefix, 'last_value' => $floor + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($created === 0) {
                $update(); // created by a concurrent request in the meantime
            }
        }

        return (int) $row()->value('last_value');
    }

    /** Highest number already used in this prefix (e.g. a SKU imported as BRK-000120). */
    private function highestUsed(int $teamId, string $prefix): int
    {
        $highest = 0;
        foreach (Product::withTrashed()->where('team_id', $teamId)->where('sku', 'like', $prefix.'-%')->pluck('sku') as $sku) {
            if (preg_match('/^'.$prefix.'-(\d{1,9})$/i', (string) $sku, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $highest;
    }

    private function format(string $prefix, int $number): string
    {
        return sprintf('%s-%06d', $prefix, $number);
    }
}
