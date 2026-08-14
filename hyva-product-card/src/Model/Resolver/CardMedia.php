<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Scr1be\HyvaProductCard\Model\Card\MediaResolver;

/**
 * `ProductInterface.card_media`.
 *
 * The image id is fixed rather than exposed as an argument. `Helper\Image::init()` merges
 * `getConfigView()->getMediaAttributes('Magento_Catalog', 'images', $imageId)` over what the caller
 * passes, and an id absent from the resolved theme's `view.xml` yields no `type`, which leaves the
 * image model without a destination subdir and produces a URL for nothing. A client-supplied id
 * would turn that into a 200-with-garbage on a typo. `category_page_grid` is present in
 * `Magento/blank`, `Magento/luma` and Hyvä's default theme — a card asking for a card-sized image
 * is the whole use case, and integrators who need another size change it in `di.xml`.
 */
class CardMedia implements ResolverInterface
{
    private const DEFAULT_IMAGE_ID = 'category_page_grid';

    public function __construct(
        private readonly MediaResolver $mediaResolver,
        private readonly string $imageId = self::DEFAULT_IMAGE_ID
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $product = $value['model'] ?? null;
        if (!$product instanceof Product) {
            throw new LocalizedException(__('"model" value should be specified'));
        }

        return $this->mediaResolver->resolve($product, $this->imageId)->toArray();
    }
}
