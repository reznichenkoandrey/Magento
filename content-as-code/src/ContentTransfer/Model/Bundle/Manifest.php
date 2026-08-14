<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Bundle;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * The header of a bundle: what format it is in, what scope it was captured with, and how much of
 * each kind of content it contains.
 *
 * **There is no timestamp in here, deliberately.** A capture that produces a byte-identical file
 * when nothing changed is the entire point of putting content in git: `git diff` after a re-capture
 * shows the content that moved and nothing else. A `generated_at` field would make every capture
 * dirty and train everyone to ignore the diff. When the bundle was captured is a question the
 * commit already answers, better.
 */
class Manifest
{
    /**
     * Bumped only for a change a previous reader cannot cope with. `apply` refuses a bundle from a
     * future format rather than importing the parts it happens to recognise.
     */
    public const FORMAT_VERSION = 1;

    public const KEY_FORMAT = 'format';
    public const KEY_STORES = 'stores';
    public const KEY_COUNTS = 'counts';

    /**
     * @param string[] $storeCodes Store views the capture was scoped to; empty means unscoped.
     * @param array<string, int> $counts porter code => number of entries
     */
    public function __construct(
        private readonly int $formatVersion,
        private readonly array $storeCodes,
        private readonly array $counts
    ) {
    }

    /**
     * @param string[] $storeCodes
     * @param array<string, int> $counts
     */
    public static function forCapture(array $storeCodes, array $counts): self
    {
        sort($storeCodes);
        ksort($counts);

        return new self(self::FORMAT_VERSION, $storeCodes, $counts);
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    public static function fromArray(array $data): self
    {
        $format = (int)($data[self::KEY_FORMAT] ?? 0);

        if ($format < 1) {
            throw new LocalizedException(
                new Phrase('The bundle manifest has no usable "%1" field.', [self::KEY_FORMAT])
            );
        }

        if ($format > self::FORMAT_VERSION) {
            throw new LocalizedException(
                new Phrase(
                    'This bundle is in format %1; this module reads up to format %2. Upgrade %3 '
                    . 'before applying it.',
                    [$format, self::FORMAT_VERSION, 'Scr1be_ContentTransfer']
                )
            );
        }

        $counts = [];

        foreach ((array)($data[self::KEY_COUNTS] ?? []) as $code => $count) {
            $counts[(string)$code] = (int)$count;
        }

        return new self(
            $format,
            array_values(array_map('strval', (array)($data[self::KEY_STORES] ?? []))),
            $counts
        );
    }

    public function getFormatVersion(): int
    {
        return $this->formatVersion;
    }

    /**
     * @return string[]
     */
    public function getStoreCodes(): array
    {
        return $this->storeCodes;
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    public function getTotalCount(): int
    {
        return array_sum($this->counts);
    }

    /**
     * `counts` is cast to an object so that a bundle with nothing in it still writes `{}` rather
     * than `[]`; PHP cannot tell an empty map from an empty list, and a reader that expects a map
     * should not have to.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::KEY_FORMAT => $this->formatVersion,
            self::KEY_STORES => $this->storeCodes,
            self::KEY_COUNTS => (object)$this->counts,
        ];
    }
}
