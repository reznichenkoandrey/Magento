<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Verifier;

/**
 * What a verified ID token tells us about the person holding it.
 *
 * Deliberately small. A provider's ID token carries a dozen claims; the only ones that may influence
 * an account are the four here, and widening this object is how a login module quietly turns into a
 * profile-sync module.
 */
final class IdentityClaims
{
    /**
     * @param string $provider
     * @param string $subject The `sub` claim — the only stable identifier a provider guarantees.
     * @param string|null $email
     * @param bool $emailVerified
     * @param string|null $firstName
     * @param string|null $lastName
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $firstName,
        public readonly ?string $lastName
    ) {
    }
}
