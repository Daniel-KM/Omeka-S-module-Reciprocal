<?php declare(strict_types=1);

/**
 * Bootstrap file for Reciprocal module tests.
 */

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    ['Common', 'Reciprocal'],
    'ReciprocalTest',
    __DIR__ . '/ReciprocalTest'
);
