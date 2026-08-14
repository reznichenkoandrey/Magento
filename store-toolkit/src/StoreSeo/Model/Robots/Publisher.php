<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Psr\Log\LoggerInterface;

/**
 * The one path from "configuration says X" to "the file on disk says X".
 *
 * The observer and the console command both call this rather than reimplementing the sequence,
 * because the interesting part is the order: validate, then write, then invalidate. Invalidating
 * before the write publishes a window in which a crawler can repopulate the cache from the old
 * file, and writing before validating leaves a broken file behind when the validation fails.
 */
class Publisher
{
    private Config $config;

    private Validator $validator;

    private FileWriter $fileWriter;

    private CacheInvalidator $cacheInvalidator;

    private StoreManagerInterface $storeManager;

    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        Validator $validator,
        FileWriter $fileWriter,
        CacheInvalidator $cacheInvalidator,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->validator = $validator;
        $this->fileWriter = $fileWriter;
        $this->cacheInvalidator = $cacheInvalidator;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @return string[] Media-relative paths that were written, for the caller to report.
     */
    public function publishAll(): array
    {
        $published = [];

        foreach ($this->storeManager->getWebsites() as $website) {
            if (!$website instanceof Website) {
                continue;
            }

            $path = $this->publishWebsite($website);

            if ($path !== null) {
                $published[] = $path;
            }
        }

        return $published;
    }

    /**
     * Null when nothing was written — the feature is off for this website, or the stored content
     * does not validate.
     */
    public function publishWebsite(Website $website): ?string
    {
        $websiteId = (int) $website->getId();
        $websiteCode = (string) $website->getCode();

        try {
            if (!$this->config->isEnabled($websiteId)) {
                $this->fileWriter->remove($websiteCode);
                $this->invalidate($website);

                return null;
            }

            $content = $this->config->getContent($websiteId);

            // Second gate. The backend model rejects invalid content on the two paths that reach
            // Magento\Config\Model\Config::save() — the admin form and `bin/magento config:set`,
            // whose DefaultProcessor builds that same model and calls save() on it. Nothing
            // rejects content that arrives through app/etc/config.php, a database restore or a
            // hand-written core_config_data row, and those are exactly the paths a bad file
            // usually comes in on.
            $violations = $this->validator->validate($content);

            if ($violations !== []) {
                $this->logger->warning(
                    sprintf(
                        'Scr1be_StoreSeo: robots.txt for website "%s" was not published: %s',
                        $websiteCode,
                        implode(' ', array_map('strval', $violations))
                    )
                );

                return null;
            }

            $path = $this->fileWriter->publish($websiteCode, $this->normalise($content));
            $this->invalidate($website);

            return $path;
        } catch (LocalizedException $e) {
            // A failed publish must not take an admin config save down with it: the configuration
            // is already stored and correct, and the file is a derived artefact that the console
            // command can regenerate.
            $this->logger->error(
                sprintf('Scr1be_StoreSeo: robots.txt for website "%s" failed: %s', $websiteCode, $e->getMessage())
            );

            return null;
        }
    }

    /**
     * Trailing newline, LF line endings. Both matter: a file whose last directive has no newline
     * is accepted by most crawlers and rejected by some, and CRLF survives an admin textarea on
     * Windows all the way to disk.
     */
    private function normalise(string $content): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $content);

        return rtrim($normalised, "\n") . "\n";
    }

    private function invalidate(Website $website): void
    {
        $this->cacheInvalidator->invalidate(array_map('intval', $website->getStoreIds()));
    }
}
