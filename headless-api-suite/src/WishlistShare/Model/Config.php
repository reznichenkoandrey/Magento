<?php
declare(strict_types=1);

namespace Scr1be\WishlistShare\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Wishlist\Model\Config as WishlistConfig;

/**
 * The module's own two settings, plus core's sharing limits read through core's own object.
 *
 * `Magento\Wishlist\Model\Config` already resolves `wishlist/email/number_limit` and
 * `wishlist/email/text_limit` — including their fallbacks to its `SHARING_EMAIL_LIMIT` and
 * `SHARING_TEXT_LIMIT` constants when the fields are blank. Re-reading those paths here would be a
 * second implementation of the same fallback, and the two would drift.
 */
class Config
{
    private const XML_PATH_EMAIL_TEMPLATE = 'scr1be_wishlist_share/email/template';
    private const XML_PATH_EMAIL_IDENTITY = 'scr1be_wishlist_share/email/identity';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WishlistConfig $wishlistConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WishlistConfig $wishlistConfig
    ) {
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getEmailTemplate(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getEmailIdentity(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_IDENTITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * How many addresses one share may name.
     *
     * @return int
     */
    public function getRecipientLimit(): int
    {
        return $this->wishlistConfig->getSharingEmailLimit();
    }

    /**
     * How long the shopper's covering note may be.
     *
     * @return int
     */
    public function getMessageLimit(): int
    {
        return $this->wishlistConfig->getSharingTextLimit();
    }
}
