<?php
/**
 * Test runner for the Right Air CRM offline test suites.
 *
 * These suites stub the handful of WordPress / WooCommerce functions the
 * modules touch, so the real plugin code runs with no WordPress, no database,
 * no Zoho API calls and no live webhook. Safe to run anywhere.
 *
 * Usage, from the plugin directory:
 *
 *   php tests/run-tests.php
 *
 * Exits non-zero if any suite fails, so it can be wired into CI.
 */

$suites = [
    'test-quote-detection.php'   => 'Quote vs converted order, and the conversion safety net',
    'test-reference-parsing.php' => 'Books invoice reference -> WooCommerce order number',
    'test-push-orders.php'       => 'Push Orders admin screen',
    'test-duplicate-guard.php'   => 'Duplicate Deal protection',
];

$php    = PHP_BINARY;
$plugin = dirname(__DIR__);
$failed = [];
$totals = ['pass' => 0, 'fail' => 0];

foreach ($suites as $file => $description) {
    $path = __DIR__ . '/' . $file;

    if (!is_file($path)) {
        echo "MISSING  {$file}\n";
        $failed[] = $file;
        continue;
    }

    $cmd = sprintf('%s %s %s 2>&1', escapeshellarg($php), escapeshellarg($path), escapeshellarg($plugin));
    exec($cmd, $output, $status);

    $summary = '';
    foreach (array_reverse($output) as $line) {
        if (preg_match('/(\d+) passed, (\d+) failed/', $line, $m)) {
            $summary = $line;
            $totals['pass'] += (int) $m[1];
            $totals['fail'] += (int) $m[2];
            break;
        }
    }

    printf("%-8s %-30s %s\n", $status === 0 ? 'OK' : 'FAIL', $file, trim($summary));

    if ($status !== 0) {
        $failed[] = $file;
        // Show the detail for whatever broke.
        echo "\n" . implode("\n", $output) . "\n\n";
    }

    $output = [];
}

printf("\n%s\n%d assertions passed, %d failed across %d suites\n",
    str_repeat('-', 60), $totals['pass'], $totals['fail'], count($suites));

if (!empty($failed)) {
    echo "Failing suites: " . implode(', ', $failed) . "\n";
    exit(1);
}

exit(0);
