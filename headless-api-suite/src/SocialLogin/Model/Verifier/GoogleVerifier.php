<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Verifier;

/**
 * Sign in with Google.
 *
 * Google's ID tokens carry a full profile — `email`, `email_verified`, `given_name`, `family_name` —
 * on every sign-in, so nothing here has to be remembered between requests.
 */
class GoogleVerifier extends AbstractVerifier
{
    public const PROVIDER_CODE = 'google';

    /**
     * Both forms are legitimate. Google's discovery document names the bare host, and tokens issued
     * for some client types carry the `https://` form; a verifier that accepts only one rejects half
     * the valid traffic with a message that says nothing useful.
     */
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private const JWKS_URI = 'https://www.googleapis.com/oauth2/v3/certs';

    private const XML_PATH_CLIENT_ID = 'scr1be_social_login/google/client_id';

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
            // Google sends this as a JSON boolean; older client libraries have been known to send
            // the string "true". Both are accepted, and nothing else is.
            ($payload['email_verified'] ?? false) === true || ($payload['email_verified'] ?? null) === 'true',
            $this->nullableString($payload['given_name'] ?? null),
            $this->nullableString($payload['family_name'] ?? null)
        );
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function nullableString($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
