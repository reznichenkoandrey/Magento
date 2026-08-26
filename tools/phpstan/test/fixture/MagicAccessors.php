<?php
declare(strict_types=1);

namespace Scr1be\PHPStan\Test\Fixture;

use Magento\Catalog\Model\Product;
use Magento\Widget\Model\Widget\Instance;

/**
 * Three cases the extension has to get right, and one line each so the assertions can name a
 * line number. This file is never executed — `run.php` analyses it and checks what came back.
 */
final class MagicAccessors
{
    /**
     * `Widget\Instance` carries `@method Instance setStoreIds(string $value)`. The annotation has
     * to keep winning: if the extension answers instead, the parameter becomes `mixed ...$args`
     * and this line stops being an error at all.
     */
    public function annotatedAccessorKeepsItsType(Instance $widget): void
    {
        $widget->setStoreIds([1, 2]);
    }

    /**
     * Not declared, not annotated. Only the extension can answer it, and `__call` would reach
     * `getData('something_nobody_declared')` at runtime.
     */
    public function magicAccessorResolves(Product $product): void
    {
        $product->getSomethingNobodyDeclared();
    }

    /**
     * `fetch` is not one of the four prefixes `DataObject::__call()` switches on, so the runtime
     * throws `LocalizedException('Invalid method %1::%2')`. This has to stay an error — it is the
     * signal a blanket suppression would have destroyed, and the reason this extension exists.
     */
    public function invalidPrefixStillFails(Product $product): void
    {
        $product->fetchSomething();
    }
}
