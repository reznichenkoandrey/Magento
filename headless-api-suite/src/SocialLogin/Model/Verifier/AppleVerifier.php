<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Verifier;

/**
 * Sign in with Apple.
 *
 * Two differences from Google that the shared base cannot hide, and both have bitten real
 * integrations:
 *
 *  - **The name is not in the token.** Apple returns `givenName`/`familyName` in the authorization
 *    response *once*, on the very first authorisation, and never again. So `toClaims()` returns nulls
 *    for the name and the mutation accepts them as separate arguments instead. A client that does not
 *    forward them on that first call has lost them, which is Apple's design and not something this
 *    module can paper over.
 *  - **The email may be a relay address.** With "Hide My Email", `email` is a
 *    `…@privaterelay.appleid.com` address that forwards to the real one. It is a perfectly good
 *    account identifier and a perfectly good delivery address, and it must not be treated as
 *    second-class — but it also means email is even less of an identity here than usual, which is
 *    why the link table keys on `sub`.
 */
class AppleVerifier extends AbstractVerifier
{
    public const PROVIDER_CODE = 'apple';

    private const ISSUERS = ['https://appleid.apple.com'];

    private const JWKS_URI = 'https://appleid.apple.com/auth/keys';

    private const XML_PATH_CLIENT_ID = 'scr1be_social_login/apple/client_id';

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return self::PROVIDER_CODE;
    }

    /**
     * @inheritDoc
     */
    protected function getJwksUri(): string
    {
        return self::JWKS_URI;
    }

    /**
     * @inheritDoc
     */
    protected function getAllowedIssuers(): array
    {
        return self::ISSUERS;
    }

    /**
     * @inheritDoc
     */
    protected function getClientIdConfigPath(): string
    {
        return self::XML_PATH_CLIENT_ID;
    }

    /**
     * @inheritDoc
     */
    protected function toClaims(array $payload): IdentityClaims
    {
        $email = isset($payload['email']) ? strtolower(trim((string)$payload['email'])) : '';

        return new IdentityClaims(
            self::PROVIDER_CODE,
            (string)$payload['sub'],
            $email === '' ? null : $email,
            // Apple sends `email_verified` as the *string* "true" for relay addresses and as a
            // boolean for others. Both spellings mean verified.
            ($payload['email_verified'] ?? false) === true || ($payload['email_verified'] ?? null) === 'true',
            null,
            null
        );
    }
}
