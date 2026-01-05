<?php declare(strict_types=1);

/**
 * Bootstrap file for EasyAdmin module tests.
 *
 * Use Common module Bootstrap helper for test setup.
 */

require dirname(__DIR__, 3) . '/bootstrap.php';
require dirname(__DIR__, 3) . '/modules/Common/test/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        'Cron',
        'EasyAdmin',
    ],
    'EasyAdminTest',
    __DIR__ . '/EasyAdminTest'
);
