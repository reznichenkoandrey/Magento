<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\SearchAutocomplete\Model\Config;

/**
 * Reusing core's settings only helps if the fallbacks match what core does — except where they
 * deliberately do not, which is the case worth asserting.
 */
class ConfigTest extends TestCase
{
    /**
     * Core's `Magento\CatalogSearch\Model\Autocomplete\DataProvider` treats a falsy limit as "no
     * limit at all". For a storefront drop-down that is merely untidy; for a public GraphQL endpoint
     * it is a way to ask for every product that matches "a". This module departs from core here on
     * purpose.
     */
    public function testABlankLimitBecomesADefaultRatherThanUnlimited(): void
    {
        $config = new Config($this->scopeConfig(['catalog/search/autocomplete_limit' => '']));

        $this->assertSame(Config::DEFAULT_LIMIT, $config->getLimit(1));
    }

    public function testAConfiguredLimitIsUsed(): void
    {
        $config = new Config($this->scopeConfig(['catalog/search/autocomplete_limit' => '5']));

        $this->assertSame(5, $config->getLimit(1));
    }

    public function testANegativeLimitIsTreatedAsUnset(): void
    {
        $config = new Config($this->scopeConfig(['catalog/search/autocomplete_limit' => '-3']));

        $this->assertSame(Config::DEFAULT_LIMIT, $config->getLimit(1));
    }

    /**
     * The defaults asserted here are the ones in `Magento_CatalogSearch/etc/config.xml`, so a store
     * with the shipped configuration behaves identically whether it reads it or falls back.
     */
    public function testTheLengthFallbacksMatchCoreDefaults(): void
    {
        $config = new Config($this->scopeConfig([]));

        $this->assertSame(3, $config->getMinQueryLength(1));
        $this->assertSame(128, $config->getMaxQueryLength(1));
    }

    /**
     * @param array<string, string> $values
     * @return ScopeConfigInterface
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? null
        );

        return $scopeConfig;
    }
}
