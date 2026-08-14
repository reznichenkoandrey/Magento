<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Scr1be\HyvaProductCard\ViewModel\ProductCard;

/**
 * Puts this module's template back on `product_list_item` after the layout has been generated.
 *
 * **Why layout alone is not enough.** `view/frontend/layout/catalog_list_item.xml` re-points the
 * block with a `<referenceBlock>`, which is the declarative way to say it and stays there as the
 * statement of intent. It does not survive: Hyvä declares `product_list_item` in the *theme*
 * (`vendor/hyva-themes/magento2-default-theme/Magento_Catalog/layout/catalog_list_item.xml`), and
 * `View\Layout\File\Collector\Aggregated::getFiles()` adds every module file first and then loops
 * over the inherited themes — so the theme's `<block … template="Magento_Catalog::product/list/
 * item.phtml">` is merged last and puts the stock template back. A module `<sequence>` orders
 * modules against modules and cannot help here.
 *
 * The symptom is the expensive part: no error, no warning, a module that installs and enables and
 * whose every unit test passes, and a storefront that quietly renders Hyvä's card. This module's
 * sibling `Scr1be_HyvaMegaMenu` documents the same trap in its own layout file, which is how the
 * mechanism was recognised here.
 *
 * `layout_generate_blocks_after` is the first point at which the block object exists and nothing has
 * rendered yet — `View\Layout\Builder::generateLayoutBlocks()` dispatches it immediately after
 * `generateElements()`. Setting the template there beats the merge without fighting it.
 *
 * Switching the module off in configuration still returns the stock card, because the check runs
 * before the template is applied.
 */
class ApplyCardTemplate implements ObserverInterface
{
    /** The block Hyvä renders every card through — see `Hyva\Theme\ViewModel\ProductListItem`. */
    public const RENDERER_BLOCK = 'product_list_item';

    public const CARD_TEMPLATE = 'Scr1be_HyvaProductCard::product/list/card.phtml';

    public function __construct(
        private readonly ProductCard $cardViewModel
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->cardViewModel->isEnabled()) {
            return;
        }

        $layout = $observer->getData('layout');

        if (!$layout instanceof LayoutInterface) {
            return;
        }

        $renderer = $layout->getBlock(self::RENDERER_BLOCK);

        /*
         * Absent on every page without a listing handle, which is most of them. The narrower
         * `Template` rather than `AbstractBlock` is deliberate: `setTemplate()` is declared on
         * Template and only reachable through DataObject's `__call` on anything above it, so a
         * block that is not one would be silently "configured" and still render the stock card.
         */
        if (!$renderer instanceof Template) {
            return;
        }

        $renderer->setTemplate(self::CARD_TEMPLATE);
    }
}
