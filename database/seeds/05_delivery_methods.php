<?php

/**
 * Delivery options from the design ("Chagua Usafirishaji"). Staff can change fees later.
 * Standard reaches every region; "Haraka (kesho)" is only for Dar es Salaam (decision D-9).
 * Safe to run again: INSERT IGNORE on the unique code.
 */

return function (Database $db): void {
    $dar_es_salaam_id = $db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'");

    $delivery_methods = [
        ['standard', 'Standard (siku 2–3)', 5000, 2, 3, null, 1],
        ['express', 'Haraka (kesho)', 10000, 1, 1, $dar_es_salaam_id, 2],
    ];

    foreach ($delivery_methods as [$code, $name, $fee, $min_days, $max_days, $region_id, $sort_order]) {
        $db->execute(
            'INSERT IGNORE INTO delivery_methods (
                delivery_method_code, delivery_method_name, delivery_method_fee,
                delivery_method_eta_min_days, delivery_method_eta_max_days, region_id, delivery_method_sort_order
             ) VALUES (:code, :name, :fee, :min_days, :max_days, :region_id, :sort_order)',
            [
                'code' => $code, 'name' => $name, 'fee' => $fee, 'min_days' => $min_days,
                'max_days' => $max_days, 'region_id' => $region_id, 'sort_order' => $sort_order,
            ]
        );
    }
};
