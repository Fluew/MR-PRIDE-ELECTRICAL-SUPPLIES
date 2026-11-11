<?php

$headers = ['sku', 'productname', 'category', 'unit', 'costprice', 'sellingprice', 'stock', 'reorderlevel', 'reorderquantity'];

function findHeaderIndex(array $headers, array $candidates): int
{
    foreach ($candidates as $cand) {
        foreach ($headers as $idx => $header) {
            if ($header === $cand || str_contains($header, $cand) || str_contains($cand, $header)) {
                return $idx;
            }
        }
    }
    return -1;
}

echo "sku index: " . findHeaderIndex($headers, ['sku', 'code', 'partnumber', 'itemcode', 'id']) . "\n";
echo "name index: " . findHeaderIndex($headers, ['productname', 'name', 'itemname', 'description', 'title']) . "\n";
