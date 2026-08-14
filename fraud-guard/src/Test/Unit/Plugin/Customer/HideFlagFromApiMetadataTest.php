<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Plugin\Customer;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FraudGuard\Model\FlagResolver;
use Scr1be\FraudGuard\Plugin\Customer\HideFlagFromApiMetadata;

class HideFlagFromApiMetadataTest extends TestCase
{
    private HideFlagFromApiMetadata $plugin;

    protected function setUp(): void
    {
        $this->plugin = new HideFlagFromApiMetadata();
    }

    public function testRemovesTheFlagAndLeavesEverythingElseAlone(): void
    {
        $taxvat = $this->attribute('taxvat');
        $gender = $this->attribute('gender');

        $result = $this->plugin->afterGetCustomAttributesMetadata(
            $this->createMock(CustomerMetadataInterface::class),
            [$taxvat, $this->attribute(FlagResolver::ATTRIBUTE_CODE), $gender]
        );

        $this->assertSame([$taxvat, $gender], $result);
    }

    public function testReturnsAListSoTheResponseStaysAJsonArray(): void
    {
        $result = $this->plugin->afterGetCustomAttributesMetadata(
            $this->createMock(CustomerMetadataInterface::class),
            [$this->attribute(FlagResolver::ATTRIBUTE_CODE), $this->attribute('taxvat')]
        );

        // array_filter preserves keys; a gapped array serialises as a JSON object, not an array.
        $this->assertSame([0], array_keys($result));
    }

    public function testAnAbsentFlagIsNotAnError(): void
    {
        $attributes = [$this->attribute('taxvat')];

        $this->assertSame(
            $attributes,
            $this->plugin->afterGetCustomAttributesMetadata(
                $this->createMock(CustomerMetadataInterface::class),
                $attributes
            )
        );
    }

    private function attribute(string $code): AttributeMetadataInterface&MockObject
    {
        $attribute = $this->createMock(AttributeMetadataInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);

        return $attribute;
    }
}
