<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Data;

use Scr1be\PosBridge\Api\Data\CustomerMatchInterface;

/**
 * Immutable value object. Built once by the lookup service from a fetched row and never mutated,
 * which is why there are no setters and no `DataObject` base class to inherit any.
 */
class CustomerMatch implements CustomerMatchInterface
{
    public function __construct(
        private readonly int $customerId,
        private readonly string $name,
        private readonly ?string $email,
        private readonly ?string $billingName,
        private readonly ?string $billingTelephone,
        private readonly ?int $websiteId,
        private readonly int $groupId
    ) {
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getBillingName(): ?string
    {
        return $this->billingName;
    }

    public function getBillingTelephone(): ?string
    {
        return $this->billingTelephone;
    }

    public function getWebsiteId(): ?int
    {
        return $this->websiteId;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }
}
