<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\BackInStock\Model\Config;

/**
 * The popup's markup — and nothing about the customer.
 *
 * There is no `getItems()` here on purpose. The block renders inside a full-page-cached document, so
 * anything it knew about the visitor would be served to the next one; the cards arrive as customer
 * data and are rendered by Alpine from `x-for`. What the block contributes is the shell, the labels
 * and the accessibility scaffolding, all of which are identical for everybody.
 */
class Popup extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isPopupEnabled((int)$this->_storeManager->getStore()->getId());
    }

    /**
     * Link to the full list, for the alerts the popup is not showing — the ones still waiting, and
     * the ones already dealt with.
     */
    public function getAccountUrl(): string
    {
        return $this->getUrl('scr1be_backinstock/account/alerts', ['_secure' => true]);
    }
}
