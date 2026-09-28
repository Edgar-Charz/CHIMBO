<?php

/**
 * CHIMBO API routes — the full list of endpoints in one place.
 * Base URL: {APP_URL}/api   e.g. http://localhost/chimbo/api/v1/health
 *
 * @var Router $router  (created in api/index.php)
 */

$router->group('/v1', function (Router $r) {

    // --- System ---
    $r->get('/health', [HealthController::class, 'show']);

    // Phase 1: auth, locations, profile
    // Phase 2: home, categories, products, search
    // Phase 3: wishlist, cart, addresses
    // Phase 4: checkout, orders, notifications, support
    // Phase 5: payments, webhooks
});
