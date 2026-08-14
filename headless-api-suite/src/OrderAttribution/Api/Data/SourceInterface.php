<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Api\Data;

/**
 * One row of the attribution source registry.
 *
 * @api
 */
interface SourceInterface
{
    public const SOURCE_ID = 'source_id';
    public const CODE = 'code';
    public const LABEL = 'label';
    public const IS_ACTIVE = 'is_active';
    public const SORT_ORDER = 'sort_order';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * @return int|null
     */
    public function getSourceId(): ?int;

    /**
     * @param int|null $sourceId
     * @return $this
     */
    public function setSourceId(?int $sourceId): self;

    /**
     * The code a client sends and an order stores.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self;

    /**
     * @return string
     */
    public function getLabel(): string;

    /**
     * @param string $label
     * @return $this
     */
    public function setLabel(string $label): self;

    /**
     * Whether new orders may be attributed to this source.
     *
     * Inactive is not deleted: existing orders keep pointing at the code, and the grid keeps
     * rendering its label.
     *
     * @return bool
     */
    public function isActive(): bool;

    /**
     * @param bool $isActive
     * @return $this
     */
    public function setIsActive(bool $isActive): self;

    /**
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * @param int $sortOrder
     * @return $this
     */
    public function setSortOrder(int $sortOrder): self;
}
