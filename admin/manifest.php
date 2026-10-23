<?php
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'id'                => './',
    'name'              => 'CHIMBO Admin',
    'short_name'        => 'CHIMBO',
    'description'       => 'Manage CHIMBO orders, customers and products.',
    'start_url'         => './index.php',
    'scope'             => './',
    'display'           => 'standalone',
    'background_color'  => '#FBF6EE',
    'theme_color'       => '#0E3B2A',
    'icons'             => [
        [
            'src'     => 'assets/img/favicon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'pwa_icon.php',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
