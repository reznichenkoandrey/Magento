<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model\Inspector;

use Magento\Framework\App\Http\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\Inspector\VaryBreakdown;

class VaryBreakdownTest extends TestCase
{
    private VaryBreakdown $breakdown;

    protected function setUp(): void
    {
        $this->breakdown = new VaryBreakdown();
    }

    public function testAKeyDifferentFromItsDefaultContributes(): void
    {
        $explained = $this->explain(
            ['customer_group' => '3'],
            ['customer_group' => 0]
        );

        $this->assertCount(1, $explained['contributors']);
        $this->assertSame('customer_group', $explained['contributors'][0]['key']);
        $this->assertSame('3', $explained['contributors'][0]['value']);
        $this->assertSame('0', $explained['contributors'][0]['default']);
    }

    public function testAFalsyValueIsInertWhateverItsDefaultIs(): void
    {
        // Core's filter tests truthiness first, which is why customer_logged_in never fragments the
        // cache for a guest even though false differs from every non-empty default.
        $explained = $this->explain(
            ['customer_logged_in' => false, 'product_list_limit' => 0, 'promo' => ''],
            ['customer_logged_in' => 'something', 'product_list_limit' => 12, 'promo' => 'none']
        );

        $this->assertSame([], $explained['contributors']);
        $this->assertCount(3, $explained['inert']);

        foreach ($explained['inert'] as $entry) {
            $this->assertSame(VaryBreakdown::REASON_FALSY, $entry['ignored_because']);
        }
    }

    public function testTheComparisonAgainstTheDefaultIsLooseJustLikeCore(): void
    {
        // '1' and true are the same value to core's != comparison, so this key never reaches the
        // hash even though a strict reading would call the two different.
        $explained = $this->explain(
            ['customer_logged_in' => '1'],
            ['customer_logged_in' => true]
        );

        $this->assertSame([], $explained['contributors']);
        $this->assertSame(VaryBreakdown::REASON_EQUALS_DEFAULT, $explained['inert'][0]['ignored_because']);
    }

    public function testAKeyWithNoRegisteredDefaultContributesWhenItIsTruthy(): void
    {
        $explained = $this->explain(['PERSISTENT' => 1], []);

        $this->assertCount(1, $explained['contributors']);
        $this->assertSame('null', $explained['contributors'][0]['default']);
    }

    public function testWellKnownKeysCarryAPointerToTheirCoreSetter(): void
    {
        $explained = $this->explain(['product_list_limit' => 36], ['product_list_limit' => 12]);

        $this->assertStringContainsString('Toolbar', $explained['contributors'][0]['setter']);
    }

    public function testAnUnrecognisedKeyIsReportedAsUnknownRatherThanGuessedAt(): void
    {
        $explained = $this->explain(['some_vendor_flag' => 'on'], []);

        $this->assertSame(VaryBreakdown::SETTER_UNKNOWN, $explained['contributors'][0]['setter']);
    }

    public function testArrayValuesAreRenderedAsJson(): void
    {
        $explained = $this->explain(
            ['weee_tax_region' => ['countryId' => 'US', 'regionId' => 12]],
            ['weee_tax_region' => 0]
        );

        $this->assertSame('{"countryId":"US","regionId":12}', $explained['contributors'][0]['value']);
    }

    public function testALongValueIsTruncatedSoTheRecordStaysReadable(): void
    {
        $explained = $this->explain(['tax_rates' => str_repeat('x', 400)], ['tax_rates' => 0]);

        $rendered = $explained['contributors'][0]['value'];

        $this->assertStringEndsWith('…', $rendered);
        $this->assertLessThan(400, strlen($rendered));
    }

    public function testAnEmptyContextProducesNothingOnEitherList(): void
    {
        $explained = $this->explain([], []);

        $this->assertSame([], $explained['contributors']);
        $this->assertSame([], $explained['inert']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     * @return array{contributors: array<int, array<string, mixed>>, inert: array<int, array<string, mixed>>}
     */
    private function explain(array $data, array $defaults): array
    {
        /** @var Context&MockObject $context */
        $context = $this->createMock(Context::class);
        $context->method('toArray')->willReturn(['data' => $data, 'default' => $defaults]);

        return $this->breakdown->explain($context);
    }
}
