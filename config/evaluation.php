<?php

/*
 * Fixed weights and periods for the data-driven evaluations. They are deliberately not editable
 * from the UI: staff cannot change how scores are calculated or overwrite the results.
 */
return [
    'product_priority' => [
        'weights' => [
            'demand' => 0.40,
            'stock' => 0.30,
            'movement' => 0.20,
            'supplier_availability' => 0.10,
        ],
        // Months of sales history summed for the Product Movement criterion.
        'movement_months' => 12,
        // The weighted sum is calculated on 0–100 and reported on the 1–5 AHP priority scale as
        // 1 + 4 × (score / 100), rounded to 2 decimals. The classification uses the 1–5 value and the
        // five equal-width (0.80) bands below, which are the same 20-point bands of the 0–100 score
        // (20 → 1.80, 40 → 2.60, 60 → 3.40, 80 → 4.20). Ordered from the highest band down.
        'scale' => ['min' => 1.0, 'max' => 5.0],
        'classes' => [
            ['min' => 4.21, 'max' => 5.00, 'label' => 'Very High Priority', 'action' => 'Reorder Immediately'],
            ['min' => 3.41, 'max' => 4.20, 'label' => 'High Priority', 'action' => null],
            ['min' => 2.61, 'max' => 3.40, 'label' => 'Moderate Priority', 'action' => null],
            ['min' => 1.81, 'max' => 2.60, 'label' => 'Low Priority', 'action' => null],
            ['min' => 1.00, 'max' => 1.80, 'label' => 'Very Low Priority', 'action' => null],
        ],
    ],

    'supplier' => [
        'weights' => [
            'delivery' => 0.40,
            'price' => 0.35,
            'quality' => 0.25,
        ],
        'delivery_split' => [
            'on_time' => 0.60,
            'fulfillment' => 0.40,
        ],
        // Rolling evaluation period, in months, ending today.
        'period_months' => 12,
        'classes' => [
            90 => 'Excellent',
            80 => 'Very Good',
            70 => 'Good',
            60 => 'Fair',
            0 => 'Needs Improvement',
        ],
    ],
];
