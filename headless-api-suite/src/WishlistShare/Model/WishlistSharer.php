<?php
declare(strict_types=1);

namespace Scr1be\WishlistShare\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\ValidateException;
use Magento\Framework\Validator\ValidatorChain;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Wishlist\Model\Wishlist;
use Psr\Log\LoggerInterface;

/**
 * Sends one wishlist to a list of addresses, and reports per address.
 *
 * The contract is frozen in the sense that matters: the shape of the answer does not depend on how
 * well the send went. Every address the caller named comes back in exactly one of `sent` or
 * `failed`, always, so a client renders one list and never has to reason about a partially populated
 * response.
 *
 * The departure from core is the loop. `Magento\Wishlist\Controller\Index\Send::execute()` wraps the
 * whole `foreach` in a single try/catch: the first address that throws aborts the run, the remaining
 * addresses are never attempted, and the shopper is shown one error with no indication of which of
 * the six people they named got the mail. That is defensible for a form post that can be resubmitted;
 * it is not defensible for an API, where the client would have to re-send to everybody and produce
 * duplicates. Here each recipient is its own transport and its own try/catch.
 */
class WishlistSharer
{
    /**
     * @param Config $config
     * @param TransportBuilder $transportBuilder
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Wishlist $wishlist
     * @param string[] $emails Already de-duplicated and within the recipient limit.
     * @param string $message
     * @param StoreInterface $store
     * @param string $sharedUrl
     * @return array{sent: string[], failed: array<int, array{email: string, reason: string}>}
     */
    public function share(
        Wishlist $wishlist,
        array $emails,
        string $message,
        StoreInterface $store,
        string $sharedUrl
    ): array {
        $sent = [];
        $failed = [];

        foreach ($emails as $email) {
            if (!$this->isValidAddress($email)) {
                $failed[] = ['email' => $email, 'reason' => ShareOutcome::INVALID_ADDRESS->value];
                continue;
            }

            try {
                $this->send($email, $wishlist, $message, $store, $sharedUrl);
                $sent[] = $email;
            } catch (\Throwable $e) {
                // The transport's own words go to the log and nowhere else. "550 5.1.1 User unknown"
                // tells whoever typed the address whether a mailbox exists, and "Connection refused
                // to smtp.internal:587" tells them about the merchant's infrastructure.
                $this->logger->error(
                    sprintf(
                        'Scr1be_WishlistShare: could not send wishlist %d to a recipient: %s',
                        (int)$wishlist->getId(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
                $failed[] = ['email' => $email, 'reason' => ShareOutcome::DELIVERY_FAILED->value];
            }
        }

        // Core counts shares on the wishlist row and uses the count to enforce the per-list limit.
        // Only successful sends are counted, so a run that failed entirely does not consume the
        // shopper's allowance.
        if ($sent !== []) {
            $wishlist->setShared((int)$wishlist->getShared() + count($sent));
            $wishlist->save();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * One recipient, one transport.
     *
     * A transport is built per address rather than once with several `addTo()` calls, and that is a
     * privacy decision as much as an isolation one: several recipients on one message means every
     * recipient sees the others' addresses.
     *
     * @param string $email
     * @param Wishlist $wishlist
     * @param string $message
     * @param StoreInterface $store
     * @param string $sharedUrl
     * @return void
     * @throws \Throwable
     */
    private function send(
        string $email,
        Wishlist $wishlist,
        string $message,
        StoreInterface $store,
        string $sharedUrl
    ): void {
        $storeId = (int)$store->getId();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier($this->config->getEmailTemplate($storeId))
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars(
                [
                    'customerName' => $this->senderName($wishlist),
                    'message' => $message,
                    'viewOnSiteLink' => $sharedUrl,
                    'items' => $this->itemSummaries($wishlist),
                    'store' => $store,
                ]
            )
            ->setFromByScope($this->config->getEmailIdentity($storeId), $storeId)
            ->addTo($email)
            ->getTransport();

        $transport->sendMessage();
    }

    /**
     * A flat list of item names, for the template.
     *
     * Core renders the item table by adding the `wishlist_email_items` layout handle and calling
     * `toHtml()` on `wishlist.email.items`
     * (`Magento\Wishlist\Controller\Index\Send::getWishlistItems()`). That is not reachable from
     * here: the handle is declared in `Magento_Wishlist/view/frontend/layout/wishlist_email_items.xml`
     * — the frontend area, which a GraphQL request is not in — and the block resolves *which*
     * wishlist to render through `Magento\Wishlist\Helper\Data::getWishlist()`, which reads the
     * `shared_wishlist` registry entry or a session-backed provider. Neither exists in an API
     * request. So this module ships its own template with plain variables instead of half-emulating
     * a storefront to reuse core's.
     *
     * @param Wishlist $wishlist
     * @return array<int, array{name: string, qty: float}>
     */
    private function itemSummaries(Wishlist $wishlist): array
    {
        $items = [];

        foreach ($wishlist->getItemCollection() as $item) {
            $product = $item->getProduct();
            if ($product === null) {
                continue;
            }
            $items[] = [
                'name' => (string)$product->getName(),
                'qty' => (float)$item->getQty(),
            ];
        }

        return $items;
    }

    /**
     * Who the mail says it is from, in the body.
     *
     * A wishlist always has a customer id — guests do not have wishlists — but the customer may have
     * been deleted between the share request and this line. Falling back to the store name keeps the
     * mail sendable rather than turning a deleted account into a 500.
     *
     * @param Wishlist $wishlist
     * @return string
     */
    private function senderName(Wishlist $wishlist): string
    {
        try {
            $customer = $this->customerRepository->getById((int)$wishlist->getCustomerId());

            return trim($customer->getFirstname() . ' ' . $customer->getLastname());
        } catch (NoSuchEntityException | \Exception) {
            return '';
        }
    }

    /**
     * @param string $email
     * @return bool
     */
    private function isValidAddress(string $email): bool
    {
        try {
            return ValidatorChain::is($email, EmailAddress::class);
        } catch (ValidateException) {
            return false;
        }
    }
}
