<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Scr1be\HyvaMedia\Model\MediaImage;
use Scr1be\HyvaMedia\Model\SrcsetBuilder;

/**
 * The module's whole public surface for templates.
 *
 * It is a ViewModel rather than a helper or a block because a Hyvä template's only sanctioned way
 * to reach server logic is a layout-injected ViewModel, and because this keeps the resizer out of
 * reach of anything that is not rendering a page.
 */
class Media implements ArgumentInterface
{
    public function __construct(
        private readonly SrcsetBuilder $srcsetBuilder,
    ) {
    }

    /**
     * @param string $mediaRelativePath Path under pub/media, e.g. 'wysiwyg/home/banner.jpg'
     * @param string $sizes The sizes attribute the markup will carry; it does not affect which
     *                      derivatives are produced, only what the browser does with them
     * @return MediaImage|null Null when the path is not usable — the caller keeps its own fallback
     */
    public function getImage(string $mediaRelativePath, string $sizes = '100vw'): ?MediaImage
    {
        return $this->srcsetBuilder->build($mediaRelativePath, $sizes);
    }
}
