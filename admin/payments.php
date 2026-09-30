<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$active_menu      = 'payments';
$planned_phase    = 'Phase 5';
$planned_features = [
    'List payments by method, status and date',
    "See each payment's event timeline and ask the provider for its status",
    'Mark refunds',
];

require __DIR__ . '/includes/coming_soon.php';
