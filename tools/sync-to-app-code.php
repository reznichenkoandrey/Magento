#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Lays the repository's modules out where a Magento installation expects them, and tells you when
 * the two have drifted apart.
 *
 * The repo keeps one folder per *project*, kebab-cased, with the module under `src/`. Magento
 * wants one folder per *module*, PascalCased, under `app/code/Scr1be/`. Three projects ship more
 * than one module, so the mapping is not a rename — it is read from each `registration.php`,
 * which is the only place the module name is actually declared.
 *
 *   php tools/sync-to-app-code.php            # copy repo -> installation
 *   php tools/sync-to-app-code.php --check    # compare only, non-zero when they differ
 *
 * `--check` is the drift gate: the installation is what the stand runs and the repo is what goes
 * to GitHub, and an edit made in the wrong one of the two is invisible until something behaves
 * differently from what you are reading.
 */

$repoRoot = dirname(__DIR__);
$magentoRoot = dirname($repoRoot);
$targetBase = $magentoRoot . '/app/code/Scr1be';

$check = in_array('--check', array_slice($argv, 1), true);

/**
 * Every module in the repo, as module name => absolute source directory.
 *
 * @return array<string, string>
 */
$discover = static function (string $repoRoot): array {
    $modules = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getFilename() !== 'registration.php') {
            continue;
        }

        $path = $file->getPathname();

        // Only under a project's `src/`. `tools/` has no modules, and a fixture that happened to
        // be called registration.php would otherwise be installed as one.
        if (!str_contains($path, '/src/')) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match('/Scr1be_([A-Za-z0-9]+)/', $contents, $matches) !== 1) {
            fwrite(STDERR, sprintf("registration.php declares no Scr1be module: %s\n", $path));

            continue;
        }

        $modules[$matches[1]] = dirname($path);
    }

    ksort($modules);

    return $modules;
};

/**
 * Relative path => sha1, for every file under a directory. Comparing hashes rather than shelling
 * out to diff keeps this usable on a runner with no rsync and no diff.
 *
 * @return array<string, string>
 */
$fingerprint = static function (string $directory): array {
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($directory) + 1);
        $files[$relative] = sha1_file($file->getPathname());
    }

    ksort($files);

    return $files;
};

$copy = static function (string $from, string $to) use (&$copy): void {
    if (!is_dir($to) && !mkdir($to, 0o755, true) && !is_dir($to)) {
        throw new RuntimeException(sprintf('Could not create %s', $to));
    }

    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot()) {
            continue;
        }

        $source = $entry->getPathname();
        $target = $to . '/' . $entry->getFilename();

        if ($entry->isDir()) {
            $copy($source, $target);

            continue;
        }

        if (!copy($source, $target)) {
            throw new RuntimeException(sprintf('Could not copy %s', $source));
        }
    }
};

/** Whatever is in the target and not in the source has to go, or --check would never come clean. */
$prune = static function (string $directory, array $keep) use (&$prune): void {
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) {
            continue;
        }

        $path = $entry->getPathname();

        if ($entry->isDir()) {
            $prune($path, $keep);

            if (iterator_count(new FilesystemIterator($path)) === 0) {
                rmdir($path);
            }

            continue;
        }

        if (!in_array($path, $keep, true)) {
            unlink($path);
        }
    }
};

$modules = $discover($repoRoot);

if ($modules === []) {
    fwrite(STDERR, "No modules found — is this being run from the repository?\n");

    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(2);
}

$drifted = [];
$synced = 0;

foreach ($modules as $module => $source) {
    $target = $targetBase . '/' . $module;

    if ($check) {
        if ($fingerprint($source) !== $fingerprint($target)) {
            $drifted[] = $module;
        }

        continue;
    }

    if (is_dir($target)) {
        $keep = [];
        foreach (array_keys($fingerprint($source)) as $relative) {
            $keep[] = $target . '/' . $relative;
        }
        $prune($target, $keep);
    }

    $copy($source, $target);
    $synced++;
}

if ($check) {
    if ($drifted !== []) {
        fwrite(STDERR, sprintf(
            "drift in %d of %d modules: %s\n",
            count($drifted),
            count($modules),
            implode(', ', $drifted)
        ));

        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit(1);
    }

    printf("no drift — %d modules identical\n", count($modules));

    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(0);
}

printf("synced %d modules into %s\n", $synced, $targetBase);
