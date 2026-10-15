<?php

/**
 * Delivery options. Staff can change fees, areas and estimates later (Admin → Settings → Delivery).
 * Dar es Salaam: "Bolt (Dar es Salaam)", paid by the customer to the rider (blueprint R-26, §12.8). Every other
 * region: "Usafiri wa mikoani", priced by the region's transport estimate and confirmed by staff after measuring the
 * load (R-25, §12.7). Every region: "Nitachukua mwenyewe dukani" (pickup at the shop, free).
 * Safe to run again: INSERT IGNORE on the unique code.
 */

return function (Database $db): void {
    $dar_es_salaam_id = $db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'");

    // [code, name, fee, pricing, is pickup, min days, max days, region, area, sort order]
    $delivery_methods = [
        ['standard',  'Bolt (Dar es Salaam)',       0, 'paid_to_rider',   0, 0, 1, $dar_es_salaam_id, 'only',   1],
        ['upcountry', 'Usafiri wa mikoani',         0, 'region_estimate', 0, 2, 5, $dar_es_salaam_id, 'except', 3],
        ['pickup',    'Nitachukua mwenyewe dukani', 0, 'fixed',           1, 0, 1, null,              'all',    4],
    ];

    foreach ($delivery_methods as [$code, $name, $fee, $pricing, $is_pickup, $min_days, $max_days, $region_id, $area, $sort_order]) {
        $db->execute(
            'INSERT IGNORE INTO delivery_methods (
                delivery_method_code, delivery_method_name, delivery_method_fee, delivery_method_pricing, delivery_method_is_pickup,
                delivery_method_eta_min_days, delivery_method_eta_max_days, region_id, delivery_method_area, delivery_method_sort_order
             ) VALUES (:code, :name, :fee, :pricing, :is_pickup, :min_days, :max_days, :region_id, :area, :sort_order)',
            [
                'code' => $code, 'name' => $name, 'fee' => $fee, 'pricing' => $pricing, 'is_pickup' => $is_pickup,
                'min_days' => $min_days, 'max_days' => $max_days, 'region_id' => $region_id, 'area' => $area, 'sort_order' => $sort_order,
            ]
        );
    }
};
