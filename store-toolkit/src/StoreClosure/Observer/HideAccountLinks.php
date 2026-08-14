<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use Scr1be\StoreClosure\Model\ClosureState;

/**
 * Takes the account menu out of a closed store's header, and leaves the store switcher in it.
 *
 * That asymmetry is the whole point. A closed store is a dead end, and the one thing a visitor who
 * lands on it needs is a way to an open sibling — so the switcher stays, while the sign-in and
 * register links that would only lead to a route the redirect observer bounces are removed.
 *
 * Done from `layout_generate_blocks_after` rather than from a layout XML `remove`, because a
 * layout removal is unconditional and the closure is a runtime flag.
 * Magento\Framework\View\Layout\Builder::generateLayoutBlocks() dispatches that event with
 * `full_action_name` and `layout` after generateElements() has run, and
 * LayoutInterface::unsetElement() drops a block from the registry before anything renders it.
 */
class HideAccountLinks implements ObserverInterface
{
    /**
     * Hyvä's Magento_Customer/layout/default.xml puts the account menu in `header.customer` and the
     * login modal in `authentication-popup`, both under `header-content`. Removing the parent takes
     * its children — the dashboard link, the address book link, sign-in, register — with it.
     *
     * @var string[]
     */
    private const BLOCKS = [
        'header.customer',
        'authentication-popup',
    ];

    private ClosureState $closureState;

    public function __construct(ClosureState $closureState)
    {
        $this->closureState = $closureState;
    }

    public function execute(Observer $observer): void
    {
        $layout = $observer->getEvent()->getData('layout');

        if (!$layout instanceof LayoutInterface || !$this->closureState->isCurrentStoreClosed()) {
            return;
        }

        foreach (self::BLOCKS as $blockName) {
            if ($layout->hasElement($blockName)) {
                $layout->unsetElement($blockName);
            }
        }
    }
}
