<?php

declare(strict_types=1);

$ptmdTestFailures = [];
$ptmdAssertions = 0;

require __DIR__ . '/social_services_test.php';
require __DIR__ . '/e2e_tests_helpers_test.php';
require __DIR__ . '/video_processor_test.php';

if ($ptmdTestFailures) {
    fwrite(STDERR, "PTMD tests failed (" . count($ptmdTestFailures) . ")\n");
    foreach ($ptmdTestFailures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PTMD tests passed ({$ptmdAssertions} assertions)\n");
