<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Bundle;

use JsonException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Model\Bundle;
use Scr1be\ContentTransfer\Model\Entry;

/**
 * Bundle ↔ JSON text. No filesystem, no Magento models — just the document format.
 *
 * `json_encode()` is called here directly rather than through
 * `Magento\Framework\Serialize\Serializer\Json`, whose `serialize()` is `json_encode($data)` with no
 * flags: one line, every slash escaped. That is the right default for a cache value and the wrong
 * one for a file a human reviews in a pull request, which is the only thing this format exists for.
 *
 * The three flags are the whole trick:
 * - `JSON_PRETTY_PRINT` — one key per line, so a diff is a diff and not a 40KB single-line rewrite.
 * - `JSON_UNESCAPED_SLASHES` — urls and layout handles stay readable.
 * - `JSON_UNESCAPED_UNICODE` — a German page title stays German instead of becoming `ü`.
 *
 * Entry order is the caller's responsibility and is preserved exactly; the export engine emits
 * entries in dependency order and sorted by identifier, which is what makes two captures of
 * unchanged content byte-identical.
 */
class JsonCodec
{
    public const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public const KEY_MANIFEST = 'manifest';
    public const KEY_ENTRIES = 'entries';
    public const KEY_PORTER = 'porter';
    public const KEY_IDENTIFIER = 'identifier';
    public const KEY_PAYLOAD = 'payload';

    /**
     * @throws LocalizedException
     */
    public function encode(Bundle $bundle): string
    {
        $entries = [];

        foreach ($bundle->getEntries() as $entry) {
            $entries[] = $this->encodeEntryData($entry);
        }

        return $this->toJson(
            [
                self::KEY_MANIFEST => $bundle->getManifest()->toArray(),
                self::KEY_ENTRIES => $entries,
            ]
        );
    }

    /**
     * One entry as its own document — the per-file form used inside a zip bundle.
     *
     * @throws LocalizedException
     */
    public function encodeEntry(EntryInterface $entry): string
    {
        return $this->toJson($this->encodeEntryData($entry));
    }

    /**
     * @throws LocalizedException
     */
    public function decode(string $json): Bundle
    {
        $data = $this->fromJson($json);

        if (!isset($data[self::KEY_MANIFEST]) || !is_array($data[self::KEY_MANIFEST])) {
            throw new LocalizedException(
                new Phrase('The bundle has no "%1" section.', [self::KEY_MANIFEST])
            );
        }

        $entries = [];

        foreach ((array)($data[self::KEY_ENTRIES] ?? []) as $index => $entryData) {
            if (!is_array($entryData)) {
                throw new LocalizedException(
                    new Phrase('Entry #%1 in the bundle is not an object.', [$index])
                );
            }

            $entries[] = $this->decodeEntryData($entryData);
        }

        return new Bundle(Manifest::fromArray($data[self::KEY_MANIFEST]), $entries);
    }

    /**
     * @throws LocalizedException
     */
    public function decodeEntry(string $json): Entry
    {
        return $this->decodeEntryData($this->fromJson($json));
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeEntryData(EntryInterface $entry): array
    {
        return [
            self::KEY_PORTER => $entry->getPorterCode(),
            self::KEY_IDENTIFIER => $entry->getIdentifier(),
            // Cast so that a porter with nothing to say still writes `{}`; PHP's empty array
            // encodes as `[]`, which changes the type of the field between two captures.
            self::KEY_PAYLOAD => (object)$entry->getPayload(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    private function decodeEntryData(array $data): Entry
    {
        foreach ([self::KEY_PORTER, self::KEY_IDENTIFIER] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                throw new LocalizedException(
                    new Phrase('A bundle entry is missing its "%1".', [$required])
                );
            }
        }

        return new Entry(
            $data[self::KEY_PORTER],
            $data[self::KEY_IDENTIFIER],
            (array)($data[self::KEY_PAYLOAD] ?? [])
        );
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    private function toJson(array $data): string
    {
        try {
            return json_encode($data, self::ENCODE_FLAGS);
        } catch (JsonException $exception) {
            throw new LocalizedException(
                new Phrase('The bundle could not be encoded: %1', [$exception->getMessage()]),
                $exception
            );
        }
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function fromJson(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LocalizedException(
                new Phrase('The bundle is not valid JSON: %1', [$exception->getMessage()]),
                $exception
            );
        }

        if (!is_array($data)) {
            throw new LocalizedException(new Phrase('The bundle is not a JSON object.'));
        }

        return $data;
    }
}
