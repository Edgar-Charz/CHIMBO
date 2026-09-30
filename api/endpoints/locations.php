<?php

/**
 * Regions (Mkoa) and districts (Wilaya) for the registration and address forms. Public.
 *
 * @var Router $router
 */

// GET /api/v1/regions
$router->get('/regions', function (Request $request) {
    return Response::success((new Region(Database::instance()))->getAllRegions());
});

// GET /api/v1/regions/{id}/districts
$router->get('/regions/{id}/districts', function (Request $request) {
    $region = new Region(Database::instance());
    return Response::success($region->getDistrictsByRegion((int) $request->param('id')));
});
