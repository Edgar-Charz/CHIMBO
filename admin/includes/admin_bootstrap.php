<?php

/**
 * The first file every admin page loads: project setup, admin helpers and the admin session.
 * (This folder is blocked from the browser — these files are only included by pages.)
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require __DIR__ . '/admin_helpers.php';

AdminSession::start();
