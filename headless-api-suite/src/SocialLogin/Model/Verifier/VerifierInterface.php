<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Verifier;

use Scr1be\SocialLogin\Model\SocialLoginException;

/**
 * One OIDC provider.
 *
 * @api
 */
interface VerifierInterface
{
    /**
     * The provider code, as stored in `scr1be_social_login_link.provider` and sent by the client.
     *
     * @return string
     */
    public function getProviderCode(): string;

    /**
     * Whether this provider is configured and switched on for the given store.
     *
     * @param int $storeId
     * @return bool
     */
    public function isAvailable(int $storeId): bool;

    /**
     * Verify an ID token and return what it says, or throw.
     *
     * @param string $idToken
     * @param int $storeId
     * @return IdentityClaims
     * @throws SocialLoginException
     */
    public function verify(string $idToken, int $storeId): IdentityClaims;
}
