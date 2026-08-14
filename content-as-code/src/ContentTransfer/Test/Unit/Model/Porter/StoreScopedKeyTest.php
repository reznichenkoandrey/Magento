<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model\Porter;

use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Porter\StoreScopedKey;

class StoreScopedKeyTest extends TestCase
{
    public function testAnAllStoreEntityKeepsItsBareIdentifier(): void
    {
        // Most entities on most installs. A suffix here would put `@` in nearly every bundle key
        // for no information.
        $this->assertSame('about-us', (new StoreScopedKey())->build('about-us', []));
    }

    public function testAStoreScopedEntityCarriesItsScope(): void
    {
        // `cms_page.identifier` has a plain btree index, not a unique one: one `home` per store
        // view is the normal multi-store arrangement, and both have to fit in one bundle.
        $this->assertSame('home@de', (new StoreScopedKey())->build('home', ['de']));
    }

    public function testStoreCodesAreSortedSoTheKeyDoesNotDependOnRowOrder(): void
    {
        $key = new StoreScopedKey();

        $this->assertSame($key->build('home', ['de', 'fr']), $key->build('home', ['fr', 'de']));
        $this->assertSame('home@de+fr', $key->build('home', ['fr', 'de']));
    }
}
