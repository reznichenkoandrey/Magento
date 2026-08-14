<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Scr1be\CustomerGroupGuard\Model\Config;

/**
 * Everything the storefront template needs, and nothing that varies per customer.
 *
 * Both values are safe on a full-page-cached page: the setting is store-scoped and the logout
 * route is the same for every visitor. The customer-specific half of the feature arrives later,
 * through the customer-data section, which is the entire reason this template can render inside
 * the cache instead of punching a hole in it.
 */
class ForceLogout implements ArgumentInterface
{
    private const LOGOUT_ROUTE = 'customer/account/logout';

    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $url
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isForceLogoutEnabled();
    }

    public function getLogoutUrl(): string
    {
        return $this->url->getUrl(self::LOGOUT_ROUTE);
    }
}
