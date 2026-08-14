<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Website-scoped reader for the robots.txt settings.
 *
 * Website, not store: robots.txt is addressed by host, and a host in Magento maps to a website.
 * Core reads its own `design/search_engine_robots/custom_instructions` at exactly this scope
 * (Magento\Robots\Model\Robots::getData() passes ScopeInterface::SCOPE_WEBSITE), and a module that
 * published a *store*-scoped file would be promising a granularity the URL cannot express.
 */
class Config
{
    public const XML_PATH_ROBOTS_ENABLED = 'scr1be_seo/robots/enabled';
    public const XML_PATH_ROBOTS_CONTENT = 'scr1be_seo/robots/content';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ROBOTS_ENABLED,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    public function getContent(?int $websiteId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ROBOTS_CONTENT,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }
}
