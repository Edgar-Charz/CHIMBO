<?php

/** Customers (with their saved addresses) matching the text typed in the order form's customer picker. */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('orders.manage');

adminSendJson(['items' => (new Customer(Database::instance()))->findCustomersForOrder((string) ($_GET['q'] ?? ''))]);
