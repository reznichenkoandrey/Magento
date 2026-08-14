<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Cache;

use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;

/**
 * Turns everything that affects the bytes into one canonical string, then into one sha256.
 *
 * "Canonical" is doing real work here. A cache key assembled ad hoc — string concatenation in one
 * place, `implode` in another, an `md5(serialize(...))` in a third — is a key whose collision
 * behaviour nobody can reason about. One builder, one separator, one field order, and a version
 * prefix that makes the whole space disposable.
 *
 * What goes in:
 *
 *  - **the key version**, so a change to this list invalidates every file rather than silently
 *    reusing entries built under the old rules;
 *  - **the renderer revision**, bumped by hand when a template, a font or a column changes — the
 *    documented way to ship a visual change without waiting out the cache lifetime;
 *  - **the document type and entity id**, because an invoice 4 and a shipment 4 are different
 *    documents with the same number;
 *  - **the store id**, because the logo, the address block, the currency and the language all come
 *    from it;
 *  - **the customer id**, so a cached file can only ever be served back to the identity it was
 *    authorized for. Nothing should be able to produce the same key under a different owner — this
 *    is the belt to the authorization's braces;
 *  - **the fingerprint**, both `updated_at` timestamps, so an edited document is a different file.
 *
 * What does not go in, and is therefore a known staleness window: store *configuration*. Changing
 * the PDF logo or the store address does not change any `updated_at`, so previously rendered files
 * keep their old header until the sweep removes them. The honest fix is a config-change observer
 * that clears the directory; the honest default is a bounded lifetime, which is what ships. Bumping
 * RENDERER_REVISION is the immediate escape hatch.
 */
class CanonicalKeyBuilder
{
    private const KEY_VERSION = 'v1';

    /**
     * Bump when the rendered output changes for reasons no timestamp captures.
     */
    private const RENDERER_REVISION = '1';

    private const SEPARATOR = '|';

    private const HASH_ALGORITHM = 'sha256';

    /**
     * @return string 64 lowercase hex characters
     */
    public function build(LoadedDocument $document, int $customerId): string
    {
        return hash(self::HASH_ALGORITHM, $this->canonicalString($document, $customerId));
    }

    /**
     * Exposed because it is the thing worth asserting on in a test: hashing an opaque string tells
     * you a hash function works, not that the right fields went into it.
     */
    public function canonicalString(LoadedDocument $document, int $customerId): string
    {
        return implode(self::SEPARATOR, [
            self::KEY_VERSION,
            self::RENDERER_REVISION,
            $document->type->value,
            (string) $document->entityId,
            (string) $document->storeId,
            (string) $customerId,
            $document->fingerprint,
        ]);
    }
}
