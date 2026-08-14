<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Bundle;

use Scr1be\ContentTransfer\Api\Data\EntryInterface;

/**
 * Turns an entry into the path it occupies inside a zip bundle: `<porter>/<slug>.json`.
 *
 * Two properties matter and they pull against each other. The name has to be **readable**, so that
 * a reviewer unzipping a bundle sees `cms_page/about-us.json` and knows what changed. And it has to
 * be **injective**, because two entries landing on one path means one of them silently disappears
 * from the archive — identifiers are free-form strings and `Über uns` and `uber-uns` slugify the
 * same.
 *
 * The compromise: identifiers that are already safe are used verbatim; anything the slug had to
 * change carries a short digest of the original. Clean identifiers stay clean, lossy ones stay
 * distinct, and the mapping is a pure function of the identifier, so the same content always
 * produces the same archive layout.
 */
class EntryNamer
{
    private const SAFE_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';

    private const UNSAFE_RUN = '/[^a-z0-9]+/';

    /**
     * Long enough that a filename survives a deep checkout path, short enough to read.
     */
    private const MAX_SLUG_LENGTH = 80;

    private const DIGEST_LENGTH = 8;

    public function path(EntryInterface $entry): string
    {
        return $entry->getPorterCode() . '/' . $this->slug($entry->getIdentifier()) . '.json';
    }

    public function slug(string $identifier): string
    {
        $lowered = strtolower($identifier);

        if ($lowered === $identifier && preg_match(self::SAFE_PATTERN, $identifier) === 1
            && strlen($identifier) <= self::MAX_SLUG_LENGTH
        ) {
            return $identifier;
        }

        $slug = trim((string)preg_replace(self::UNSAFE_RUN, '-', $lowered), '-');
        $slug = substr($slug, 0, self::MAX_SLUG_LENGTH - self::DIGEST_LENGTH - 1);
        $slug = rtrim($slug, '-');

        // sha1 over the raw identifier, not the slug: the slug is what threw information away, so
        // hashing it would carry the collision straight through.
        $digest = substr(sha1($identifier), 0, self::DIGEST_LENGTH);

        return $slug === '' ? $digest : $slug . '-' . $digest;
    }
}
