<?php
declare(strict_types=1);

namespace Scr1be\MegaMenuAttributes\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\MegaMenuAttributes\Setup\Patch\Data\AddMegamenuFeaturedImageAttribute;

class CategoryFeaturedImage implements ArgumentInterface
{
    private const MEDIA_PATH = 'catalog/category';

    /** @var array<int, string|null> */
    private array $cache = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function getFeaturedImageUrl(int $categoryId): ?string
    {
        if (array_key_exists($categoryId, $this->cache)) {
            return $this->cache[$categoryId];
        }

        $this->cache[$categoryId] = $this->resolveUrl($categoryId);
        return $this->cache[$categoryId];
    }

    private function resolveUrl(int $categoryId): ?string
    {
        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException) {
            return null;
        }

        $attribute = $category->getCustomAttribute(AddMegamenuFeaturedImageAttribute::ATTRIBUTE_CODE);
        $filename = $attribute?->getValue();
        if (!$filename || !is_string($filename)) {
            return null;
        }

        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return rtrim($mediaUrl, '/') . '/' . self::MEDIA_PATH . '/' . ltrim($filename, '/');
    }
}
