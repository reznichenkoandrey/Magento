<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store-scoped reads of the three settings the menu has.
 *
 * Every value is read at store-view scope because the menu is a storefront artefact: the same
 * website can run a retail storefront and a trade storefront off different root categories, and
 * a website-scoped read would force them to agree.
 */
class Config
{
    private const XML_PATH_DEFAULT_ROOT = 'scr1be_mega_menu/menu/default_root';
    private const XML_PATH_GROUP_MAP = 'scr1be_mega_menu/menu/group_map';
    private const XML_PATH_THIRD_LEVEL = 'scr1be_mega_menu/menu/third_level';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The configured root category id, or null when the field is empty.
     *
     * Null is not an error state: it is the normal answer, and it means "use the root category
     * this store view is already assigned to". Zero is folded into null because category id 0 is
     * Magento's "no root category" sentinel (Category::ROOT_CATEGORY_ID), never a real category.
     */
    public function getDefaultRootCategoryId(?int $storeId = null): ?int
    {
        $configured = (int) $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_ROOT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $configured > 0 ? $configured : null;
    }

    public function getGroupMapRaw(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GROUP_MAP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isThirdLevelEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_THIRD_LEVEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
