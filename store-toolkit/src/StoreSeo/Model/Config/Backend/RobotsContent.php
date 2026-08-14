<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Robots\Model\Config\Value as CoreRobotsConfigValue;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreSeo\Model\Robots\Validator;

/**
 * Backend model for `scr1be_seo/robots/content`.
 *
 * Does two things core's own robots backend model does separately: it refuses to save content that
 * would produce a broken file, and it reports the cache identities that a change invalidates.
 *
 * The identity is deliberately the *same* string core uses — `Magento\Robots\Model\Config\Value`
 * declares `CACHE_TAG = 'robots'` and returns `'robots_' . storeId` — because the page being
 * invalidated is core's `/robots.txt` response, not one of ours.
 */
class RobotsContent extends Value implements IdentityInterface
{
    private Validator $validator;

    private StoreManagerInterface $storeManager;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Validator $validator,
        StoreManagerInterface $storeManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->validator = $validator;
        $this->storeManager = $storeManager;

        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $violations = $this->validator->validate((string) $this->getValue());

        if ($violations !== []) {
            // One exception listing every violation, not one save attempt per typo.
            throw new LocalizedException(
                __('The robots.txt content was rejected: %1', implode(' ', array_map('strval', $violations)))
            );
        }

        return parent::beforeSave();
    }

    /**
     * @return string[]
     */
    public function getIdentities()
    {
        return [CoreRobotsConfigValue::CACHE_TAG . '_' . $this->storeManager->getStore()->getId()];
    }
}
