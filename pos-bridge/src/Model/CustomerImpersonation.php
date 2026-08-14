<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Integration\Api\Exception\UserTokenException;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Model\CustomUserContextFactory;
use Scr1be\PosBridge\Api\CustomerImpersonationInterface;
use Scr1be\PosBridge\Api\Data\ImpersonationTokenInterface;
use Scr1be\PosBridge\Api\Data\ImpersonationTokenInterfaceFactory;

/**
 * Mints a customer token for a customer the terminal has already identified.
 *
 * **Why core's token framework and not a token of our own.** `Magento\Integration\Api\TokenManager`
 * is the composition core's own `CustomerTokenService::createCustomerAccessToken()` uses: it pairs
 * `UserTokenIssuerInterface` with a `UserTokenParameters` instance. On a stock 2.4.8 install
 * `Magento_JwtUserToken` binds that interface to `Magento\JwtUserToken\Model\Issuer`, which writes
 * `uid` and `utypid` claims and an `exp` derived from the customer TTL. Calling the same seam means
 * this module issues the *same* credential a password login issues — one that every existing
 * validator, every revocation path and every downstream endpoint already understands. A bespoke
 * token would have needed all of that reimplemented, and would have been the first thing to rot.
 *
 * **Where the difference from a login lives.** The one thing this endpoint skips is the password,
 * so everything a password check would otherwise have enforced has to be enforced here instead. That
 * is what the ladder below is: a locked-out account and an unconfirmed account are both accounts
 * core refuses to authenticate, and an impersonation endpoint that ignored them would be a documented
 * way around the lockout policy rather than a convenience for the till.
 */
class CustomerImpersonation implements CustomerImpersonationInterface
{
    private const REASON_DISABLED = 'impersonation disabled';
    private const REASON_LOCKED = 'account locked';
    private const REASON_UNCONFIRMED = 'account awaiting confirmation';
    private const REASON_UNREADABLE_TOKEN = 'minted token could not be read back';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AuthenticationInterface $authentication,
        private readonly AccountManagementInterface $accountManagement,
        private readonly TokenManager $tokenManager,
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly CustomUserContextFactory $userContextFactory,
        private readonly ImpersonationTokenInterfaceFactory $tokenFactory,
        private readonly ImpersonationLog $log
    ) {
    }

    /**
     * @inheritDoc
     */
    public function issueToken(int $customerId): ImpersonationTokenInterface
    {
        if (!$this->config->isImpersonationEnabled()) {
            $this->log->refused($customerId, self::REASON_DISABLED);

            throw new LocalizedException(new Phrase('Customer impersonation is switched off.'));
        }

        // NoSuchEntityException, which the REST layer renders as a 404.
        $customer = $this->customerRepository->getById($customerId);
        $this->assertActable($customer);

        $token = $this->tokenManager->create(
            $this->userContextFactory->create([
                'userId' => (int) $customer->getId(),
                'userType' => UserContextInterface::USER_TYPE_CUSTOMER,
            ]),
            $this->tokenManager->createUserTokenParameters()
        );

        $expiresAt = $this->readExpiry($token, $customerId);
        $this->log->issued($customerId, $expiresAt);

        return $this->tokenFactory->create([
            'customerId' => (int) $customer->getId(),
            'token' => $token,
            'expiresAt' => $expiresAt,
        ]);
    }

    /**
     * The two states core would refuse a password for.
     *
     * Both refusals carry the same neutral message. The endpoint is behind an admin ACL so there is
     * no attacker to leak an account's state to, but a message that names the reason is a message an
     * operator will read out across a counter, and neither reason is the shopper's business.
     */
    private function assertActable(CustomerInterface $customer): void
    {
        $customerId = (int) $customer->getId();

        if ($this->authentication->isLocked($customerId)) {
            $this->log->refused($customerId, self::REASON_LOCKED);

            throw new LocalizedException(new Phrase('This account cannot be used right now.'));
        }

        $status = $this->accountManagement->getConfirmationStatus($customerId);
        if ($status === AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED) {
            $this->log->refused($customerId, self::REASON_UNCONFIRMED);

            throw new LocalizedException(new Phrase('This account cannot be used right now.'));
        }
    }

    /**
     * The expiry is read back out of the minted token rather than recomputed from the TTL setting.
     *
     * Recomputing would mean holding a second copy of core's expiry rule — the customer TTL, its
     * fallback and its unit — and a copy that drifts reports an expiry the token does not have,
     * which is worse than reporting none.
     *
     * What the read actually costs is worth stating rather than guessing at. `UserTokenReaderInterface`
     * is bound to `Magento\Integration\Model\CompositeTokenReader`, which tries each configured reader
     * in turn and returns the first that succeeds; a stock install configures two — the opaque-token
     * reader from `Magento_Integration` and `Magento\JwtUserToken\Model\Reader`. The opaque reader
     * loads a row from the token table and throws when it finds none, so reading a JWT back can cost
     * one indexed lookup before the JWT reader answers. That is a fixed, small price paid once per
     * issued token, and it buys an expiry that is read off the credential rather than re-derived.
     *
     * The read is not an expiry or revocation check: those are separate validators applied when a
     * token is *used*, so a `UserTokenException` here means the token could not be parsed at all —
     * which is why it is treated as a failure to issue rather than as a refusal.
     */
    private function readExpiry(string $token, int $customerId): string
    {
        try {
            $expires = $this->tokenReader->read($token)->getData()->getExpires();
        } catch (UserTokenException $error) {
            $this->log->refused($customerId, self::REASON_UNREADABLE_TOKEN);

            throw new LocalizedException(
                new Phrase('The impersonation token could not be issued.'),
                $error
            );
        }

        return $expires->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
