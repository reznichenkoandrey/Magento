<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\Website;
use Scr1be\StoreSeo\Model\Robots\Publisher;

/**
 * Republishes robots.txt whenever the section is saved.
 *
 * `Magento\Config\Model\Config::save()` dispatches
 * `admin_system_config_changed_section_{section}` with `website`, `store` and `changed_paths`
 * after the values are stored and `$this->_appConfig->reinit()` has run — which is what makes it
 * safe to read the new values back out of ScopeConfig from here.
 */
class PublishRobotsFile implements ObserverInterface
{
    private WebsiteRepositoryInterface $websiteRepository;

    private Publisher $publisher;

    public function __construct(WebsiteRepositoryInterface $websiteRepository, Publisher $publisher)
    {
        $this->websiteRepository = $websiteRepository;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer): void
    {
        $website = $this->resolveWebsite((string) $observer->getEvent()->getData('website'));

        if ($website === null) {
            // Saved at default scope: every website inherits from it, so every website is
            // republished. A website with an explicit override simply gets the same bytes back.
            $this->publisher->publishAll();

            return;
        }

        $this->publisher->publishWebsite($website);
    }

    /**
     * The `website` value is whatever went into the `website` request parameter — an id from the
     * admin scope switcher, but Magento\Config\Model\Config also accepts a code through setWebsite(),
     * so both forms are tried. A value that resolves to nothing returns null, which sends the
     * caller down the republish-everything path: the operation is idempotent, and a stale file is
     * a worse outcome than a few extra writes.
     */
    private function resolveWebsite(string $identifier): ?Website
    {
        if ($identifier === '') {
            return null;
        }

        try {
            $website = ctype_digit($identifier)
                ? $this->websiteRepository->getById((int) $identifier)
                : $this->websiteRepository->get($identifier);
        } catch (LocalizedException $e) {
            return null;
        }

        return $website instanceof Website ? $website : null;
    }
}
