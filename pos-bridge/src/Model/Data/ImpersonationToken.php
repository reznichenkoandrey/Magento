<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Data;

use Scr1be\PosBridge\Api\Data\ImpersonationTokenInterface;

class ImpersonationToken implements ImpersonationTokenInterface
{
    public function __construct(
        private readonly int $customerId,
        private readonly string $token,
        private readonly string $expiresAt
    ) {
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }
}
