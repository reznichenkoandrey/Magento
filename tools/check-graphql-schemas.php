#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Gate: no schema file may declare a type from inside a comment.
 *
 * Magento does not parse `.graphqls` files — it splits them with a regular expression.
 * `Magento\Framework\GraphQlSchemaStitching\GraphQlReader::parseTypes()` runs the pattern
 * reproduced below over the file's raw content: comments are not stripped first, and the
 * keyword alternation carries no word boundaries. A schema keyword followed by whitespace
 * and a word therefore begins a declaration wherever it appears — prose included — and
 * everything up to the next closing brace is swallowed into it.
 *
 * The failure is not local. A comment that reads "an `input` field of the type below" can
 * capture the real declaration underneath it, and schema generation then dies for *every*
 * GraphQL request in the installation, usually with an unterminated-string error that
 * names no file. Nothing in `php -l`, `xmllint` or the unit suite observes this: the file
 * is valid PHP-adjacent text, valid XML is not involved, and no test parses schemas.
 *
 * Two of this repo's own schemas carry comments explaining this exact trap, which is a
 * good way to fall into it. Hence a gate rather than a convention.
 *
 * The pattern is a verbatim copy of core's, taken from Magento 2.4.8-p4. It is duplicated
 * on purpose — the gate has to model what core *does*, and reaching into a private method
 * would tie this script to an implementation detail it cannot call anyway. On a Magento
 * upgrade, re-read parseTypes() and update the four fragments below if they moved.
 *
 * Usage:
 *   php tools/check-graphql-schemas.php [path ...]
 *
 * With no argument it scans the repository root. Exits 0 when clean, 1 on a finding,
 * 2 when it was pointed at something it could not read.
 */

const CORE_TYPE_KINDS = '(type|interface|union|enum|input|scalar)';
const CORE_TYPE_NAME = '([_A-Za-z][_0-9A-Za-z]+)';
const CORE_TYPE_DEFINITION = '([^\{\}]*)(\{[^\}]*\})';
const CORE_SPACE = '[\s\t\n\r]+';

const EXIT_OK = 0;
const EXIT_FINDING = 1;
const EXIT_USAGE = 2;

/**
 * @return string[] Absolute paths, sorted, no duplicates.
 */
function collectSchemas(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $real = realpath($root);
        if ($real === false) {
            throw new RuntimeException(sprintf('%s does not exist', $root));
        }

        if (is_file($real)) {
            $files[$real] = true;
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                // vendor/ and node_modules/ are somebody else's problem, and .git holds no schemas.
                static fn(SplFileInfo $entry): bool =>
                    !$entry->isDir() || !in_array($entry->getFilename(), ['.git', 'vendor', 'node_modules'], true)
            )
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'graphqls') {
                $files[$entry->getRealPath()] = true;
            }
        }
    }

    $paths = array_keys($files);
    sort($paths);

    return $paths;
}

/**
 * The line a byte offset falls on, 1-indexed, and that line's text.
 *
 * @return array{int, string}
 */
function lineAt(string $content, int $offset): array
{
    $before = substr($content, 0, $offset);
    $number = substr_count($before, "\n") + 1;
    $start = strrpos($before, "\n");
    $start = $start === false ? 0 : $start + 1;
    $end = strpos($content, "\n", $offset);
    $line = substr($content, $start, ($end === false ? strlen($content) : $end) - $start);

    return [$number, $line];
}

/**
 * @return array{types: string[], findings: string[]}
 */
function inspect(string $file): array
{
    $content = (string)file_get_contents($file);

    $matched = preg_match_all(
        '/' . CORE_TYPE_KINDS . CORE_SPACE . CORE_TYPE_NAME . CORE_SPACE . CORE_TYPE_DEFINITION . '/i',
        $content,
        $matches,
        PREG_OFFSET_CAPTURE
    );

    if ($matched === false) {
        return ['types' => [], 'findings' => ['the pattern failed to run against this file']];
    }

    $types = [];
    $findings = [];

    foreach ($matches[0] as $index => [$whole, $offset]) {
        $name = $matches[2][$index][0];
        $types[] = $name;

        [$number, $line] = lineAt($content, $offset);
        if (str_starts_with(ltrim($line), '#')) {
            $findings[] = sprintf(
                'line %d declares type "%s" from inside a comment; core will swallow everything '
                . "up to the next closing brace\n      %s",
                $number,
                $name,
                trim($line)
            );
        }
    }

    // A schema core parses nothing out of contributes nothing, which is a defect of its own
    // — most often a declaration already swallowed by something above it.
    if ($types === [] && trim(preg_replace('/^\s*#.*$/m', '', $content) ?? '') !== '') {
        $findings[] = 'core parses no type at all out of this file, though it is not empty';
    }

    return ['types' => $types, 'findings' => $findings];
}

/**
 * @param string[] $roots
 * @return int One of the EXIT_* codes.
 */
function run(array $roots): int
{
    try {
        $schemas = collectSchemas($roots);
    } catch (RuntimeException $e) {
        fwrite(STDERR, sprintf("check-graphql-schemas: %s\n", $e->getMessage()));

        return EXIT_USAGE;
    }

    if ($schemas === []) {
        fwrite(STDOUT, "check-graphql-schemas: no .graphqls files found\n");

        return EXIT_OK;
    }

    $repoRoot = dirname(__DIR__) . '/';
    $failed = 0;

    foreach ($schemas as $file) {
        $result = inspect($file);
        $relative = str_starts_with($file, $repoRoot) ? substr($file, strlen($repoRoot)) : $file;

        if ($result['findings'] === []) {
            fwrite(STDOUT, sprintf(
                "  ok    %-58s %d type%s\n",
                $relative,
                count($result['types']),
                count($result['types']) === 1 ? '' : 's'
            ));
            continue;
        }

        $failed++;
        fwrite(STDOUT, sprintf("  FAIL  %s\n", $relative));
        foreach ($result['findings'] as $finding) {
            fwrite(STDOUT, sprintf("      - %s\n", $finding));
        }
    }

    fwrite(STDOUT, sprintf(
        "\n%d schema%s checked, %d failed\n",
        count($schemas),
        count($schemas) === 1 ? '' : 's',
        $failed
    ));

    return $failed > 0 ? EXIT_FINDING : EXIT_OK;
}

$roots = array_slice($argv, 1);

// The one exit in the file, and the reason the script exists: a gate reports through its
// status code. Magento2.Security.LanguageConstruct forbids exit because in module code it
// kills the request mid-flight — core's own bin/magento exits the same way for the same
// reason this does.
// phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
exit(run($roots === [] ? [dirname(__DIR__)] : $roots));
