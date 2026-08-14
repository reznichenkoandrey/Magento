<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Verifier;

use Magento\Framework\Phrase;
use Scr1be\SocialLogin\Model\SocialLoginException;

/**
 * Provider code → verifier, wired in di.xml.
 *
 * A pool rather than a `match` so that adding a third provider is a subclass and four lines of XML,
 * with nothing in this module to edit. The array is validated on construction rather than on use:
 * a mistyped class in di.xml should fail when the container is compiled, not on somebody's sign-in.
 */
class VerifierPool
{
    /**
     * @var array<string, VerifierInterface>
     */
    private array $verifiers;

    /**
     * @param VerifierInterface[] $verifiers
     * @throws \InvalidArgumentException
     */
    public function __construct(array $verifiers = [])
    {
        $byCode = [];
        foreach ($verifiers as $name => $verifier) {
            if (!$verifier instanceof VerifierInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Social login verifier "%s" must implement %s.', $name, VerifierInterface::class)
                );
            }
            $byCode[$verifier->getProviderCode()] = $verifier;
        }

        $this->verifiers = $byCode;
    }

    /**
     * @param string $providerCode
     * @param int $storeId
     * @return VerifierInterface
     * @throws SocialLoginException
     */
    public function get(string $providerCode, int $storeId): VerifierInterface
    {
        $verifier = $this->verifiers[$providerCode] ?? null;

        if ($verifier === null || !$verifier->isAvailable($storeId)) {
            // One message for "no such provider" and for "configured but switched off". Which of the
            // two it is tells an unauthenticated caller about the merchant's setup, and neither
            // answer changes what a legitimate client does next.
            throw new SocialLoginException(
                SocialLoginException::PROVIDER_UNAVAILABLE,
                new Phrase('This sign-in method is not available.')
            );
        }

        return $verifier;
    }

    /**
     * The provider codes a client may currently use in this store.
     *
     * @param int $storeId
     * @return string[]
     */
    public function getAvailableCodes(int $storeId): array
    {
        $codes = [];
        foreach ($this->verifiers as $code => $verifier) {
            if ($verifier->isAvailable($storeId)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
