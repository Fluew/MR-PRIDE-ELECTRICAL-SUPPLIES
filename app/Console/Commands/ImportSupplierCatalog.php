<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Inventory\SupplierCatalog\SupplierCatalogImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * php artisan catalog:import-supplier-prices database/data/mrp_supplier_product_prices_usd_updated.csv
 *     --team=1 --expect-products=47 --expect-prices=137 --expect-suppliers=26 [--dry-run]
 *
 * Imports the supplier-product price list into the Supplier Product Catalog (not into inventory).
 * The file is validated first; when an expected count is given and the file differs, nothing is
 * imported and the difference is reported. Safe to run again: nothing is duplicated.
 */
class ImportSupplierCatalog extends Command
{
    protected $signature = 'catalog:import-supplier-prices
        {file : CSV or XLSX file (Product Name, Category, Supplier Company, Unit Cost (USD), Pricing Unit)}
        {--team= : Team ID or slug (default: the only team)}
        {--expect-products= : Expected number of unique products}
        {--expect-prices= : Expected number of supplier-product price records}
        {--expect-suppliers= : Expected number of unique suppliers}
        {--dry-run : Validate and show what would be imported, then roll back}';

    protected $description = 'Import supplier-product USD prices into the Supplier Product Catalog (idempotent)';

    public function handle(SupplierCatalogImporter $importer): int
    {
        $team = $this->team();
        if (! $team) {
            return self::FAILURE;
        }

        try {
            $analysis = $importer->analyze((string) $this->argument('file'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $s = $analysis['stats'];

        $this->info('File: '.basename((string) $this->argument('file')).' → team '.$team->name." (#{$team->id})");
        $this->table(['Check', 'In file', 'Expected'], [
            ['Data rows', $s['data_rows'], '—'],
            ['Valid rows', $s['valid_rows'], '—'],
            ['Unique products', $s['unique_products'], $this->option('expect-products') ?? '—'],
            ['Supplier-product price records', $s['unique_pairs'], $this->option('expect-prices') ?? '—'],
            ['Unique suppliers', $s['unique_suppliers'], $this->option('expect-suppliers') ?? '—'],
            ['Duplicate rows in file', $s['duplicates_in_file'], '—'],
        ]);
        foreach ($analysis['errors'] as $e) {
            $this->error($e);
        }
        foreach ($analysis['warnings'] as $w) {
            $this->warn($w);
        }

        $discrepancies = array_filter([
            $this->differs('expect-products', $s['unique_products'], 'unique products'),
            $this->differs('expect-prices', $s['unique_pairs'], 'supplier-product price records'),
            $this->differs('expect-suppliers', $s['unique_suppliers'], 'unique suppliers'),
        ]);
        if ($analysis['errors'] || $discrepancies) {
            foreach ($discrepancies as $d) {
                $this->error($d);
            }
            $this->error('Nothing was imported. Correct the file (or the expected counts) and run again.');

            return self::FAILURE;
        }

        $report = $importer->import($team->id, $analysis['rows'], basename((string) $this->argument('file')), (bool) $this->option('dry-run'));

        $this->newLine();
        $this->info($this->option('dry-run') ? 'DRY RUN: nothing was saved. It would have:' : 'Imported:');
        $this->table(['Result', 'Count'], [
            ['Suppliers created', count($report['suppliers_created'])],
            ['Suppliers matched (existing)', count($report['suppliers_matched'])],
            ['Suppliers unresolved', count($report['suppliers_unresolved'])],
            ['Catalog products created', $report['products_created']],
            ['Catalog products matched (existing)', $report['products_matched']],
            ['Linked to existing inventory products', count($report['products_linked_to_inventory'])],
            ['Price records created', $report['prices_created']],
            ['Price records already imported (skipped)', $report['prices_unchanged']],
            ['Price conflicts (kept, not overwritten)', count($report['price_conflicts'])],
            ['Rows skipped (unresolved supplier)', $report['rows_skipped_unresolved_supplier']],
        ]);
        foreach ([...$report['suppliers_unresolved'], ...$report['price_conflicts'], ...$report['category_differences']] as $line) {
            $this->warn($line);
        }
        foreach ($report['products_linked_to_inventory'] as $name) {
            $this->line("Linked to inventory: {$name}");
        }
        if ($s['unconfirmed_units']) {
            $this->newLine();
            $this->warn(count($s['unconfirmed_units']).' product(s) have a pricing unit to confirm (e.g. "pc or set"); stored as pcs, unit confirmed at registration.');
        }
        foreach ($s['specs_to_verify'] as $name) {
            $this->warn("Specification marked for verification in the source: {$name}");
        }

        return self::SUCCESS;
    }

    private function team(): ?Team
    {
        $option = $this->option('team');
        if ($option === null) {
            if (Team::count() !== 1) {
                $this->error('Several teams exist: choose one with --team=<id or slug>.');

                return null;
            }

            return Team::first();
        }
        $team = is_numeric($option) ? Team::find((int) $option) : Team::where('slug', $option)->first();
        if (! $team) {
            $this->error("Team {$option} not found.");
        }

        return $team;
    }

    private function differs(string $option, int $actual, string $label): ?string
    {
        $expected = $this->option($option);
        if ($expected === null || (int) $expected === $actual) {
            return null;
        }

        return "Discrepancy: the file has {$actual} {$label}, expected {$expected}.";
    }
}
