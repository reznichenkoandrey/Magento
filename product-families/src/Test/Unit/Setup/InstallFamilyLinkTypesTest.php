<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\FamilyLinkType;
use Scr1be\ProductFamilies\Setup\Patch\Data\InstallFamilyLinkTypes;

/**
 * The patch reserves auto-increment ids, which is a bet. These cases are the bet's terms.
 */
class InstallFamilyLinkTypesTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private InstallFamilyLinkTypes $patch;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->connection);
        $setup->method('getTable')->willReturnArgument(0);

        $this->patch = new InstallFamilyLinkTypes($setup, new FamilyLinkType());
    }

    public function testACleanInstallForcesEveryReservedIdAndAddsItsPositionAttribute(): void
    {
        // Per link type: code lookup, id lookup, position-attribute lookup — all empty.
        $this->givenLookups(array_fill(0, 9, false));

        $forced = [];
        $this->connection->method('insertForce')->willReturnCallback(
            static function (string $table, array $bind) use (&$forced): void {
                $forced[$bind['code']] = $bind['link_type_id'];
            }
        );

        $attributes = [];
        $this->connection->method('insert')->willReturnCallback(
            static function (string $table, array $bind) use (&$attributes): void {
                $attributes[] = [$table, $bind['link_type_id'], $bind['product_link_attribute_code']];
            }
        );

        $this->patch->apply();

        $this->assertSame(
            [
                FamilyLinkType::CODE_OTHER_COLORS => FamilyLinkType::LINK_TYPE_OTHER_COLORS,
                FamilyLinkType::CODE_OTHER_SIZES => FamilyLinkType::LINK_TYPE_OTHER_SIZES,
                FamilyLinkType::CODE_SIMILAR => FamilyLinkType::LINK_TYPE_SIMILAR,
            ],
            $forced
        );
        $this->assertSame(
            [
                ['catalog_product_link_attribute', FamilyLinkType::LINK_TYPE_OTHER_COLORS, 'position'],
                ['catalog_product_link_attribute', FamilyLinkType::LINK_TYPE_OTHER_SIZES, 'position'],
                ['catalog_product_link_attribute', FamilyLinkType::LINK_TYPE_SIMILAR, 'position'],
            ],
            $attributes
        );
    }

    /**
     * `patch_list` rows are lost with a partial database restore often enough that re-running has to
     * be free. Everything is checked for presence, so a second apply writes nothing.
     */
    public function testReapplyingAnAlreadyInstalledPatchWritesNothing(): void
    {
        $this->givenLookups([
            (string)FamilyLinkType::LINK_TYPE_OTHER_COLORS, '11',
            (string)FamilyLinkType::LINK_TYPE_OTHER_SIZES, '12',
            (string)FamilyLinkType::LINK_TYPE_SIMILAR, '13',
        ]);

        $this->connection->expects($this->never())->method('insertForce');
        $this->connection->expects($this->never())->method('insert');

        $this->patch->apply();
    }

    /**
     * Our code, someone else's id. The module's constants are compiled into the GraphQL resolvers,
     * so carrying on would address the wrong link type on every query.
     */
    public function testARelocatedCodeStopsTheUpgradeAndSaysWhereItWent(): void
    {
        $this->givenLookups(['77']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already exists with id 77');

        $this->patch->apply();
    }

    /**
     * Our id, someone else's code. Forcing it would take over another extension's links.
     */
    public function testAnIdTakenByAnotherExtensionStopsTheUpgrade(): void
    {
        $this->givenLookups([false, 'acme_bundle_parts']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('acme_bundle_parts');

        $this->patch->apply();
    }

    /**
     * The link type survived a restore but its position attribute did not — the writer would then
     * refuse every run. Adding the missing row is the patch's job and does not need the type
     * reinstalled.
     */
    public function testAMissingPositionAttributeIsAddedToAnExistingLinkType(): void
    {
        $this->givenLookups([
            (string)FamilyLinkType::LINK_TYPE_OTHER_COLORS, false,
            (string)FamilyLinkType::LINK_TYPE_OTHER_SIZES, '12',
            (string)FamilyLinkType::LINK_TYPE_SIMILAR, '13',
        ]);

        $this->connection->expects($this->never())->method('insertForce');

        $inserted = [];
        $this->connection->method('insert')->willReturnCallback(
            static function (string $table, array $bind) use (&$inserted): void {
                $inserted[] = $bind['link_type_id'];
            }
        );

        $this->patch->apply();

        $this->assertSame([FamilyLinkType::LINK_TYPE_OTHER_COLORS], $inserted);
    }

    public function testThePatchDeclaresNoDependenciesAndNoPreviousNames(): void
    {
        $this->assertSame([], InstallFamilyLinkTypes::getDependencies());
        $this->assertSame([], $this->patch->getAliases());
    }

    /**
     * `fetchOne()` is the patch's only read, and it is called in a fixed order — per link type:
     * lookup by code, lookup by id (only when the code was absent), lookup of the position
     * attribute. Queueing the answers keeps the cases readable without a database.
     *
     * @param array<int, string|false> $answers
     */
    private function givenLookups(array $answers): void
    {
        $this->connection->method('fetchOne')->willReturnCallback(
            static function () use (&$answers): string|false {
                return array_shift($answers) ?? false;
            }
        );
    }
}
