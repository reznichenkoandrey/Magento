<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Block\Adminhtml\Category;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\CategoryCascade\Model\CascadeGuard;
use Scr1be\CategoryCascade\Model\Config;
use Scr1be\CategoryCascade\Model\SubtreeLocator;

/**
 * Renders the data the confirm prompt needs, and nothing else.
 *
 * The category comes from the request rather than from the registry. `current_category` is what
 * the admin category controllers register and what most modules reach for, but it is registry
 * state set by a controller this block does not control, and reading it couples the block to
 * whichever controller happened to render the page. The id and store are already in the URL of
 * every category edit screen, and the repository turns them into the same object.
 */
class CascadeConfirm extends Template
{
    private const PARAM_CATEGORY_ID = 'id';
    private const PARAM_STORE_ID = 'store';

    private ?int $activeDescendants = null;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly SubtreeLocator $locator,
        private readonly CategoryRepositoryInterface $categoryRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Nothing renders unless a save on this screen could actually cascade: the feature is on, the
     * prompt is on, and there is at least one enabled category below this one. A prompt that fires
     * on saves which change nothing is a prompt admins learn to dismiss without reading.
     */
    public function isPromptEnabled(): bool
    {
        $storeId = $this->getStoreId();

        return $this->config->isCascadeEnabled($storeId)
            && $this->config->isConfirmPromptEnabled($storeId)
            && $this->getActiveDescendantCount() > 0;
    }

    public function getActiveDescendantCount(): int
    {
        if ($this->activeDescendants !== null) {
            return $this->activeDescendants;
        }

        $category = $this->getCategory();
        if ($category === null || (int) $category->getLevel() < CascadeGuard::MIN_CASCADE_LEVEL) {
            return $this->activeDescendants = 0;
        }

        return $this->activeDescendants = $this->locator->countActiveDescendants(
            (string) $category->getPath(),
            $this->getStoreId()
        );
    }

    /**
     * The prompt has to fire on a transition, not on a state — the same condition the server-side
     * guard applies. This is the "before" half of it, read once when the page renders; the browser
     * supplies the "after" half from the form.
     */
    public function isCategoryEnabled(): bool
    {
        $category = $this->getCategory();

        return $category !== null && (bool) (int) $category->getIsActive();
    }

    public function getConfirmMessage(): string
    {
        return (string) __(
            'Disabling this category will also disable %1 enabled subcategories below it. '
            . 'Enabling it again later will not turn them back on. Save anyway?',
            $this->getActiveDescendantCount()
        );
    }

    private function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam(self::PARAM_STORE_ID, 0);
    }

    private function getCategory(): ?CategoryInterface
    {
        $categoryId = (int) $this->getRequest()->getParam(self::PARAM_CATEGORY_ID);
        if ($categoryId === 0) {
            return null;
        }

        try {
            return $this->categoryRepository->get($categoryId, $this->getStoreId());
        } catch (NoSuchEntityException) {
            // The new-category screen carries no id, and a deleted category can still be reached
            // by a stale bookmark. Neither is an error worth surfacing from a hidden helper block.
            return null;
        }
    }
}
