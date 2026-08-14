<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Scr1be\PushNotifications\Model\OrderNotifier;
use Scr1be\PushNotifications\Model\ResourceModel\DeviceRegistry;

/**
 * `setCartDeviceToken` — register the device and pin it to the cart in one call.
 *
 * Attaching the device at cart time rather than at order time is what makes a guest checkout
 * notifiable. By the time the order exists there is no request from the app any more — the order may
 * be placed by a payment callback — and a guest order has no customer id to look devices up by. The
 * cart is the last point at which the app is definitely on the other end of the connection.
 *
 * The cart stores the *hash*, not the token, for the same reason the registry keys on one: the token
 * is a credential that lets its holder push arbitrary notifications to that device, and there is no
 * reason for a second copy of it to live on a table that is dumped into every staging environment.
 */
class SetCartDeviceToken implements ResolverInterface
{
    /**
     * What an app may claim to be. Anything else is refused rather than stored, so the column stays
     * groupable and a client cannot fill it with free text.
     */
    private const PLATFORMS = ['ios', 'android', 'web'];

    /**
     * FCM tokens are long and the vendors have lengthened them before. The bound is a sanity check
     * against a client posting a megabyte, not a format assertion.
     */
    private const MAX_TOKEN_LENGTH = 4096;

    /**
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     * @param CartRepositoryInterface $cartRepository
     * @param DeviceRegistry $registry
     */
    public function __construct(
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly DeviceRegistry $registry
    ) {
    }

    /**
     * @inheritDoc
     * @throws GraphQlInputException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $maskedCartId = trim((string)($args['input']['cart_id'] ?? ''));
        $token = trim((string)($args['input']['device_token'] ?? ''));
        $platform = strtolower(trim((string)($args['input']['platform'] ?? '')));

        if ($maskedCartId === '' || $token === '') {
            throw new GraphQlInputException(__('A cart id and a device token are required.'));
        }
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new GraphQlInputException(__('The device token is not valid.'));
        }
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new GraphQlInputException(
                __('The platform must be one of: %1.', implode(', ', self::PLATFORMS))
            );
        }

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = $store instanceof StoreInterface ? (int)$store->getId() : 0;
        $customerId = (int)$context->getUserId();

        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedCartId);
            /** @var Quote $quote */
            $quote = $this->cartRepository->get($quoteId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new GraphQlInputException(__('Could not find a cart with ID "%1".', $maskedCartId), $e);
        }

        // The cart's own ownership rule, not this module's: a signed-in caller may only address their
        // own cart, and an anonymous caller may only address a guest cart. Without this, a masked id
        // guessed or leaked from another session could be tagged with the caller's device.
        if ((int)$quote->getCustomerId() !== $customerId) {
            throw new GraphQlInputException(__('Could not find a cart with ID "%1".', $maskedCartId));
        }

        $hash = $this->registry->register($token, $customerId ?: null, $storeId, $platform);
        $quote->setData(OrderNotifier::FIELD_DEVICE_TOKEN_HASH, $hash);
        $this->cartRepository->save($quote);

        return ['success' => true];
    }
}
