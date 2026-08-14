<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

/**
 * Resolves a link type's `position` attribute id, which both the writer and the storefront reader
 * need and neither should look up for itself.
 *
 * The id is an auto-increment in `catalog_product_link_attribute` — unlike the link type ids it is
 * never reserved, because nothing addresses it by constant. It is stable for the life of an
 * installation, so it is memoised per request rather than per query.
 */
class LinkPositionAttribute
{
    private const LINK_ATTRIBUTE_TABLE = 'catalog_product_link_attribute';

    /**
     * @var array<int, int>
     */
    private array $ids = [];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @throws LocalizedException when the install patch has not run, or its row was removed
     */
    public function getId(int $linkTypeId): int
    {
        if (isset($this->ids[$linkTypeId])) {
            return $this->ids[$linkTypeId];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::LINK_ATTRIBUTE_TABLE), ['product_link_attribute_id'])
            ->where('link_type_id = ?', $linkTypeId)
            ->where('product_link_attribute_code = ?', FamilyLinkType::POSITION_ATTRIBUTE_CODE);

        $attributeId = (int)$connection->fetchOne($select);
        if ($attributeId <= 0) {
            throw new LocalizedException(
                __(
                    'Link type %1 has no "%2" attribute. Run bin/magento setup:upgrade to install it.',
                    $linkTypeId,
                    FamilyLinkType::POSITION_ATTRIBUTE_CODE
                )
            );
        }

        return $this->ids[$linkTypeId] = $attributeId;
    }
}
