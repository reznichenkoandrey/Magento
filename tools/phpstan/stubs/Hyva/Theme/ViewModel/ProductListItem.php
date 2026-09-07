<?php
declare(strict_types=1);

namespace Hyva\Theme\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The one Hyvä class this repository names in PHP, declared here so the analysis can run without
 * Hyvä installed.
 *
 * Hyvä is commercial: it ships from a licensed private Packagist, and a public repository has no
 * credentials for it. Without this file PHPStan cannot run in CI at all — `hyva-product-slider`
 * takes this view model in its constructor, so the class has to resolve for that module to be
 * analysed. Putting the licence's credentials into a public workflow was the alternative, and it
 * is not one.
 *
 * What is reproduced is the signature and nothing else: no method body, no docblock of Hyvä's, no
 * implementation. That is the part `hyva-product-slider` compiles against, and the part an
 * analyser needs.
 *
 * It is a subset on purpose. Hyvä's own class also declares `getProductPriceHtml()`,
 * `getItemCacheKeyInfo()` and `getItemHtmlWithRenderer()`; none of them is called from this
 * repository, so none is declared here. A stub that grows past what is used is a second copy of
 * somebody else's API with nothing checking it.
 *
 * `tools/phpstan/test/stub-drift.php` is what keeps it honest — on a machine that does have Hyvä
 * it reflects both and fails when they disagree. Without that, this file silently becomes fiction
 * the first time Hyvä changes a parameter, and CI stays green while the storefront breaks.
 *
 * Loaded through `scanFiles` in `phpstan-ci.neon`, never autoloaded and never executed. The local
 * config does not include it: on the stand the real class is installed and analysing against a
 * copy would defeat the point.
 *
 * @see \Hyva\Theme\ViewModel\ProductListItem in `vendor/hyva-themes/magento2-theme-module`
 */
class ProductListItem implements ArgumentInterface
{
    public function getItemHtml(
        Product $product,
        AbstractBlock $parentBlock,
        string $viewMode,
        string $templateType,
        string $imageDisplayArea,
        bool $showDescription
    ): string {
        throw new \LogicException(self::class . ' is a signature stub and must never be executed.');
    }
}
