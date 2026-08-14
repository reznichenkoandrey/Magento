<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model\Fcm;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * The three fields this module needs out of a Google service-account JSON key.
 *
 * Parsed into a value object rather than passed around as an array so that a malformed key is
 * discovered once, at the boundary, with a reason — instead of as an "undefined index" three frames
 * into a signing routine.
 */
final class ServiceAccount
{
    /**
     * @param string $projectId
     * @param string $clientEmail The JWT's `iss` and `sub`.
     * @param string $privateKey PEM.
     */
    private function __construct(
        public readonly string $projectId,
        public readonly string $clientEmail,
        public readonly string $privateKey
    ) {
    }

    /**
     * Parse a service-account key, or explain why not.
     *
     * @param string $json
     * @param Json $serializer
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromJson(string $json, Json $serializer): self
    {
        if (trim($json) === '') {
            throw new \InvalidArgumentException('No service account key is configured.');
        }

        try {
            $decoded = $serializer->unserialize($json);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('The service account key is not valid JSON.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('The service account key is not a JSON object.');
        }

        foreach (['project_id', 'client_email', 'private_key'] as $field) {
            if (!isset($decoded[$field]) || !is_string($decoded[$field]) || trim($decoded[$field]) === '') {
                throw new \InvalidArgumentException(sprintf('The service account key has no "%s".', $field));
            }
        }

        return new self(
            $decoded['project_id'],
            $decoded['client_email'],
            // Google ships the key with literal "\n" sequences when it has been pasted through a
            // system that escapes newlines. OpenSSL will not parse a PEM without real line breaks,
            // and the error it gives ("error:0909006C") says nothing about why.
            str_replace('\\n', "\n", $decoded['private_key'])
        );
    }
}
