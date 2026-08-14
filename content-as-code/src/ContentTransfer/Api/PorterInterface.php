<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Api;

use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\Summary;

/**
 * Everything the engine knows about a kind of content.
 *
 * Implement this and add the implementation to the `Scr1be\ContentTransfer\Model\PorterPool`
 * argument in your own `di.xml`; the console commands, the admin page and the data-patch base class
 * pick it up with no further wiring. The engine never type-checks a payload, so a porter is free to
 * store whatever it needs as long as `capture()` and `apply()` agree.
 */
interface PorterInterface
{
    /**
     * Stable machine name. It is the key in the bundle manifest, the value of `--porter` on the
     * command line and the directory name inside a zip bundle, so changing it breaks old bundles.
     */
    public function getCode(): string;

    /**
     * Label for the admin page and the console output.
     */
    public function getLabel(): string;

    /**
     * Codes of the porters whose entries must be applied before this porter's.
     *
     * Declaring `cms_block` here is what lets a page that embeds a block by identifier find it on
     * an install that has neither yet.
     *
     * @return string[]
     */
    public function getDependencies(): array;

    /**
     * List what this porter could capture, for the admin picker.
     *
     * @return Summary[]
     */
    public function summarize(Selection $selection): array;

    /**
     * Capture the selected entities.
     *
     * @return EntryInterface[]
     */
    public function capture(Selection $selection): array;

    /**
     * Does this install already have the entity the entry names?
     *
     * Exists as its own method so that `--dry-run` can report "would create" / "would replace" /
     * "would skip" per entry without a write path that takes a "do not actually write" flag —
     * a flag like that is one `if` away from writing on a dry run.
     */
    public function exists(EntryInterface $entry): bool;

    /**
     * Write one captured entry into this install.
     *
     * Implementations may throw: the engine catches everything and records the entry as failed, so
     * one malformed entry never costs the operator the other 199.
     */
    public function apply(EntryInterface $entry, ImportMode $mode): Outcome;
}
