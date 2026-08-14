<?php
declare(strict_types=1);

namespace Scr1be\WishlistShare\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Wishlist\Model\ResourceModel\Wishlist as WishlistResource;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\Wishlist\Config as WishlistModuleConfig;
use Magento\Wishlist\Model\WishlistFactory;
use Scr1be\WishlistShare\Model\Config;
use Scr1be\WishlistShare\Model\WishlistSharer;

/**
 * `shareWishlist(input: { wishlist_id, emails, message })`.
 *
 * **The ownership check is one branch on purpose.** A wishlist that does not exist and a wishlist
 * belonging to somebody else produce the same exception with the same message. Splitting them —
 * "no such wishlist" versus "not yours" — turns the mutation into an oracle: walk the id space, and
 * the two different errors tell you exactly which ids are real. The whole point of an id-based
 * lookup behind authentication is that a caller learns nothing about ids they do not own, and that
 * property survives only if the failure modes are indistinguishable.
 *
 * **The validation happens before anything is sent.** Recipient count and message length are checked
 * up front, and both use core's own limits (`wishlist/email/number_limit`, `wishlist/email/text_limit`)
 * so the API and the storefront form agree.
 */
class ShareWishlist implements ResolverInterface
{
    /**
     * @param WishlistFactory $wishlistFactory
     * @param WishlistResource $wishlistResource
     * @param WishlistModuleConfig $wishlistModuleConfig
     * @param WishlistSharer $sharer
     * @param Config $config
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly WishlistFactory $wishlistFactory,
        private readonly WishlistResource $wishlistResource,
        private readonly WishlistModuleConfig $wishlistModuleConfig,
        private readonly WishlistSharer $sharer,
        private readonly Config $config,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @inheritDoc
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->wishlistModuleConfig->isEnabled()) {
            throw new GraphQlInputException(__('The wishlist configuration is currently disabled.'));
        }

        $customerId = (int)$context->getUserId();
        if ($customerId === 0) {
            throw new GraphQlAuthorizationException(
                __('The current user cannot perform operations on wishlist')
            );
        }

        $wishlist = $this->loadOwned((int)($args['input']['wishlist_id'] ?? 0), $customerId);
        $emails = $this->normaliseRecipients($args['input']['emails'] ?? []);
        $message = $this->normaliseMessage((string)($args['input']['message'] ?? ''));

        $store = $context->getExtensionAttributes()->getStore();
        if (!$store instanceof StoreInterface) {
            throw new GraphQlInputException(__('The store could not be determined for this request.'));
        }

        $sharedUrl = $this->sharedUrl($wishlist, $store);
        $result = $this->sharer->share($wishlist, $emails, $message, $store, $sharedUrl);

        return [
            'wishlist_id' => (int)$wishlist->getId(),
            'shared_url' => $sharedUrl,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ];
    }

    /**
     * Load the wishlist, or fail in a way that reveals nothing.
     *
     * @param int $wishlistId
     * @param int $customerId
     * @return Wishlist
     * @throws GraphQlNoSuchEntityException
     */
    private function loadOwned(int $wishlistId, int $customerId): Wishlist
    {
        /** @var Wishlist $wishlist */
        $wishlist = $this->wishlistFactory->create();

        if ($wishlistId > 0) {
            $this->wishlistResource->load($wishlist, $wishlistId);
        } else {
            // No id means "my wishlist", which is what a single-list storefront always wants.
            $this->wishlistResource->load($wishlist, $customerId, 'customer_id');
        }

        if ($wishlist->getId() === null || (int)$wishlist->getCustomerId() !== $customerId) {
            // One message for both cases. See the class docblock.
            throw new GraphQlNoSuchEntityException(__('The wish list could not be found.'));
        }

        return $wishlist;
    }

    /**
     * @param mixed $emails
     * @return string[]
     * @throws GraphQlInputException
     */
    private function normaliseRecipients($emails): array
    {
        if (!is_array($emails)) {
            throw new GraphQlInputException(__('At least one email address is required.'));
        }

        $normalised = [];
        foreach ($emails as $email) {
            $email = trim((string)$email);
            if ($email !== '') {
                $normalised[] = $email;
            }
        }

        // De-duplicated case-insensitively: sending the same person two copies because they were
        // typed as `Ada@` and `ada@` is a bug the recipient notices and the shopper does not.
        $normalised = array_values(
            array_intersect_key($normalised, array_unique(array_map('mb_strtolower', $normalised)))
        );

        if ($normalised === []) {
            throw new GraphQlInputException(__('At least one email address is required.'));
        }

        $limit = $this->config->getRecipientLimit();
        if (count($normalised) > $limit) {
            throw new GraphQlInputException(__('A maximum of %1 email addresses can be used.', $limit));
        }

        return $normalised;
    }

    /**
     * @param string $message
     * @return string
     * @throws GraphQlInputException
     */
    private function normaliseMessage(string $message): string
    {
        $message = trim($message);
        $limit = $this->config->getMessageLimit();

        if (mb_strlen($message) > $limit) {
            throw new GraphQlInputException(__('The message cannot be longer than %1 characters.', $limit));
        }

        return $message;
    }

    /**
     * The public link the recipients follow.
     *
     * `sharing_code` is generated on demand and persisted, because
     * `Magento\Wishlist\Model\Wishlist::generateSharingCode()` is called from the storefront path and
     * a wishlist that has only ever been used through the API has none.
     *
     * The URL is built with `UrlInterface` and an explicit `_scope`, not by concatenating a base URL.
     * `Magento\Framework\Url::getUrl()` treats `_scope` as a reserved route parameter and calls
     * `setScope()` with it, which is what makes the link point at the store the `Store` header named
     * rather than at whichever store the request happens to have resolved as current. Concatenating
     * would also skip the store code that a multi-store install puts in the path.
     *
     * @param Wishlist $wishlist
     * @param StoreInterface $store
     * @return string
     * @throws GraphQlInputException
     */
    private function sharedUrl(Wishlist $wishlist, StoreInterface $store): string
    {
        if (!$wishlist->getSharingCode()) {
            $wishlist->generateSharingCode();
            try {
                $wishlist->save();
            } catch (LocalizedException $e) {
                throw new GraphQlInputException(__('The wish list could not be shared.'), $e);
            }
        }

        return $this->url->getUrl(
            'wishlist/shared/index',
            [
                'code' => (string)$wishlist->getSharingCode(),
                '_scope' => (int)$store->getId(),
                '_nosid' => true,
            ]
        );
    }
}
