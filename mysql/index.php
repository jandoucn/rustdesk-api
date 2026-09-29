<?php
declare(strict_types=1);

// Compatibility entry point for deployments whose document root is mysql/.
// Both database engines run the same route tree and storage abstraction.
require dirname(__DIR__) . '/sqlite/index.php';
