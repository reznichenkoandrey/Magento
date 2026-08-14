<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;

/**
 * One implementation per document type, registered in di.xml under its DocumentType value.
 *
 * The interface is split in two on purpose. `loadAndAuthorize()` is cheap, has no side effects and
 * is the only thing the GraphQL mutation runs — a client asking for somebody else's invoice is told
 * so immediately, before any PDF machinery is touched. `render()` is the expensive half and only
 * runs on a cache miss inside the download controller.
 *
 * Both halves are called on the download path, and the load runs again there. The signed token is
 * a *claim* about who asked for what, not a grant: it survives the customer being deleted, the
 * order being reassigned or the document being moved to another store view, and re-authorizing is
 * what keeps a five-minute URL from outliving the permission it was issued under.
 */
interface DocumentRendererInterface
{
    /**
     * Resolve a UID to a document and decide whether this customer, in this store, may have it.
     *
     * @param string $uid The UID as sent by the client — base64, in whatever shape core emits
     * @param int $customerId Authenticated customer, never taken from client-supplied data
     * @param int $storeId Store view the request was made in
     * @throws DocumentUnavailableException Not found, not yours, wrong store — one message for all three
     */
    public function loadAndAuthorize(string $uid, int $customerId, int $storeId): LoadedDocument;

    /**
     * Render the already-authorized document to PDF bytes.
     *
     * @return string Raw PDF, ready to be written to the cache
     */
    public function render(LoadedDocument $document): string;
}
