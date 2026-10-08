<?php

/**
 * First file of every storefront page: loads the app, starts the shop session
 * (the same cookie the API reads) and the storefront helpers.
 *
 *   require __DIR__ . '/includes/init.php';
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/storefront.php';

CustomerSession::start();
