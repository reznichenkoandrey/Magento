<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Controller\Download;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NotFoundException;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Cache\CanonicalKeyBuilder;
use Scr1be\SignedDocumentDelivery\Model\Cache\DocumentCache;
use Scr1be\SignedDocumentDelivery\Model\DocumentUrlBuilder;
use Scr1be\SignedDocumentDelivery\Model\Renderer\RendererPool;
use Scr1be\SignedDocumentDelivery\Model\Token\CustomerTokenAuthenticator;
use Scr1be\SignedDocumentDelivery\Model\Token\InvalidTokenException;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenVerifier;

/**
 * Streams the PDF, behind two locks that do not share a key.
 *
 * The order of operations is the design:
 *
 * 1. **Verify the signature.** Nothing the client sent is parsed until the HMAC over it matches.
 * 2. **Authenticate the caller.** The `Authorization: Bearer` header is resolved to a customer id
 *    through Magento_Integration, entirely independently of the token.
 * 3. **Cross-check.** The authenticated customer must be the customer the payload names. A valid
 *    signed URL plus a valid bearer token for a *different* customer is a 404.
 * 4. **Authorize again, from scratch.** The renderer re-loads the document and re-runs the
 *    ownership check using the *authenticated* customer id — never the payload's. The payload
 *    decides what to look for; it never decides who may have it.
 * 5. **Serve.** Cache hit streams; cache miss renders, writes atomically, then streams.
 *
 * Step 4 is what stops a token outliving the permission it was minted under. Between issue and
 * download the order can be reassigned, the customer deleted, the document moved to another store
 * view — five minutes is short but it is not zero, and a token that authorized rather than merely
 * described would sail straight through all three.
 *
 * Every failure is the same 404, produced by throwing NotFoundException, which
 * Magento\Framework\App\FrontController catches and forwards to `noroute`. Bad signature, expired
 * link, missing bearer token, wrong customer, deleted invoice: one response, one log line on the
 * server with the actual reason.
 */
class Index implements HttpGetActionInterface
{
    private const CONTENT_TYPE = 'application/pdf';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly FileFactory $fileFactory,
        private readonly TokenVerifier $tokenVerifier,
        private readonly CustomerTokenAuthenticator $authenticator,
        private readonly RendererPool $rendererPool,
        private readonly CanonicalKeyBuilder $keyBuilder,
        private readonly DocumentCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws NotFoundException
     */
    public function execute(): ResponseInterface
    {
        try {
            return $this->deliver();
        } catch (InvalidTokenException $e) {
            $this->refuse($e->reason);
        } catch (DocumentUnavailableException) {
            // Already logged with its reason where it was raised.
            $this->refuse('document is not available to this caller');
        } catch (\Exception $e) {
            // A filesystem failure or a broken renderer. The customer still gets a 404 — there is
            // no partial answer to give — but this one is an error, not a refusal.
            $this->logger->error(
                'Scr1be_SignedDocumentDelivery failed to deliver a document: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->refuse('unexpected failure while delivering');
        }
    }

    /**
     * @throws InvalidTokenException
     * @throws DocumentUnavailableException
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function deliver(): ResponseInterface
    {
        $token = $this->request->getParam(DocumentUrlBuilder::TOKEN_PARAM, '');
        if (!is_string($token)) {
            // `?token[]=a&token[]=b` reaches getParam() as an array; casting it would emit a
            // conversion warning and then verify the literal string "Array".
            throw new InvalidTokenException('token parameter is not a single string');
        }

        $payload = $this->tokenVerifier->verify($token);

        $callerId = $this->authenticator->resolveCustomerId($this->request->getHeader('Authorization'));
        if ($callerId !== $payload->customerId) {
            throw new InvalidTokenException(
                sprintf('link was issued to customer %d, presented by customer %d', $payload->customerId, $callerId)
            );
        }

        // The payload decides what to look for. The authenticated caller decides who may have it.
        $renderer = $this->rendererPool->get($payload->type);
        $document = $renderer->loadAndAuthorize($payload->uid, $callerId, $payload->storeId);

        $key = $this->keyBuilder->build($document, $callerId);
        if (!$this->cache->has($key)) {
            $this->cache->write($key, $renderer->render($document));
        }

        return $this->fileFactory->create(
            $document->filename(),
            ['type' => 'filename', 'value' => $this->cache->relativePath($key)],
            $this->cache->directoryCode(),
            self::CONTENT_TYPE
        );
    }

    /**
     * @throws NotFoundException
     */
    private function refuse(string $reason): never
    {
        $this->logger->warning('Scr1be_SignedDocumentDelivery refused a download: ' . $reason);

        throw new NotFoundException(__('The requested document is not available.'));
    }
}
