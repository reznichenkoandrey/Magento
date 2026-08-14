<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push\Fcm;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The three fields of a Google service-account key file that this module uses, and the validation
 * that stops a half-pasted one reaching `openssl_sign()`.
 *
 * The whole file is pasted into a config field, so the failure everyone hits is a truncated private
 * key or the wrong JSON entirely. Failing here with a sentence naming the missing field is the
 * difference between a fixable mistake and `openssl_sign(): supplied key param cannot be coerced
 * into a private key` in an exception log.
 */
final class ServiceAccount
{
    /** Where a service-account key says to exchange a signed assertion for an access token. */
    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKey,
        public readonly string $tokenUri,
        public readonly string $projectId
    ) {
    }

    /**
     * @throws LocalizedException When the JSON is not a service-account key this module can use.
     */
    public static function fromJson(string $json, Json $serializer): self
    {
        if (trim($json) === '') {
            throw new LocalizedException(__('No Firebase service account is configured.'));
        }

        try {
            $decoded = $serializer->unserialize($json);
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException(__('The Firebase service account is not valid JSON.'), $exception);
        }

        if (!is_array($decoded)) {
            throw new LocalizedException(__('The Firebase service account is not valid JSON.'));
        }

        foreach (['client_email', 'private_key'] as $required) {
            if (!isset($decoded[$required]) || trim((string)$decoded[$required]) === '') {
                throw new LocalizedException(
                    __('The Firebase service account is missing "%1".', $required)
                );
            }
        }

        return new self(
            trim((string)$decoded['client_email']),
            // Pasting through a textarea turns the key file's "\n" escapes into two literal
            // characters often enough that handling it is cheaper than explaining it.
            str_replace('\n', "\n", (string)$decoded['private_key']),
            trim((string)($decoded['token_uri'] ?? self::DEFAULT_TOKEN_URI)) ?: self::DEFAULT_TOKEN_URI,
            trim((string)($decoded['project_id'] ?? ''))
        );
    }
}
