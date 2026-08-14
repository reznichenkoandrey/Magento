<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Config;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\DocumentUrlBuilder;
use Scr1be\SignedDocumentDelivery\Model\Renderer\DocumentRendererInterface;
use Scr1be\SignedDocumentDelivery\Model\Renderer\RendererPool;
use Scr1be\SignedDocumentDelivery\Model\Resolver\RequestSalesDocument;
use Scr1be\SignedDocumentDelivery\Model\Token\SignedToken;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenIssuer;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenPayload;

class RequestSalesDocumentTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;
    private const NOW = 1_775_000_000;
    private const TTL = 300;
    private const UID = 'NA==';

    private RendererPool&MockObject $rendererPool;
    private DocumentRendererInterface&MockObject $renderer;
    private TokenIssuer&MockObject $tokenIssuer;
    private DocumentUrlBuilder&MockObject $urlBuilder;
    private Config&MockObject $config;
    private ContextInterface&MockObject $context;
    private ContextExtensionInterface&MockObject $extension;
    private RequestSalesDocument $resolver;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(DocumentRendererInterface::class);
        $this->renderer->method('loadAndAuthorize')->willReturn($this->document());

        $this->rendererPool = $this->createMock(RendererPool::class);
        $this->rendererPool->method('get')->willReturn($this->renderer);

        $this->tokenIssuer = $this->createMock(TokenIssuer::class);
        $this->tokenIssuer->method('issue')->willReturn(
            new SignedToken(
                'payload.mac',
                new TokenPayload(DocumentType::INVOICE, self::UID, self::CUSTOMER_ID, self::STORE_ID, self::NOW + self::TTL, 'nonce')
            )
        );

        $this->urlBuilder = $this->createMock(DocumentUrlBuilder::class);
        $this->urlBuilder->method('build')->willReturn('https://example.test/signeddocument/download/index?token=payload.mac');

        $this->config = $this->createMock(Config::class);
        $this->config->method('getUrlTtl')->willReturn(self::TTL);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $store = $this->getMockBuilder(StoreInterface::class)->disableOriginalConstructor()->getMockForAbstractClass();
        $store->method('getId')->willReturn(self::STORE_ID);

        $this->extension = $this->getMockBuilder(ContextExtensionInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->extension->method('getIsCustomer')->willReturn(true);
        $this->extension->method('getStore')->willReturn($store);

        $this->context = $this->getMockBuilder(ContextInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->context->method('getExtensionAttributes')->willReturn($this->extension);
        $this->context->method('getUserId')->willReturn(self::CUSTOMER_ID);

        $this->resolver = new RequestSalesDocument(
            $this->rendererPool,
            $this->tokenIssuer,
            $this->urlBuilder,
            $this->config,
            $dateTime
        );
    }

    public function testASignedUrlIsReturnedForTheCustomersOwnDocument(): void
    {
        $result = $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID]);

        $this->assertSame([
            'document_type' => 'INVOICE',
            'uid' => self::UID,
            'url' => 'https://example.test/signeddocument/download/index?token=payload.mac',
            'filename' => 'invoice-000000004.pdf',
            'content_type' => 'application/pdf',
            'expires_in' => self::TTL,
            'expires_at' => gmdate(\DATE_ATOM, self::NOW + self::TTL),
        ], $result);
    }

    public function testTheAuthenticatedCustomerIsUsedAndNotAnythingFromTheInput(): void
    {
        // The only place the customer id may come from is the resolved GraphQL context.
        $this->renderer = $this->createMock(DocumentRendererInterface::class);
        $this->renderer->expects($this->once())
            ->method('loadAndAuthorize')
            ->with(self::UID, self::CUSTOMER_ID, self::STORE_ID)
            ->willReturn($this->document());
        $this->rendererPool = $this->createMock(RendererPool::class);
        $this->rendererPool->method('get')->willReturn($this->renderer);

        $this->rebuildResolver();

        $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID, 'customer_id' => 99]);
    }

    public function testNothingIsRenderedWhileIssuing(): void
    {
        // An interactive mutation must not be as slow as the slowest PDF in the catalogue, and the
        // client may never follow the URL at all.
        $this->renderer->expects($this->never())->method('render');

        $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID]);
    }

    public function testAGuestIsToldToLogIn(): void
    {
        $extension = $this->getMockBuilder(ContextExtensionInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $extension->method('getIsCustomer')->willReturn(false);
        $this->context = $this->getMockBuilder(ContextInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->context->method('getExtensionAttributes')->willReturn($extension);

        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID]);
    }

    /**
     * @dataProvider unusableInputs
     */
    public function testUnusableInputIsAnInputError(array $input): void
    {
        $this->expectException(GraphQlInputException::class);

        $this->resolve($input);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableInputs(): array
    {
        return [
            'no type' => [['uid' => self::UID]],
            'a type the enum does not have' => [['document_type' => 'PACKING_LIST', 'uid' => self::UID]],
            'lowercase type' => [['document_type' => 'invoice', 'uid' => self::UID]],
            'no uid' => [['document_type' => 'INVOICE']],
            'blank uid' => [['document_type' => 'INVOICE', 'uid' => '   ']],
        ];
    }

    public function testAnUnavailableDocumentIsANotFoundAndNotAnOracle(): void
    {
        // Missing, someone else's and wrong-store all arrive here as the same exception with the
        // same message. Distinguishing them in the response would let a client enumerate ids.
        $renderer = $this->createMock(DocumentRendererInterface::class);
        $renderer->method('loadAndAuthorize')
            ->willThrowException(new DocumentUnavailableException(new Phrase('The requested document is not available.')));
        $this->rendererPool = $this->createMock(RendererPool::class);
        $this->rendererPool->method('get')->willReturn($renderer);
        $this->rebuildResolver();

        $this->expectException(GraphQlNoSuchEntityException::class);
        $this->expectExceptionMessage('The requested document is not available.');

        $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID]);
    }

    public function testNoTokenIsIssuedForADocumentTheCustomerMayNotHave(): void
    {
        $renderer = $this->createMock(DocumentRendererInterface::class);
        $renderer->method('loadAndAuthorize')
            ->willThrowException(new DocumentUnavailableException(new Phrase('nope')));
        $this->rendererPool = $this->createMock(RendererPool::class);
        $this->rendererPool->method('get')->willReturn($renderer);
        $this->tokenIssuer = $this->createMock(TokenIssuer::class);
        $this->tokenIssuer->expects($this->never())->method('issue');
        $this->rebuildResolver();

        try {
            $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID]);
        } catch (GraphQlNoSuchEntityException) {
            // expected
        }
    }

    public function testTheTtlComesFromTheRequestsOwnStore(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->expects($this->once())->method('getUrlTtl')->with(self::STORE_ID)->willReturn(120);
        $this->tokenIssuer = $this->createMock(TokenIssuer::class);
        $this->tokenIssuer->expects($this->once())
            ->method('issue')
            ->with(DocumentType::INVOICE, self::UID, self::CUSTOMER_ID, self::STORE_ID, 120)
            ->willReturn(new SignedToken(
                'payload.mac',
                new TokenPayload(DocumentType::INVOICE, self::UID, self::CUSTOMER_ID, self::STORE_ID, self::NOW + 120, 'nonce')
            ));
        $this->rebuildResolver();

        $this->assertSame(120, $this->resolve(['document_type' => 'INVOICE', 'uid' => self::UID])['expires_in']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function resolve(array $input): array
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->context,
            $this->createMock(ResolveInfo::class),
            null,
            ['input' => $input]
        );
    }

    private function rebuildResolver(): void
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $this->resolver = new RequestSalesDocument(
            $this->rendererPool,
            $this->tokenIssuer,
            $this->urlBuilder,
            $this->config,
            $dateTime
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
