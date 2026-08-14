<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Bundle;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Scr1be\ContentTransfer\Model\Bundle;
use ZipArchive;

/**
 * The exploded form of a bundle: `manifest.json` plus one file per entry under its porter's
 * directory.
 *
 * Same document format as the single JSON file — the manifest and the entries are byte-for-byte the
 * objects `JsonCodec` writes — split across files so that a review of a 300-entry capture is a
 * review of the four files that changed.
 *
 * `Magento\Framework\Archive\Zip` is not used: its `pack()` is `$zip->addFile($source)` for exactly
 * one file, and `addFile()` with no local-name argument stores the entry under the source's own
 * path. A multi-entry archive with controlled names is outside what it does.
 *
 * **What is deterministic here and what is not.** Entry names and the order they are added in are a
 * pure function of the bundle. The archive's *bytes* are not: a zip records a modification time per
 * entry, so two captures minutes apart produce different files. Commit the single-JSON form if you
 * want git to show you nothing when nothing changed; the zip is for handing a bundle to someone.
 */
class ZipCodec
{
    public const MANIFEST_ENTRY = 'manifest.json';

    public function __construct(
        private readonly JsonCodec $jsonCodec,
        private readonly EntryNamer $entryNamer
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function write(Bundle $bundle, string $absolutePath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new LocalizedException(
                new Phrase('The archive %1 could not be opened for writing (code %2).', [$absolutePath, $opened])
            );
        }

        try {
            $zip->addFromString(self::MANIFEST_ENTRY, $this->jsonCodec->encode(
                new Bundle($bundle->getManifest(), [])
            ));

            $used = [];

            foreach ($bundle->getEntries() as $entry) {
                $path = $this->entryNamer->path($entry);

                // The namer is injective by construction; this guard exists because "by
                // construction" stops being true the moment somebody edits the namer, and losing an
                // entry from an archive is a failure that looks exactly like a successful export.
                if (isset($used[$path])) {
                    throw new LocalizedException(
                        new Phrase('Two entries claim the archive path "%1".', [$path])
                    );
                }

                $used[$path] = true;
                $zip->addFromString($path, $this->jsonCodec->encodeEntry($entry));
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws LocalizedException
     */
    public function read(string $absolutePath): Bundle
    {
        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);

        if ($opened !== true) {
            throw new LocalizedException(
                new Phrase('The archive %1 could not be opened (code %2).', [$absolutePath, $opened])
            );
        }

        try {
            $manifestJson = $zip->getFromName(self::MANIFEST_ENTRY);

            if ($manifestJson === false) {
                throw new LocalizedException(
                    new Phrase('The archive has no %1.', [self::MANIFEST_ENTRY])
                );
            }

            $manifest = $this->jsonCodec->decode($manifestJson)->getManifest();

            $names = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name === false || $name === self::MANIFEST_ENTRY || !str_ends_with($name, '.json')) {
                    continue;
                }

                $names[] = $name;
            }

            // The archive's own order is whatever the writer happened to use; sorting makes a
            // reader's entry order a function of the names alone, so a hand-assembled archive
            // applies in the same order as a captured one.
            sort($names);

            $entries = [];

            foreach ($names as $name) {
                $json = $zip->getFromName($name);

                if ($json === false) {
                    throw new LocalizedException(
                        new Phrase('The archive entry %1 could not be read.', [$name])
                    );
                }

                $entries[] = $this->jsonCodec->decodeEntry($json);
            }
        } finally {
            $zip->close();
        }

        return new Bundle($manifest, $entries);
    }

    public function isSupported(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip';
    }
}
