<?php
/**
 * Post Press API test runner (no dependencies).
 *
 *   php tests/run.php                 run every tests/unit/*Test.php file
 *   php tests/run.php PathResolver    run files whose name contains "PathResolver"
 *
 * Each file runs in its own PHP process so files can choose whether ACF exists.
 * HTTP is always mocked; no network or database is used.
 */

declare(strict_types=1);

$pattern = $argv[1] ?? '';
$files   = glob(__DIR__ . '/unit/*Test.php') ?: [];
sort($files);

$total_passed = 0;
$total_failed = 0;
$broken       = [];

foreach ($files as $file) {
    if ($pattern !== '' && !str_contains(basename($file), $pattern)) {
        continue;
    }

    echo basename($file) . "\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run-file.php') . ' ' . escapeshellarg($file) . ' 2>&1';
    $output  = [];
    exec($command, $output, $code);

    $summary = null;
    foreach ($output as $line) {
        if (preg_match('/^RESULT passed=(\d+) failed=(\d+)$/', $line, $m)) {
            $summary = [(int) $m[1], (int) $m[2]];
            continue;
        }
        echo $line . "\n";
    }

    if ($summary === null) {
        $broken[] = basename($file);
        $total_failed++;
        echo "  \u{2718} file did not complete (exit code {$code})\n";
        continue;
    }

    $total_passed += $summary[0];
    $total_failed += $summary[1];
}

echo "\n" . sprintf('%d passed, %d failed', $total_passed, $total_failed) . ($broken ? ' (incomplete: ' . implode(', ', $broken) . ')' : '') . "\n";
echo 'PHP ' . PHP_VERSION . "\n";
exit($total_failed > 0 ? 1 : 0);
