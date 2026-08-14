<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model\Verifier;

use Scr1be\SocialLogin\Model\Verifier\AbstractVerifier;
use Scr1be\SocialLogin\Model\Verifier\IdentityClaims;

/**
 * The smallest possible concrete verifier, so `AbstractVerifierTest` exercises the shared validation
 * rather than Google's or Apple's particular claim names.
 *
 * A real file rather than an anonymous class: PHPUnit's `--strict-coverage` and the PSR-4 autoloader
 * both want test helpers to be findable, and an anonymous class inside the test method would be
 * re-declared per test run under some PHP versions.
 */
class TestableVerifier extends AbstractVerifier
{
    public const PROVIDER_CODE = 'testable';

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
        return 'https://issuer.example.com/keys';
    }

    /**
     * @inheritDoc
     */
    protected function getAllowedIssuers(): array
    {
        return ['https://issuer.example.com'];
    }

    /**
     * @inheritDoc
     */
    protected function getClientIdConfigPath(): string
    {
        return 'scr1be_social_login/testable/client_id';
    }

    /**
     * @inheritDoc
     */
    protected function toClaims(array $payload): IdentityClaims
    {
        return new IdentityClaims(
            self::PROVIDER_CODE,
            (string)$payload['sub'],
            isset($payload['email']) ? (string)$payload['email'] : null,
            ($payload['email_verified'] ?? false) === true,
            null,
            null
        );
    }
}
