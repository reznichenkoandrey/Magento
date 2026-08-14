<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * What a signed URL says about itself.
 *
 * Short keys because this is serialised into a query string and every byte is one the customer may
 * end up pasting into a support ticket. The version is first so a future format can be recognised
 * before anything else is trusted.
 *
 * Nothing in here is an authorization. It records *what was asked for and by whom*, so the download
 * controller can re-run the same authorization rather than take the token's word for it.
 */
final class TokenPayload
{
    public const VERSION = 1;

    private const KEY_VERSION = 'v';
    private const KEY_TYPE = 't';
    private const KEY_UID = 'd';
    private const KEY_CUSTOMER = 'c';
    private const KEY_STORE = 's';
    private const KEY_EXPIRES = 'x';
    private const KEY_NONCE = 'n';

    public function __construct(
        public readonly DocumentType $type,
        public readonly string $uid,
        public readonly int $customerId,
        public readonly int $storeId,
        public readonly int $expiresAt,
        public readonly string $nonce
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            self::KEY_VERSION => self::VERSION,
            self::KEY_TYPE => $this->type->value,
            self::KEY_UID => $this->uid,
            self::KEY_CUSTOMER => $this->customerId,
            self::KEY_STORE => $this->storeId,
            self::KEY_EXPIRES => $this->expiresAt,
            self::KEY_NONCE => $this->nonce,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self|null Null for anything that is not a well-formed payload of a version we know
     */
    public static function fromArray(array $data): ?self
    {
        if (($data[self::KEY_VERSION] ?? null) !== self::VERSION) {
            return null;
        }

        $type = DocumentType::tryFrom((string) ($data[self::KEY_TYPE] ?? ''));
        $uid = $data[self::KEY_UID] ?? null;
        $customerId = $data[self::KEY_CUSTOMER] ?? null;
        $storeId = $data[self::KEY_STORE] ?? null;
        $expiresAt = $data[self::KEY_EXPIRES] ?? null;
        $nonce = $data[self::KEY_NONCE] ?? null;

        if ($type === null
            || !is_string($uid) || $uid === ''
            || !is_int($customerId) || $customerId <= 0
            || !is_int($storeId) || $storeId < 0
            || !is_int($expiresAt)
            || !is_string($nonce) || $nonce === ''
        ) {
            return null;
        }

        return new self($type, $uid, $customerId, $storeId, $expiresAt, $nonce);
    }
}
