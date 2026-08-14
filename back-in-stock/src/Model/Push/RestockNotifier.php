<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Api\PushTransportInterface;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\DeviceTokenRegistry;

/**
 * One restock, one notification, and the token clean-up that follows it.
 *
 * This class is where the push channel earns the word "optional". Every early return in it is a
 * reason not to send — the channel is off for this website, the customer has no device registered,
 * the product cannot be resolved — and each of them costs one config read or one indexed select. A
 * shop that never turns push on pays for `isPushEnabled()` returning false, and nothing else.
 */
class RestockNotifier
{
    /**
     * The label attached to tokens the transport refused. Written to
     * `scr1be_push_device_token.deactivated_reason`, so "why did this device stop getting alerts"
     * has an answer in the row rather than in a log file that has since rotated.
     */
    private const RETIRE_REASON = 'refused by push transport';

    public function __construct(
        private readonly Config $config,
        private readonly DeviceTokenRegistry $registry,
        private readonly PushTransportInterface $transport,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Never throws. The caller is an observer on an event dispatched from inside
     * `Magento\ProductAlert\Model\ResourceModel\Stock::save()`, which is itself inside the alert mail
     * run — a run that is already holding a customer's email in a buffer waiting to be sent. A push
     * notification is a nice-to-have; the email is the thing the customer asked for.
     */
    public function notify(int $customerId, int $productId, int $websiteId, int $storeId): void
    {
        if ($customerId <= 0 || $productId <= 0 || !$this->config->isPushEnabled($websiteId)) {
            return;
        }

        try {
            $tokens = $this->registry->getActiveTokens($customerId, $websiteId);

            if ($tokens === []) {
                return;
            }

            // Store-scoped, so the name is the store's name and `getProductUrl()` resolves against
            // the store's base url rather than whichever store the cron process happens to be in.
            $product = $this->productRepository->getById($productId, false, $storeId);

            $result = $this->transport->send(
                new PushMessage(
                    $this->config->getPushTitle($storeId) ?: (string)__('Back in stock'),
                    (string)$product->getName(),
                    (string)$product->getProductUrl(),
                    [
                        'product_id' => (string)$productId,
                        'sku' => (string)$product->getSku(),
                    ]
                ),
                $tokens
            );

            if ($result->invalidTokens !== []) {
                $this->registry->retire($result->invalidTokens, self::RETIRE_REASON);
            }

            foreach ($result->errors as $error) {
                $this->logger->warning(sprintf('Back-in-stock push failed for customer %d: %s', $customerId, $error));
            }
        } catch (\Exception $exception) {
            $this->logger->error(
                'Back-in-stock push could not be sent: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }
}
