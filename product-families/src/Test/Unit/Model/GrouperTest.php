<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\Grouper;

class GrouperTest extends TestCase
{
    private Grouper $grouper;

    protected function setUp(): void
    {
        $this->grouper = new Grouper();
    }

    public function testBucketsProductsByTheirGroupValue(): void
    {
        $families = $this->grouper->group([
            ['entity_id' => 1, 'group_value' => '40', 'variant_value' => '11'],
            ['entity_id' => 2, 'group_value' => '40', 'variant_value' => '12'],
            ['entity_id' => 3, 'group_value' => '41', 'variant_value' => '11'],
        ]);

        $this->assertSame(
            ['40' => [1 => '11', 2 => '12'], '41' => [3 => '11']],
            $families
        );
    }

    /**
     * A multiselect stores its option ids as one comma-separated string, so a product with two
     * values is a member of two families. Treating the raw string as the key instead would make
     * "Backpack,Messenger" a family of its own and every multiselect attribute useless as a key.
     */
    public function testAMultiselectValuePutsOneProductInEveryFamilyItNames(): void
    {
        $families = $this->grouper->group([
            ['entity_id' => 7, 'group_value' => '12,15', 'variant_value' => '3'],
            ['entity_id' => 8, 'group_value' => '15', 'variant_value' => '4'],
        ]);

        $this->assertSame(['12' => [7 => '3'], '15' => [7 => '3', 8 => '4']], $families);
    }

    public function testTrimsWhitespaceAndDiscardsEmptyTokens(): void
    {
        $families = $this->grouper->group([
            ['entity_id' => 5, 'group_value' => ' 12 ,, 15,', 'variant_value' => ' 9 '],
        ]);

        $this->assertSame(['12' => [5 => '9'], '15' => [5 => '9']], $families);
    }

    public function testSkipsRowsWithoutAUsableProductId(): void
    {
        $families = $this->grouper->group([
            ['entity_id' => 0, 'group_value' => '40', 'variant_value' => '1'],
            ['entity_id' => '9', 'group_value' => '40', 'variant_value' => '1'],
        ]);

        $this->assertSame(['40' => [9 => '1']], $families);
    }

    public function testAMissingVariantValueBecomesTheEmptyString(): void
    {
        // The scanner always selects the column, so a row carries the key and a LEFT JOIN makes
        // the value null. Omitting the key entirely is a shape production never produces.
        $families = $this->grouper->group([
            ['entity_id' => 4, 'group_value' => '40', 'variant_value' => null],
        ]);

        $this->assertSame(['40' => [4 => '']], $families);
    }

    /**
     * On a real catalogue most products are the only thing with their exact attribute value, so
     * this filter is what keeps the remaining stages proportional to the number of *families*
     * rather than to the number of products.
     */
    public function testDropsFamiliesThatCannotProduceALink(): void
    {
        $families = $this->grouper->dropSingletons([
            '40' => [1 => 'a', 2 => 'b'],
            '41' => [3 => 'c'],
            '42' => [],
        ]);

        $this->assertSame(['40' => [1 => 'a', 2 => 'b']], $families);
    }

    public function testAcceptsAGeneratorWithoutMaterialisingItFirst(): void
    {
        $rows = (static function (): \Generator {
            yield ['entity_id' => 1, 'group_value' => 'x', 'variant_value' => ''];
            yield ['entity_id' => 2, 'group_value' => 'x', 'variant_value' => ''];
        })();

        $this->assertSame(['x' => [1 => '', 2 => '']], $this->grouper->group($rows));
    }
}
