<?php

namespace App\Services\MaterialList;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Suggests catalog products for extracted material lines. Separate from OCR/parsing.
 *
 * Only an exact product code (SKU) is treated as a sure match and pre-selected. Word-overlap
 * matches are just suggestions with a confidence level; the client must pick one themselves,
 * and the client's own description is always kept. Unmatched lines stay as custom items.
 */
class ProductMatcher
{
    private const STOP_WORDS = ['the', 'and', 'for', 'with', 'of', 'a', 'an', 'to', 'in', 'type', 'pcs', 'pc', 'piece', 'pieces', 'unit', 'units', 'brand'];

    private ?Collection $products = null;

    public function __construct(private ?int $teamId = null) {}

    /** @param  array<int, array>  $items */
    public function matchAll(array $items): array
    {
        return array_map(fn ($item) => $item + $this->match($item), $items);
    }

    /** @return array{match: ?array, suggestions: array<int, array>} */
    public function match(array $item): array
    {
        $products = $this->products();
        $haystack = mb_strtolower(implode(' ', array_filter([
            $item['item_code'] ?? null, $item['description'] ?? null, $item['specs'] ?? null,
        ])));

        // 1. Exact SKU, as its own token, in the item code / description: a sure match.
        foreach ($products as $p) {
            $sku = mb_strtolower((string) $p->sku);
            // Codes shorter than 4 characters ("A1") are too likely to appear by chance.
            if (mb_strlen($sku) >= 4 && (
                mb_strtolower(trim((string) ($item['item_code'] ?? ''))) === $sku
                || preg_match('/(?<![\p{L}\p{N}])'.preg_quote($sku, '/').'(?![\p{L}\p{N}])/u', $haystack)
            )) {
                $exact = $this->present($p, 100, 'high', 'Exact product code');

                return ['match' => $exact, 'suggestions' => [$exact]];
            }
        }

        // 2. Word overlap between the line and the product name/brand/category: suggestions only.
        $tokens = self::tokens($haystack.' '.mb_strtolower((string) ($item['brand'] ?? '')));
        if ($tokens === []) {
            return ['match' => null, 'suggestions' => []];
        }

        $scored = [];
        foreach ($products as $p) {
            $nameTokens = self::tokens(mb_strtolower($p->name.' '.$p->category));
            if ($nameTokens === []) {
                continue;
            }
            $shared = array_intersect($nameTokens, $tokens);
            $score = count($shared) / count($nameTokens);
            if ($p->brand && ($item['brand'] ?? null) && mb_strtolower($p->brand) === mb_strtolower(trim($item['brand']))) {
                $score += 0.15;
            }
            // A rating/size that differs (60a vs 30a, 3.5mm vs 2.0mm) means a different product.
            $specsA = preg_grep('/\d/', $nameTokens);
            $specsB = preg_grep('/\d/', $tokens);
            if ($specsA && $specsB && ! array_intersect($specsA, $specsB)) {
                $score -= 0.4;
            }
            if ($score >= 0.4 && count($shared) >= 1) {
                $scored[] = [$p, min(0.95, $score)];
            }
        }

        usort($scored, fn ($a, $b) => $b[1] <=> $a[1]);
        $suggestions = array_map(function ($pair) {
            [$p, $s] = $pair;
            $level = $s >= 0.8 ? 'high' : ($s >= 0.6 ? 'medium' : 'low');

            return $this->present($p, (int) round($s * 100), $level, 'Similar name');
        }, array_slice($scored, 0, 3));

        return ['match' => null, 'suggestions' => $suggestions];
    }

    /** @return array<int, string> */
    public static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[.\/][\p{N}]+)*[\p{L}]*/u', $text, $m);

        return array_values(array_unique(array_filter($m[0], fn ($t) => mb_strlen($t) >= 2 && ! in_array($t, self::STOP_WORDS, true))));
    }

    /** What the client may see about a product: no stock or cost data. */
    private function present(Product $p, int $confidence, string $level, string $reason): array
    {
        return [
            'product_id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'brand' => $p->brand,
            'unit' => $p->unit,
            'price' => config('quotation.show_estimated_prices', true) && (float) $p->price > 0 ? round((float) $p->price, 2) : null,
            'confidence' => $confidence,
            'level' => $level,
            'reason' => $reason,
        ];
    }

    private function products(): Collection
    {
        return $this->products ??= Product::query()
            ->where('is_active', true)
            ->when($this->teamId, fn ($q) => $q->where('team_id', $this->teamId))
            ->get(['id', 'sku', 'name', 'brand', 'category', 'unit', 'price']);
    }
}
