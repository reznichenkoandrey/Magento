<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\Phrase;

/**
 * One exception with a closed set of codes, rather than one exception class per failure.
 *
 * The codes reach the client in `extensions.code`. `GraphQL\Error\Error::__construct()` copies
 * `getExtensions()` from a previous exception that implements `ProvidesExtensions`, and
 * `GraphQlInputException` implements it, so overriding the method here is all it takes.
 *
 * The messages are deliberately uninformative about *which* check failed. A sign-in endpoint that
 * distinguishes "unknown key id", "wrong audience" and "expired" for an unauthenticated caller is
 * describing the shape of its verification to whoever is probing it. The operator gets the detail in
 * the log; the client gets a code it can branch on and a sentence it can show.
 */
class SocialLoginException extends GraphQlInputException
{
    /** The provider is not configured, or is switched off for this store. */
    public const PROVIDER_UNAVAILABLE = 'SOCIAL_PROVIDER_UNAVAILABLE';

    /** The ID token did not survive verification, for any reason. */
    public const INVALID_TOKEN = 'SOCIAL_INVALID_TOKEN';

    /** The token verified, but carries no email — so no account can be provisioned. */
    public const EMAIL_UNAVAILABLE = 'SOCIAL_EMAIL_UNAVAILABLE';

    /** The provider's key endpoint could not be reached. Retryable. */
    public const KEYS_UNAVAILABLE = 'SOCIAL_KEYS_UNAVAILABLE';

    /** An account exists for the email but could not be linked. */
    public const ACCOUNT_CONFLICT = 'SOCIAL_ACCOUNT_CONFLICT';

    /**
     * Named `errorCode`, not `code`: `\Exception` already declares a protected `$code`, and PHP
     * refuses to narrow an inherited property to private.
     *
     * @param string $errorCode One of the class constants.
     * @param Phrase $message
     * @param \Exception|null $cause
     */
    public function __construct(
        private readonly string $errorCode,
        Phrase $message,
        ?\Exception $cause = null
    ) {
        parent::__construct($message, $cause);
    }

    /**
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @inheritDoc
     */
    public function getExtensions(): array
    {
        return parent::getExtensions() + ['code' => $this->errorCode];
    }
}
