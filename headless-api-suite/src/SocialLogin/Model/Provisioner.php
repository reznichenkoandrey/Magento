<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Scr1be\SocialLogin\Model\ResourceModel\SocialLink;
use Scr1be\SocialLogin\Model\Verifier\IdentityClaims;

/**
 * Resolves a verified identity to a Magento customer, creating one if necessary.
 *
 * The ladder, in order, and each rung exists because skipping it breaks something concrete:
 *
 *  1. **`(provider, subject)` already linked** → that customer, full stop. This is the only rung
 *     that is an identity match rather than an inference, so it runs first and nothing overrides it.
 *     In particular it still holds when the provider has since changed the account's email.
 *  2. **No link, and the token carries no email** → refuse. There is nothing to create an account
 *     from and nothing to match against. Apple with a revoked scope reaches here.
 *  3. **No link, email belongs to an existing account on this website** → link and sign in. This is
 *     the "I registered with a password last year and today I tapped Sign in with Google" case, and
 *     it is why the module requires `email_verified`: without that check, any provider that lets a
 *     user assert an unverified address is an account-takeover route.
 *  4. **No link, no account** → create one with no password, then link.
 *  5. **Uniqueness race on the create** → somebody else created the account between rungs 3 and 4.
 *     Re-find and link, exactly as in rung 3.
 */
class Provisioner
{
    /**
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerInterfaceFactory $customerFactory
     * @param GroupManagementInterface $groupManagement
     * @param SocialLink $socialLink
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly GroupManagementInterface $groupManagement,
        private readonly SocialLink $socialLink,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param IdentityClaims $claims
     * @param StoreInterface $store
     * @return CustomerInterface
     * @throws SocialLoginException
     */
    public function resolve(IdentityClaims $claims, StoreInterface $store): CustomerInterface
    {
        $websiteId = (int)$store->getWebsiteId();

        $linkedId = $this->socialLink->findCustomerId($claims->provider, $claims->subject, $websiteId);
        if ($linkedId !== null) {
            try {
                $customer = $this->customerRepository->getById($linkedId);
                $this->socialLink->touch($claims->provider, $claims->subject, $websiteId);

                return $customer;
            } catch (NoSuchEntityException $e) {
                // The foreign key is ON DELETE CASCADE, so a link pointing at a deleted customer
                // should be impossible. If it happens, the database is not in the shape this module
                // assumes and guessing is worse than stopping.
                $this->logger->error(
                    sprintf(
                        'Scr1be_SocialLogin: link for %s/%s points at missing customer %d',
                        $claims->provider,
                        $claims->subject,
                        $linkedId
                    ),
                    ['exception' => $e]
                );

                throw new SocialLoginException(
                    SocialLoginException::ACCOUNT_CONFLICT,
                    new Phrase('This account could not be opened. Please contact support.'),
                    $e
                );
            }
        }

        if ($claims->email === null || !$claims->emailVerified) {
            throw new SocialLoginException(
                SocialLoginException::EMAIL_UNAVAILABLE,
                new Phrase('A verified email address is required to sign in this way.')
            );
        }

        $existing = $this->findByEmail($claims->email, $websiteId);
        if ($existing !== null) {
            $this->socialLink->link($claims->provider, $claims->subject, (int)$existing->getId(), $websiteId);

            return $existing;
        }

        return $this->create($claims, $store, $websiteId);
    }

    /**
     * @param IdentityClaims $claims
     * @param StoreInterface $store
     * @param int $websiteId
     * @return CustomerInterface
     * @throws SocialLoginException
     */
    private function create(IdentityClaims $claims, StoreInterface $store, int $websiteId): CustomerInterface
    {
        $customer = $this->customerFactory->create();
        $customer->setEmail((string)$claims->email);
        $customer->setFirstname($claims->firstName ?? $this->fallbackFirstName($claims));
        $customer->setLastname($claims->lastName ?? '-');
        $customer->setWebsiteId($websiteId);
        $customer->setStoreId((int)$store->getId());
        // Explicit rather than left to the model's default, because `CustomerRepository::save()`
        // only validates a group id it was given (`validateGroupId()` returns early on a falsy one)
        // and silently leaves the rest to whatever the column defaults to.
        $customer->setGroupId((int)$this->groupManagement->getDefaultGroup((int)$store->getId())->getId());

        try {
            // `save()` on the repository rather than `AccountManagementInterface::createAccount()`.
            // createAccount() sends a welcome email — and for an account created by tapping "Sign in
            // with Google", a "set your password" mail arrives as noise at best and as a phishing
            // lookalike at worst. The customer can still set a password later through the ordinary
            // forgotten-password flow, which is the same door.
            $created = $this->customerRepository->save($customer);
        } catch (AlreadyExistsException | InputMismatchException) {
            // Both, and the pair is not defensive padding. `CustomerRepository::save()` documents
            // `InputMismatchException` for a taken email, but it calls `$customerModel->save()`
            // without a try/catch, and `Magento\Customer\Model\ResourceModel\Customer::_beforeSave()`
            // raises `AlreadyExistsException` — so the exception that actually escapes is the one the
            // docblock does not name. `AccountManagement::createAccountWithPasswordHash()` is where
            // the documented translation happens, and this path deliberately does not go through it.
            //
            // Either way the meaning is the same: somebody created this email between the lookup and
            // the insert. Re-find and link, as in rung 3.
            $winner = $this->findByEmail((string)$claims->email, $websiteId);
            if ($winner === null) {
                throw new SocialLoginException(
                    SocialLoginException::ACCOUNT_CONFLICT,
                    new Phrase('An account with this email address already exists.')
                );
            }

            $this->socialLink->link($claims->provider, $claims->subject, (int)$winner->getId(), $websiteId);

            return $winner;
        } catch (\Exception $e) {
            $this->logger->error(
                'Scr1be_SocialLogin: could not create a customer from a verified identity: ' . $e->getMessage(),
                ['exception' => $e]
            );

            throw new SocialLoginException(
                SocialLoginException::ACCOUNT_CONFLICT,
                new Phrase('This account could not be created. Please contact support.'),
                $e
            );
        }

        $this->socialLink->link($claims->provider, $claims->subject, (int)$created->getId(), $websiteId);

        return $created;
    }

    /**
     * Magento requires a first name; Apple does not always supply one.
     *
     * The local part of the email is a better placeholder than the literal "Customer": it is what the
     * person recognises in a "Hello, …" line, and it is what they will change first if it is wrong.
     *
     * @param IdentityClaims $claims
     * @return string
     */
    private function fallbackFirstName(IdentityClaims $claims): string
    {
        $localPart = strstr((string)$claims->email, '@', true);

        return $localPart === false || $localPart === '' ? 'Customer' : $localPart;
    }

    /**
     * @param string $email
     * @param int $websiteId
     * @return CustomerInterface|null
     */
    private function findByEmail(string $email, int $websiteId): ?CustomerInterface
    {
        try {
            return $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
