<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Document;

use Magento\Framework\Model\AbstractModel;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * What a renderer hands back once it has found a document and decided the caller may have it.
 *
 * The entity is carried along rather than re-loaded, because the download controller loads exactly
 * once and then needs the same object twice: to build the cache key from its fingerprint, and — on
 * a miss — to render it. Re-loading between those two steps would open a window where the key
 * describes one revision of the document and the bytes on disk are another.
 *
 * `$entity` is the concrete sales model the renderer put there (an order, invoice, shipment or
 * credit memo). It is typed as the framework base class because there is no common ancestor below
 * it, and each renderer only ever receives its own.
 */
final class LoadedDocument
{
    /**
     * @param DocumentType $type Which document this is
     * @param string $uid The UID exactly as the client sent it, kept for the token payload
     * @param int $entityId Resolved primary key, whatever the UID happened to encode
     * @param string $incrementId Customer-facing number, used in the filename
     * @param int $storeId Store the document belongs to
     * @param string $fingerprint Value that changes when the document does — see CanonicalKeyBuilder
     * @param AbstractModel $entity The loaded sales model, ready to render
     */
    public function __construct(
        public readonly DocumentType $type,
        public readonly string $uid,
        public readonly int $entityId,
        public readonly string $incrementId,
        public readonly int $storeId,
        public readonly string $fingerprint,
        public readonly AbstractModel $entity
    ) {
    }

    /**
     * Filename offered to the client, e.g. `invoice-000000004.pdf`.
     *
     * Built from the increment id rather than the entity id because that is the number printed on
     * the document itself, and the one the customer will quote in an email.
     */
    public function filename(): string
    {
        $suffix = $this->incrementId !== '' ? $this->incrementId : (string) $this->entityId;

        return $this->type->filenamePrefix() . '-' . $suffix . '.pdf';
    }
}
