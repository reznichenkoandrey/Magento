<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Controller\Download;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Controller\Download\Index;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Cache\CanonicalKeyBuilder;
use Scr1be\SignedDocumentDelivery\Model\Cache\DocumentCache;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\Renderer\DocumentRendererInterface;
use Scr1be\SignedDocumentDelivery\Model\Renderer\RendererPool;
use Scr1be\SignedDocumentDelivery\Model\Token\CustomerTokenAuthenticator;
use Scr1be\SignedDocumentDelivery\Model\Token\InvalidTokenException;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenPayload;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenVerifier;

/**
 * The download path, which is where the two locks are actually applied.
 */
class IndexTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;
    private const UID = 'NA==';
    private const TOKEN = 'payload.mac';
    private const BEARER = 'Bearer abcdef0123456789';
    private const KEY = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';
    private const PATH = 'scr1be/signed-documents/ab/cd/'
        . 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789.pdf';

    private HttpRequest&MockObject $request;
    private FileFactory&MockObject $fileFactory;
    private TokenVerifier&MockObject $verifier;
    private CustomerTokenAuthenticator&MockObject $authenticator;
    private DocumentRendererInterface&MockObject $renderer;
    private RendererPool&MockObject $rendererPool;
    private CanonicalKeyBuilder&MockObject $keyBuilder;
    private DocumentCache&MockObject $cache;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->willReturn(self::TOKEN);
        $this->request->method('getHeader')->with('Authorization')->willReturn(self::BEARER);

        $this->fileFactory = $this->createMock(FileFactory::class);
        $this->fileFactory->method('create')->willReturn($this->createMock(ResponseInterface::class));

        $this->verifier = $this->createMock(TokenVerifier::class);
        $this->verifier->method('verify')->willReturn($this->payload());

        $this->authenticator = $this->createMock(CustomerTokenAuthenticator::class);
        $this->authenticator->method('resolveCustomerId')->willReturn(self::CUSTOMER_ID);

        $this->renderer = $this->createMock(DocumentRendererInterface::class);
        $this->renderer->method('loadAndAuthorize')->willReturn($this->document());
        $this->renderer->method('render')->willReturn('%PDF-1.4 bytes');

        $this->rendererPool = $this->createMock(RendererPool::class);
        $this->rendererPool->method('get')->willReturn($this->renderer);

        $this->keyBuilder = $this->createMock(CanonicalKeyBuilder::class);
        $this->keyBuilder->method('build')->willReturn(self::KEY);

        $this->cache = $this->createMock(DocumentCache::class);
        $this->cache->method('relativePath')->willReturn(self::PATH);
        $this->cache->method('directoryCode')->willReturn(DirectoryList::TMP);

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testACacheHitStreamsWithoutRendering(): void
    {
        $this->cache->method('has')->willReturn(true);
        $this->renderer->expects($this->never())->method('render');
        $this->cache->expects($this->never())->method('write');

        $this->fileFactory->expects($this->once())
            ->method('create')
            ->with(
                'invoice-000000004.pdf',
                ['type' => 'filename', 'value' => self::PATH],
                DirectoryList::TMP,
                'application/pdf'
            )
            ->willReturn($this->createMock(ResponseInterface::class));

        $this->controller()->execute();
    }

    public function testACacheMissRendersOnceAndWritesBeforeStreaming(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->renderer->expects($this->once())->method('render');
        $this->cache->expects($this->once())->method('write')->with(self::KEY, '%PDF-1.4 bytes');

        $this->controller()->execute();
    }

    public function testTheDocumentIsAuthorizedAgainOnTheDownloadAndNotJustAtIssue(): void
    {
        // A token is a claim about the past. Between issue and download the order can be
        // reassigned or the customer deleted; five minutes is short, not zero.
        $this->cache->method('has')->willReturn(true);
        $this->renderer->expects($this->once())
            ->method('loadAndAuthorize')
            ->with(self::UID, self::CUSTOMER_ID, self::STORE_ID)
            ->willReturn($this->document());

        $this->controller()->execute();
    }

    public function testTheSignatureIsCheckedBeforeTheCallerIsEvenLookedAt(): void
    {
        // Order of operations: an unsigned request must not cost a token-table read.
        $this->verifier = $this->createMock(TokenVerifier::class);
        $this->verifier->method('verify')->willThrowException(new InvalidTokenException('signature does not match'));
        $this->authenticator->expects($this->never())->method('resolveCustomerId');
        $this->rendererPool->expects($this->never())->method('get');

        $this->expectException(NotFoundException::class);

        $this->controller()->execute();
    }

    public function testAValidLinkPresentedByADifferentCustomerIsRefused(): void
    {
        // The cross-check. Both credentials are individually valid; they simply do not agree.
        $this->authenticator = $this->createMock(CustomerTokenAuthenticator::class);
        $this->authenticator->method('resolveCustomerId')->willReturn(43);
        $this->rendererPool->expects($this->never())->method('get');

        $this->expectException(NotFoundException::class);

        $this->controller()->execute();
    }

    public function testARequestWithoutABearerTokenIsRefusedEvenWithAPerfectlyGoodLink(): void
    {
        // The signed URL is deliberately not a capability: pasting it into a browser gets a 404.
        $this->authenticator = $this->createMock(CustomerTokenAuthenticator::class);
        $this->authenticator->method('resolveCustomerId')
            ->willThrowException(new InvalidTokenException('no Authorization header on the download request'));
        $this->rendererPool->expects($this->never())->method('get');

        $this->expectException(NotFoundException::class);

        $this->controller()->execute();
    }

    public function testAnArrayTokenParameterIsRefusedRatherThanCastToTheStringArray(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->willReturn(['a', 'b']);
        $request->method('getHeader')->willReturn(self::BEARER);
        $this->request = $request;
        $this->verifier->expects($this->never())->method('verify');

        $this->expectException(NotFoundException::class);

        $this->controller()->execute();
    }

    public function testADocumentThatIsNoLongerAvailableIsA404(): void
    {
        $renderer = $this->createMock(DocumentRendererInterface::class);
        $renderer->method('loadAndAuthorize')
            ->willThrowException(new DocumentUnavailableException(new Phrase('nope')));
        $this->rendererPool = $this->createMock(RendererPool::class);
        $this->rendererPool->method('get')->willReturn($renderer);

        $this->expectException(NotFoundException::class);

        $this->controller()->execute();
    }

    public function testAFilesystemFailureIsLoggedAsAnErrorAndStillOnlyA404(): void
    {
        // There is no partial answer to give a customer, but this one is an operator's problem and
        // is logged as such rather than as a refusal.
        $this->cache->method('has')->willThrowException(new FileSystemException(new Phrase('disk gone')));
        $this->logger->expects($this->once())->method('error');

        $this->expectException(NotFoundException::class);

        $this->controller()->execute();
    }

    /**
     * No oracle on the download path either: a bad signature, the wrong caller, a document that has
     * since gone and a broken disk all have to be indistinguishable from outside.
     */
    public function testEveryRefusalLooksIdenticalFromOutside(): void
    {
        $messages = [];

        foreach (['signature', 'caller', 'document', 'filesystem'] as $failure) {
            // Fresh collaborators each round: re-stubbing a method that setUp already stubbed
            // leaves the first configuration in charge, so the arrangement has to replace the
            // whole mock rather than add to it.
            $this->setUp();

            match ($failure) {
                'signature' => $this->verifier = $this->refusingVerifier(),
                'caller' => $this->authenticator = $this->refusingAuthenticator(),
                'document' => $this->rendererPool = $this->refusingPool(),
                'filesystem' => $this->cache = $this->explodingCache(),
            };

            try {
                $this->controller()->execute();
                $this->fail('expected a 404 for the ' . $failure . ' failure');
            } catch (NotFoundException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(4, $messages);
        $this->assertSame(['The requested document is not available.'], array_values(array_unique($messages)));
    }

    private function refusingVerifier(): TokenVerifier&MockObject
    {
        $verifier = $this->createMock(TokenVerifier::class);
        $verifier->method('verify')->willThrowException(new InvalidTokenException('bad'));

        return $verifier;
    }

    private function explodingCache(): DocumentCache&MockObject
    {
        $cache = $this->createMock(DocumentCache::class);
        $cache->method('has')->willThrowException(new FileSystemException(new Phrase('boom')));

        return $cache;
    }

    private function refusingAuthenticator(): CustomerTokenAuthenticator&MockObject
    {
        $authenticator = $this->createMock(CustomerTokenAuthenticator::class);
        $authenticator->method('resolveCustomerId')->willReturn(43);

        return $authenticator;
    }

    private function refusingPool(): RendererPool&MockObject
    {
        $renderer = $this->createMock(DocumentRendererInterface::class);
        $renderer->method('loadAndAuthorize')
            ->willThrowException(new DocumentUnavailableException(new Phrase('nope')));
        $pool = $this->createMock(RendererPool::class);
        $pool->method('get')->willReturn($renderer);

        return $pool;
    }

    private function controller(): Index
    {
        return new Index(
            $this->request,
            $this->fileFactory,
            $this->verifier,
            $this->authenticator,
            $this->rendererPool,
            $this->keyBuilder,
            $this->cache,
            $this->logger
        );
    }

    private function payload(): TokenPayload
    {
        return new TokenPayload(
            DocumentType::INVOICE,
            self::UID,
            self::CUSTOMER_ID,
            self::STORE_ID,
            PHP_INT_MAX,
            'nonce'
        );
    }

    private function document(): LoadedDocument
    {
        return new LoadedDocument(
            DocumentType::INVOICE,
            self::UID,
            4,
            '000000004',
            self::STORE_ID,
            '2026-08-01 10:00:00|2026-08-01 09:00:00',
            new class extends AbstractModel {
                // phpcs:ignore Magento2.Functions.ConstructorEmptyBody
                public function __construct()
                {
                }
            }
        );
    }
}
