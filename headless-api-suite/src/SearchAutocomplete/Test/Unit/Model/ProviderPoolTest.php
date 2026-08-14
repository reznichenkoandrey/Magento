<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SearchAutocomplete\Api\SuggestionProviderInterface;
use Scr1be\SearchAutocomplete\Model\ProviderPool;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * The pool exists for one behaviour: one broken source must not empty the drop-down.
 */
class ProviderPoolTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testKeysTheResultsByTheFieldEachProviderFills(): void
    {
        $pool = new ProviderPool($this->logger, [
            'products' => $this->provider([['sku' => 'A']]),
            'categories' => $this->provider([['id' => 3]]),
        ]);

        $this->assertSame(
            ['products' => [['sku' => 'A']], 'categories' => [['id' => 3]]],
            $pool->collect($this->request())
        );
    }

    /**
     * A shopper cannot tell "no results" from "something broke", so the failure must cost only its
     * own section — and must be visible to the operator.
     */
    public function testOneFailingProviderCostsOnlyItsOwnSection(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('terms'));

        $pool = new ProviderPool($this->logger, [
            'products' => $this->provider([['sku' => 'A']]),
            'terms' => $this->failingProvider(),
        ]);

        $this->assertSame(
            ['products' => [['sku' => 'A']], 'terms' => []],
            $pool->collect($this->request())
        );
    }

    /**
     * The schema declares every section as a non-null list, so an early return has to know the field
     * names even when no provider ran.
     */
    public function testExposesItsKeys(): void
    {
        $pool = new ProviderPool($this->logger, [
            'products' => $this->provider([]),
            'terms' => $this->provider([]),
        ]);

        $this->assertSame(['products', 'terms'], $pool->getKeys());
    }

    public function testRefusesAPoolEntryThatIsNotAProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderPool($this->logger, ['products' => new \stdClass()]);
    }

    private function request(): SuggestionRequest
    {
        return new SuggestionRequest('shirt', 1, 1, 0, 8);
    }

    /**
     * @param array<int, array<string, mixed>> $result
     * @return SuggestionProviderInterface
     */
    private function provider(array $result): SuggestionProviderInterface
    {
        $provider = $this->createMock(SuggestionProviderInterface::class);
        $provider->method('getSuggestions')->willReturn($result);

        return $provider;
    }

    private function failingProvider(): SuggestionProviderInterface
    {
        $provider = $this->createMock(SuggestionProviderInterface::class);
        $provider->method('getSuggestions')->willThrowException(new \RuntimeException('index is rebuilding'));

        return $provider;
    }
}
