#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Behavioural test for MagicDataAccessorExtension.
 *
 * PHPStan extensions are only observable through PHPStan, so this runs it over `fixture/` and
 * asserts what came back line by line. Each expectation was checked against the opposite
 * implementation before being written down: with the annotation deference removed, case 1 stops
 * being reported, and with the extension unregistered, case 2 starts being.
 *
 *   php tools/phpstan/test/run.php
 */

$root = dirname(__DIR__);
$binary = $root . '/vendor/bin/phpstan';

if (!is_file($binary)) {
    fwrite(STDERR, "phpstan is not installed — run: composer install -d tools/phpstan\n");
    // A test runner's whole output is its exit status, and this is how a PHP script sets one.
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(2);
}

// The result cache is keyed on the content of the *analysed* files, and the extension's own
// source is not one of them — so without this a change to the extension is invisible here and
// the test passes against an implementation it should fail. Found the hard way.
// A PHPStan extension is only observable through PHPStan, so this has to run it. The command
// is built from paths this file computes, with no input from anywhere else.
// phpcs:ignore Magento2.Security.InsecureFunction
exec(sprintf(
    '%s clear-result-cache -c %s 2>/dev/null',
    escapeshellarg($binary),
    escapeshellarg(__DIR__ . '/extension.neon')
));

$command = sprintf(
    '%s analyse -c %s --no-progress --error-format=json --memory-limit=2G 2>/dev/null',
    escapeshellarg($binary),
    escapeshellarg(__DIR__ . '/extension.neon')
);

// phpcs:ignore Magento2.Security.InsecureFunction
exec($command, $output, $ignoredExitCode);
$report = json_decode(implode("\n", $output), true);

if (!is_array($report) || !isset($report['files'])) {
    fwrite(STDERR, "phpstan produced no parseable report\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(2);
}

/** @var array<int, string> $reported line => identifier */
$reported = [];
foreach ($report['files'] as $file) {
    foreach ($file['messages'] as $message) {
        $reported[(int) $message['line']] = (string) ($message['identifier'] ?? '');
    }
}

$fixture = file(__DIR__ . '/fixture/MagicAccessors.php');
$lineOf = static function (string $needle) use ($fixture): int {
    foreach ($fixture as $index => $text) {
        if (str_contains($text, $needle)) {
            return $index + 1;
        }
    }

    throw new RuntimeException(sprintf('Fixture no longer contains "%s"', $needle));
};

$expectations = [
    'an @method annotation keeps its typed signature' => [
        'line' => $lineOf('setStoreIds([1, 2])'),
        'identifier' => 'argument.type',
    ],
    'an unknown prefix is still an error' => [
        'line' => $lineOf('fetchSomething()'),
        'identifier' => 'method.notFound',
    ],
];

$silent = [
    'a magic accessor resolves' => $lineOf('getSomethingNobodyDeclared()'),
];

$failures = [];

foreach ($expectations as $name => $expected) {
    $actual = $reported[$expected['line']] ?? null;
    if ($actual !== $expected['identifier']) {
        $failures[] = sprintf(
            '%s: expected %s on line %d, got %s',
            $name,
            $expected['identifier'],
            $expected['line'],
            $actual === null ? 'nothing' : $actual
        );
    }
}

foreach ($silent as $name => $line) {
    if (isset($reported[$line])) {
        $failures[] = sprintf('%s: line %d should be silent, got %s', $name, $line, $reported[$line]);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL\n  " . implode("\n  ", $failures) . "\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

printf("ok — %d assertions\n", count($expectations) + count($silent));
