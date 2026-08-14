<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Model\Cache\CacheSweeper;
use Scr1be\SignedDocumentDelivery\Model\Config;

/**
 * Hourly: throw away rendered PDFs nobody has asked for lately.
 *
 * Hourly rather than nightly because the cost of a sweep is one directory walk and the cost of not
 * sweeping is unbounded disk. A shop that renders a document a second fills `var/tmp` between two
 * nightly runs; the same shop is untroubled by an hourly `readdir` over a sharded tree.
 *
 * The job logs only when it actually removed something. A cron entry that writes a line every hour
 * saying it did nothing is a cron entry nobody reads.
 */
class SweepDocumentCache
{
    public function __construct(
        private readonly CacheSweeper $sweeper,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $deleted = $this->sweeper->sweep($this->config->getCacheLifetime(), $this->dateTime->gmtTimestamp());

        if ($deleted > 0) {
            $this->logger->info(
                sprintf('Scr1be_SignedDocumentDelivery swept %d expired document(s) from the PDF cache.', $deleted)
            );
        }
    }
}
