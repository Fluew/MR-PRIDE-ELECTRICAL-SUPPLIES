<?php

namespace App\Services\Documents;

use App\Models\Quotation;

/**
 * The data printed on the official MR-PRIDE quotation (PDF, image export and print all use the
 * same PDF). Everything comes from the stored quotation, its client and its products; nothing
 * is made up. Missing details are left blank.
 *
 * Amounts: each line shows Quantity × Unit Price. "Less Discount" is what the stored quotation
 * deducts (per-unit line discounts × quantity, plus the overall discount), VAT is shown only
 * when the quotation has tax, and TOTAL AMOUNT is the stored grand total, so the document always
 * matches the database.
 */
class QuotationDocument
{
    /** @return array<string, mixed> */
    public static function data(Quotation $quotation): array
    {
        $quotation->loadMissing(['items.product:id,unit', 'preparedBy:id,name', 'client']);
        $client = $quotation->client;

        $rows = $quotation->items->values()->map(fn ($item, $i) => [
            'no' => $i + 1,
            'description' => (string) $item->product_name,
            'quantity' => (int) $item->quantity,
            'unit' => $item->product?->unit,
            'unit_price' => (float) $item->unit_price,
            'amount' => round((int) $item->quantity * (float) $item->unit_price, 2),
        ]);

        $subTotal = round($rows->sum('amount'), 2);
        $lineDiscounts = round($quotation->items->sum(fn ($item) => (float) $item->discount * (int) $item->quantity), 2);
        $discount = round($lineDiscounts + (float) $quotation->discount, 2);
        $tax = round((float) $quotation->tax_amount, 2);
        $total = round((float) $quotation->grand_total, 2);

        $date = $quotation->quotation_date;
        $validDays = $date && $quotation->valid_until
            ? (int) $date->copy()->startOfDay()->diffInDays($quotation->valid_until->copy()->startOfDay())
            : (int) config('company.quotation_validity_days', 30);

        return [
            'number' => $quotation->quotation_number,
            'date' => $date?->format('F j, Y'),
            'client' => [
                'name' => $quotation->contact_person ?: $client?->contact_person,
                'company' => $quotation->client_name ?: $client?->company_name,
                'address' => $quotation->address ?: $client?->address,
                'phone' => $quotation->phone ?: $client?->phone,
            ],
            'rows' => $rows->all(),
            'currency' => (string) config('company.currency', 'USD'),
            'sub_total' => $subTotal,
            'discount' => $discount,
            'tax' => $tax,
            'tax_rate' => (float) $quotation->tax_rate,
            'total' => $total,
            'valid_days' => max(0, $validDays),
            'prepared_by' => $quotation->preparedBy?->name,
        ];
    }

    /** "USD 1,250.00": the currency code and the amount, the same way everywhere on the document. */
    public static function money(float $amount, string $currency): string
    {
        return $currency.' '.number_format($amount, 2);
    }
}
