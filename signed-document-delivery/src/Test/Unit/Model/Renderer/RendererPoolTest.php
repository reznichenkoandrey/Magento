<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Renderer;

use Magento\Framework\Exception\ConfigurationMismatchException;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\Renderer\DocumentRendererInterface;
use Scr1be\SignedDocumentDelivery\Model\Renderer\RendererPool;

class RendererPoolTest extends TestCase
{
    public function testARegisteredTypeResolvesToItsRenderer(): void
    {
        $invoice = $this->createMock(DocumentRendererInterface::class);
        $pool = new RendererPool(['INVOICE' => $invoice, 'ORDER' => $this->createMock(DocumentRendererInterface::class)]);

        $this->assertSame($invoice, $pool->get(DocumentType::INVOICE));
    }

    public function testASupportedTypeWithNoRendererWiredToItIsAConfigurationError(): void
    {
        // The enum and the di.xml map can drift apart. The failure has to be loud rather than a
        // "Undefined array key" notice three frames down.
        $pool = new RendererPool(['INVOICE' => $this->createMock(DocumentRendererInterface::class)]);

        $this->expectException(ConfigurationMismatchException::class);

        $pool->get(DocumentType::SHIPMENT);
    }

    public function testAnUnknownKeyIsRejectedAtConstructionTime(): void
    {
        // Compile time, not the one request a year that asks for a credit memo.
        $this->expectException(ConfigurationMismatchException::class);

        new RendererPool(['PACKING_LIST' => $this->createMock(DocumentRendererInterface::class)]);
    }

    public function testSomethingThatIsNotARendererIsRejectedAtConstructionTime(): void
    {
        $this->expectException(ConfigurationMismatchException::class);

        new RendererPool(['INVOICE' => new \stdClass()]);
    }

    public function testTheSupportedTypesAreTheKeysAsEnumCases(): void
    {
        $pool = new RendererPool([
            'ORDER' => $this->createMock(DocumentRendererInterface::class),
            'CREDITMEMO' => $this->createMock(DocumentRendererInterface::class),
        ]);

        $this->assertSame([DocumentType::ORDER, DocumentType::CREDITMEMO], $pool->supportedTypes());
    }

    /**
     * A cross-file contract, read out of the shipped di.xml rather than restated here. Adding a
     * case to the enum and forgetting the wiring would otherwise get all the way to a customer
     * asking for that document type before anything complained.
     */
    public function testTheShippedWiringCoversEveryDocumentTypeAndNothingElse(): void
    {
        $di = new \SimpleXMLElement((string) file_get_contents(__DIR__ . '/../../../../etc/di.xml'));

        $wired = [];
        foreach ($di->xpath('//type[@name="' . RendererPool::class . '"]/arguments/argument/item') ?: [] as $item) {
            $wired[] = (string) $item['name'];
        }

        sort($wired);
        $cases = array_map(static fn (DocumentType $type): string => $type->value, DocumentType::cases());
        sort($cases);

        $this->assertSame($cases, $wired);
    }

    /**
     * The schema's enum members and the PHP enum's values are the same strings, and the resolver
     * maps between them with a bare tryFrom(). A member renamed on one side only would be accepted
     * by GraphQL and then rejected by the resolver as unsupported.
     */
    public function testTheGraphQlEnumMembersMatchThePhpEnum(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../../../../etc/schema.graphqls');

        preg_match('/enum SalesDocumentType[^{]*\{(.*?)\}/s', $schema, $matches);
        preg_match_all('/^\s{4}([A-Z_]+)/m', $matches[1] ?? '', $members);

        $declared = $members[1];
        sort($declared);
        $cases = array_map(static fn (DocumentType $type): string => $type->value, DocumentType::cases());
        sort($cases);

        $this->assertSame($cases, $declared);
    }
}
