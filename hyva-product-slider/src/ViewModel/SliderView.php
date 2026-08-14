<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\ViewModel;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Block\Slider as SliderBlock;
use Scr1be\HyvaProductSlider\Model\Config;

/**
 * The module's surface for templates: render a slider from anywhere, by name.
 *
 * There are already two ways to place a slider — a layout `<block>` and the widget — and both are the
 * right answer when the position is a *layout* decision. This is for the third case, where it is a
 * *template* decision: a theme's home page phtml that wants a carousel between two hand-written
 * sections, and would otherwise need a container, a handle and a layout update to say so.
 *
 * It hands back HTML rather than data because the point is the whole block — its cache key, its
 * identities, its Hyvä-rendered cards. A ViewModel returning products would push all three into the
 * calling template, which is where they get forgotten.
 */
class SliderView implements ArgumentInterface
{
    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly SliderRepositoryInterface $sliderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled($this->getStoreId());
    }

    /**
     * Empty string for a slider that does not exist, is disabled, or is not assigned to this store —
     * the same three outcomes the block itself produces, because it is the block that decides.
     */
    public function getSliderHtml(string $identifier): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        /** @var SliderBlock $block */
        $block = $this->layout->createBlock(
            SliderBlock::class,
            '',
            ['data' => ['identifier' => $identifier]]
        );

        return $block->setTemplate('Scr1be_HyvaProductSlider::slider.phtml')->toHtml();
    }

    /**
     * Whether a slider by that name would render anything, for a template that wants to lay out
     * around it — a heading, a wrapper, a grid column that should collapse rather than sit empty.
     */
    public function exists(string $identifier): bool
    {
        try {
            return $this->sliderRepository->getByIdentifier($identifier, $this->getStoreId())->isActive();
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
