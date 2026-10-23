<?php
/**
 * Runs the tests in one file (called by tests/run.php in a separate process).
 *
 * Usage: php tests/run-file.php tests/unit/SomethingTest.php
 */

declare(strict_types=1);

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php tests/run-file.php <test file>\n");
    exit(2);
}

$header = (string) file_get_contents($file, false, null, 0, 4000);
if (str_contains($header, '@ppapi-no-acf')) {
    define('PPAPI_TEST_NO_ACF', true);
}
if (str_contains($header, '@ppapi-bare')) {
    define('PPAPI_TEST_BARE', true);
}

require __DIR__ . '/bootstrap.php';

$before = get_declared_classes();
require $file;
$classes = array_diff(get_declared_classes(), $before);

$passed = 0;
$failed = 0;
$filter = $argv[2] ?? '';

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract() || !$reflection->isSubclassOf(\PostPressAPI\Tests\TestCase::class)) {
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test') || ($filter !== '' && !str_contains($method->getName(), $filter))) {
            continue;
        }

        $name = $reflection->getShortName() . '::' . $method->getName();
        $case = new $class();

        try {
            $case->setUp();
            $method->invoke($case);
            $case->tearDown();
            $passed++;
            echo "  \u{2714} {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            $where = $e->getFile() . ':' . $e->getLine();
            foreach ($e->getTrace() as $frame) {
                if (isset($frame['file']) && str_contains($frame['file'], '/tests/unit/')) {
                    $where = $frame['file'] . ':' . $frame['line'];
                    break;
                }
            }
            echo "  \u{2718} {$name}\n      " . get_class($e) . ': ' . $e->getMessage() . "\n      at " . str_replace(dirname(__DIR__) . '/', '', $where) . "\n";
        }
    }
}

echo "RESULT passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
