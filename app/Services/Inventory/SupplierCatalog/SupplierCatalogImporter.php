<?php

namespace App\Services\Inventory\SupplierCatalog;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCatalogPrice;
use App\Models\SupplierCatalogProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Imports a supplier-product price list (CSV or XLSX) into the Supplier Product Catalog.
 *
 * Columns: Product Name | Category | Supplier Company | Unit Cost (USD) | Pricing Unit.
 *
 * Rules:
 *  - one catalog product per distinct (normalized) description, one supplier per distinct
 *    (normalized) company name, one price per product and supplier;
 *  - idempotent: an identical row already imported is skipped; a different cost for a pair
 *    already in the catalog is reported and left unchanged (never overwritten silently);
 *  - existing suppliers are reused; a supplier that was archived is reported, not restored;
 *  - a product whose description matches an existing inventory product is linked to it;
 *    stock, reorder values, prices, purchase orders and quotes of inventory products are never
 *    touched;
 *  - everything runs in one transaction; a dry run rolls back.
 *
 * Nothing is sent anywhere (no e-mail, notification or external request).
 */
class SupplierCatalogImporter
{
    public const HEADERS = ['product name', 'category', 'supplier company', 'unit cost (usd)', 'pricing unit'];

    /**
     * Read and validate the file without writing anything.
     *
     * @return array{rows: list<array>, errors: list<string>, warnings: list<string>, stats: array}
     */
    public function analyze(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = array_map(fn ($h) => mb_strtolower(SupplierCatalogNormalizer::clean(preg_replace('/^\x{FEFF}/u', '', (string) $h))), array_shift($sheet) ?? []);
        $header = array_slice($header, 0, count(self::HEADERS));
        if ($header !== self::HEADERS) {
            throw new RuntimeException('Unexpected columns. Expected: Product Name, Category, Supplier Company, Unit Cost (USD), Pricing Unit. Found: '.implode(', ', $header));
        }

        $rows = [];
        $errors = [];
        $warnings = [];
        foreach ($sheet as $i => $r) {
            $line = $i + 2; // spreadsheet row number (header is row 1)
            if (count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank row
            }
            $name = SupplierCatalogNormalizer::clean($r[0] ?? '');
            $category = SupplierCatalogNormalizer::clean($r[1] ?? '');
            $supplier = SupplierCatalogNormalizer::clean($r[2] ?? '');
            $rawCost = is_string($r[3] ?? null) ? str_replace([',', '$', ' '], '', $r[3]) : ($r[3] ?? null);
            $unitLabel = SupplierCatalogNormalizer::clean($r[4] ?? '');
            $unit = SupplierCatalogNormalizer::pricingUnit($unitLabel);

            $problems = [];
            if ($name === '') {
                $problems[] = 'no product name';
            } elseif (mb_strlen($name) > 255) {
                $problems[] = 'product name longer than 255 characters';
            }
            if ($category === '') {
                $problems[] = 'no category';
            }
            if ($supplier === '') {
                $problems[] = 'no supplier company';
            }
            if (! is_numeric($rawCost) || (float) $rawCost <= 0) {
                $problems[] = 'unit cost is not a positive amount';
            } elseif (round((float) $rawCost, 2) != (float) $rawCost) {
                $problems[] = 'unit cost has more than 2 decimals';
            }
            if ($unit === null) {
                $problems[] = "pricing unit \"{$unitLabel}\" is not recognised";
            } elseif ($unit['currency'] !== null && $unit['currency'] !== 'USD') {
                $problems[] = "pricing unit currency {$unit['currency']} is not USD";
            }
            if ($problems) {
                $errors[] = "Row {$line}: ".implode('; ', $problems).'.';

                continue;
            }

            $rows[] = [
                'line' => $line,
                'name' => $name,
                'product_key' => SupplierCatalogNormalizer::productKey($name),
                'category' => $category,
                'supplier' => $supplier,
                'supplier_key' => SupplierCatalogNormalizer::supplierKey($supplier),
                'unit_cost' => round((float) $rawCost, 2),
                'pricing_unit' => $unit['unit'],
                'unit_confirmed' => $unit['confirmed'],
                'pricing_unit_label' => $unitLabel,
            ];
        }

        // Consistency inside the file: one category per product, one price per product and supplier.
        $pairs = [];
        $categories = [];
        $unique = [];
        foreach ($rows as $row) {
            $categories[$row['product_key']][$row['category']] = true;
            $pair = $row['product_key'].'|'.$row['supplier_key'];
            if (isset($pairs[$pair])) {
                $first = $pairs[$pair];
                $same = $first['unit_cost'] == $row['unit_cost'] && $first['pricing_unit'] === $row['pricing_unit'];
                $warnings[] = $same
                    ? "Row {$row['line']}: duplicate of row {$first['line']} ({$row['supplier']}, {$row['name']}); skipped."
                    : "Row {$row['line']}: {$row['supplier']} already has a different price for {$row['name']} on row {$first['line']}; row {$row['line']} skipped.";

                continue;
            }
            $pairs[$pair] = $row;
            $unique[] = $row;
        }
        foreach ($categories as $key => $cats) {
            if (count($cats) > 1) {
                $warnings[] = 'Product "'.$key.'" has several categories ('.implode(', ', array_keys($cats)).'); the first is used.';
            }
        }
        foreach ($unique as $row) {
            if (! $row['unit_confirmed']) {
                $unconfirmed[$row['name']] = $row['pricing_unit_label'];
            }
            if (preg_match('/\(verify[^)]*\)/i', $row['name'])) {
                $specs[$row['name']] = true;
            }
        }

        return [
            'rows' => $unique,
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'data_rows' => count($rows) + count($errors),
                'valid_rows' => count($rows),
                'unique_products' => count(array_unique(array_column($rows, 'product_key'))),
                'unique_suppliers' => count(array_unique(array_column($rows, 'supplier_key'))),
                'unique_pairs' => count($unique),
                'duplicates_in_file' => count($rows) - count($unique),
                'unconfirmed_units' => $unconfirmed ?? [],
                'specs_to_verify' => array_keys($specs ?? []),
            ],
        ];
    }

    /**
     * Write the analyzed rows for a team. With $dryRun the transaction is rolled back.
     *
     * @param  list<array>  $rows  rows from analyze()
     * @return array<string, mixed> counts and details of what was (or would be) done
     */
    public function import(int $teamId, array $rows, string $sourceFile, bool $dryRun = false): array
    {
        $report = [
            'suppliers_created' => [], 'suppliers_matched' => [], 'suppliers_unresolved' => [],
            'products_created' => 0, 'products_matched' => 0, 'products_linked_to_inventory' => [],
            'category_differences' => [],
            'prices_created' => 0, 'prices_unchanged' => 0, 'price_conflicts' => [], 'rows_skipped_unresolved_supplier' => 0,
        ];

        DB::beginTransaction();
        try {
            $suppliers = $this->resolveSuppliers($teamId, $rows, $report);
            $knownBrands = Brand::query()->pluck('name')->all();
            $inventory = Product::where('team_id', $teamId)->get(['id', 'name'])
                ->groupBy(fn ($p) => SupplierCatalogNormalizer::productKey($p->name));
            $catalog = SupplierCatalogProduct::where('team_id', $teamId)->get()->keyBy('normalized_name');
            $seen = [];

            foreach ($rows as $row) {
                $supplier = $suppliers[$row['supplier_key']] ?? null;

                $product = $catalog->get($row['product_key']);
                if (! $product) {
                    $product = SupplierCatalogProduct::create([
                        'team_id' => $teamId,
                        'name' => $row['name'],
                        'normalized_name' => $row['product_key'],
                        'category' => $row['category'],
                        'brand' => SupplierCatalogNormalizer::brand($row['name'], $knownBrands),
                    ]);
                    $catalog->put($row['product_key'], $product);
                    $report['products_created']++;
                } elseif (! $product->wasRecentlyCreated && ! isset($seen[$product->id])) {
                    $report['products_matched']++;
                    if ($product->category !== $row['category']) {
                        $report['category_differences'][] = "{$product->name}: catalog has \"{$product->category}\", file has \"{$row['category']}\" (catalog kept).";
                    }
                }
                $seen[$product->id] = true;

                // Already an inventory product with the same description (exactly one): link it, change nothing in it.
                if ($product->product_id === null && ($matches = $inventory->get($row['product_key'])) && $matches->count() === 1) {
                    $product->update(['product_id' => $matches->first()->id]);
                    $report['products_linked_to_inventory'][] = $product->name;
                }

                if (! $supplier) {
                    $report['rows_skipped_unresolved_supplier']++;

                    continue;
                }

                $existing = SupplierCatalogPrice::where('supplier_catalog_product_id', $product->id)->where('supplier_id', $supplier->id)->first();
                if ($existing) {
                    if ((float) $existing->unit_cost == $row['unit_cost'] && $existing->pricing_unit === $row['pricing_unit']) {
                        $report['prices_unchanged']++;
                    } else {
                        $report['price_conflicts'][] = "{$supplier->name} / {$product->name}: catalog has {$existing->unit_cost} per {$existing->pricing_unit}, file has {$row['unit_cost']} per {$row['pricing_unit']} (catalog kept).";
                    }

                    continue;
                }

                SupplierCatalogPrice::create([
                    'team_id' => $teamId,
                    'supplier_catalog_product_id' => $product->id,
                    'supplier_id' => $supplier->id,
                    'unit_cost' => $row['unit_cost'],
                    'currency' => 'USD',
                    'pricing_unit' => $row['pricing_unit'],
                    'pricing_unit_label' => mb_substr($row['pricing_unit_label'], 0, 100),
                    'unit_confirmed' => $row['unit_confirmed'],
                    'is_simulated' => true,
                    'source_file' => mb_substr($sourceFile, 0, 191),
                ]);
                $report['prices_created']++;
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (! $dryRun && $report['suppliers_created']) {
            Cache::forget("team:{$teamId}:procurement_suppliers_v2"); // procurement's supplier list shows new suppliers at once
        }

        return $report;
    }

    /**
     * One supplier record per distinct company: existing (active) suppliers are reused, missing
     * ones are created. A name matching only an archived supplier is not restored: its rows are
     * reported as unresolved.
     *
     * @return array<string, Supplier> keyed by supplier matching key
     */
    private function resolveSuppliers(int $teamId, array $rows, array &$report): array
    {
        $existing = Supplier::withTrashed()->where('team_id', $teamId)->get()
            ->groupBy(fn ($s) => SupplierCatalogNormalizer::supplierKey($s->name));

        $resolved = [];
        foreach (collect($rows)->unique('supplier_key') as $row) {
            $matches = $existing->get($row['supplier_key'], collect());
            $active = $matches->whereNull('deleted_at');
            if ($active->count() === 1) {
                $resolved[$row['supplier_key']] = $active->first();
                $report['suppliers_matched'][] = $active->first()->name;
            } elseif ($active->count() > 1) {
                $report['suppliers_unresolved'][] = "{$row['supplier']}: matches several suppliers (".$active->pluck('name')->implode(', ').'); rows skipped.';
            } elseif ($matches->isNotEmpty()) {
                $report['suppliers_unresolved'][] = "{$row['supplier']}: matches an archived supplier; restore it first; rows skipped.";
            } else {
                $resolved[$row['supplier_key']] = Supplier::create([
                    'team_id' => $teamId,
                    'name' => $row['supplier'],
                    'status' => 'Active',
                ]);
                $report['suppliers_created'][] = $row['supplier'];
            }
        }

        return $resolved;
    }
}
