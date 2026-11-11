<?php

namespace App\Services\RecycleBin;

use App\Models\CommercialInvoice;
use App\Models\NotificationLog;
use App\Models\OrderFulfillment;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Permanent delete from the Recycle Bin: the record leaves the database for good.
 *
 * - The record is force-deleted; the database cascades its own child rows (quotation lines and
 *   files, sale items, payments, receipts, invoices, delivery releases and attempt logs).
 * - Rows that point to it without a foreign key are removed too: its customer email log entries.
 * - Its stored files (PDFs, uploaded product lists, payment and delivery proofs) are deleted
 *   from storage, unless another remaining record still uses the same file.
 * - The audit trail is kept: it is the record of who deleted what.
 */
class PermanentDeleter
{
    /** Every column that stores a file path, so a file still in use is never deleted. */
    private const FILE_COLUMNS = [
        'quotations' => ['sent_pdf_path', 'attachment_path', 'material_file_path'],
        'quotation_files' => ['path'],
        'sales' => ['proof_of_payment'],
        'payments' => ['proof_path'],
        'payment_receipts' => ['pdf_path'],
        'sales_invoices' => ['pdf_path'],
        'order_fulfillments' => ['proof_of_delivery'],
        'delivery_releases' => ['proof_path'],
        'delivery_attempt_logs' => ['proof_image'],
        'commercial_invoices' => ['attachment_path'],
    ];

    public function delete(Model $model): void
    {
        $files = $this->files($model);

        DB::transaction(function () use ($model) {
            if ($model instanceof Sale) {
                Quotation::withTrashed()->where('converted_to_sale_id', $model->id)->update(['converted_to_sale_id' => null]);
            }
            foreach ($this->notifiables($model) as [$type, $ids]) {
                if ($ids !== []) {
                    NotificationLog::where('notifiable_type', $type)->whereIn('notifiable_id', $ids)->delete();
                }
            }
            $model->forceDelete();
        });

        // Only once the delete is committed (right away when no outer transaction is open):
        // a rolled-back delete keeps its files.
        DB::afterCommit(fn () => $this->deleteUnusedFiles($files));
    }

    /**
     * The email log entries of the record and of the child records deleted with it.
     *
     * @return list<array{0: string, 1: list<int>}>
     */
    private function notifiables(Model $model): array
    {
        return match (true) {
            $model instanceof Quotation => [['Quotation', [$model->id]]],
            $model instanceof Sale => [
                ['Sale', [$model->id]],
                ['Payment', DB::table('payments')->where('sale_id', $model->id)->pluck('id')->all()],
                ['SalesInvoice', DB::table('sales_invoices')->where('sale_id', $model->id)->pluck('id')->all()],
                ['OrderFulfillment', DB::table('order_fulfillments')->where('sale_id', $model->id)->pluck('id')->all()],
            ],
            $model instanceof OrderFulfillment => [['OrderFulfillment', [$model->id]]],
            default => [],
        };
    }

    /** @return list<string> stored file paths of the record and its cascaded child rows */
    private function files(Model $model): array
    {
        $paths = [];
        $add = function (iterable $values) use (&$paths) {
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $paths[] = $value;
                }
            }
        };
        $columns = fn (string $table, string $key, array $ids) => $ids === [] ? [] : DB::table($table)->whereIn($key, $ids)
            ->get(self::FILE_COLUMNS[$table])->flatMap(fn ($row) => array_values((array) $row))->all();

        if ($model instanceof Quotation) {
            $add($model->only(self::FILE_COLUMNS['quotations']));
            $add($columns('quotation_files', 'quotation_id', [$model->id]));
        }
        if ($model instanceof Sale) {
            $add($model->only(self::FILE_COLUMNS['sales']));
            $paymentIds = DB::table('payments')->where('sale_id', $model->id)->pluck('id')->all();
            $add($columns('payments', 'id', $paymentIds));
            $add($columns('payment_receipts', 'payment_id', $paymentIds));
            $add($columns('sales_invoices', 'sale_id', [$model->id]));
            $fulfillmentIds = DB::table('order_fulfillments')->where('sale_id', $model->id)->pluck('id')->all();
            $add($this->fulfillmentFiles($fulfillmentIds, $columns));
        }
        if ($model instanceof OrderFulfillment) {
            $add($this->fulfillmentFiles([$model->id], $columns));
        }
        if ($model instanceof CommercialInvoice) {
            $add($model->only(self::FILE_COLUMNS['commercial_invoices']));
        }

        return array_values(array_unique($paths));
    }

    private function fulfillmentFiles(array $ids, callable $columns): array
    {
        return [
            ...$columns('order_fulfillments', 'id', $ids),
            ...$columns('delivery_releases', 'order_fulfillment_id', $ids),
            ...$columns('delivery_attempt_logs', 'order_fulfillment_id', $ids),
        ];
    }

    /** @param  list<string>  $paths */
    private function deleteUnusedFiles(array $paths): void
    {
        $disk = Storage::disk('local');
        foreach ($paths as $path) {
            if (str_contains($path, '..') || $this->stillUsed($path)) {
                continue;
            }
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning("Recycle Bin: could not delete stored file {$path}: ".$e->getMessage());
            }
        }
    }

    private function stillUsed(string $path): bool
    {
        foreach (self::FILE_COLUMNS as $table => $cols) {
            $query = DB::table($table)->where(function ($q) use ($cols, $path) {
                foreach ($cols as $col) {
                    $q->orWhere($col, $path);
                }
            });
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
