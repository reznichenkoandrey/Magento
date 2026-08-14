<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Resolver;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Scr1be\BackInStock\Model\AlertItem;
use Scr1be\BackInStock\Model\AlertItemProvider;
use Scr1be\BackInStock\Model\AlertScope;
use Scr1be\BackInStock\Model\AlertState;

/**
 * `Query.scr1beBackInStockAlerts` — the same list the account page renders, for a client that is not
 * a Magento storefront.
 *
 * **Why it returns skus and not images.** Everything about a product that depends on the storefront
 * — a resized image, a formatted currency, a price with the session's tax address applied — is
 * deliberately absent. A headless client asks `products(filter: {sku: {in: [...]}})` for that, where
 * core's own resolvers already do it correctly for the client's own store and currency. Duplicating
 * it here would produce a second, subtly different answer.
 *
 * **Why the scope is rebuilt rather than reused.** The customer group comes from the customer this
 * *token* identifies, not from a storefront session — `Magento\Catalog\Model\ResourceModel\Product\Collection::addPriceData()`
 * would otherwise price the response for whoever the session belongs to.
 */
class BackInStockAlerts implements ResolverInterface
{
    private const STATES = [
        AlertState::POPUP_IDLE => 'QUEUED',
        AlertState::POPUP_QUEUED => 'QUEUED',
        AlertState::POPUP_SHOWN => 'SHOWN',
    ];

    public function __construct(
        private readonly AlertItemProvider $provider,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @inheritdoc
     * @throws GraphQlAuthorizationException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        /** @var ContextInterface $context */
        if ($context->getExtensionAttributes()->getIsCustomer() === false) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $customerId = (int)$context->getUserId();
        $store = $context->getExtensionAttributes()->getStore();

        try {
            $groupId = (int)$this->customerRepository->getById($customerId)->getGroupId();
        } catch (NoSuchEntityException $exception) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'), $exception);
        }

        $scope = new AlertScope($customerId, $groupId, (int)$store->getId(), (int)$store->getWebsiteId());

        return array_map(
            static fn (AlertItem $item): array => [
                'product_sku' => (string)$item->product->getSku(),
                'product_name' => (string)$item->product->getName(),
                'product_url_key' => (string)$item->product->getData('url_key'),
                'subscribed_at' => $item->subscribedAt,
                'restocked_at' => $item->restockedAt,
                'state' => $item->alertStatus === AlertState::ALERT_ARMED
                    ? 'WAITING'
                    : (self::STATES[$item->popupStatus] ?? 'QUEUED'),
                'price' => $item->finalPrice,
                'is_salable' => $item->isSalable,
                'requires_options' => $item->requiresConfiguration,
                'badges' => $item->badges,
            ],
            $this->provider->getAll($scope)
        );
    }
}
