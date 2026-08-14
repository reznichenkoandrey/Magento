<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity;

use Magento\Framework\App\RequestInterface;

/**
 * Works out which entity the current request renders, from the request alone.
 *
 * The obvious alternative is the registry (`current_product`, `current_category`), and it is the
 * wrong seam for two reasons: the registry is only populated once the controller has run, which is
 * after the head block has been generated on some page layouts, and reading it couples a head
 * block to controller internals that core has spent several versions deprecating. A full action
 * name plus a request parameter is stable, and it is what the layout handle is built from anyway.
 */
class RequestEntityResolver
{
    private const ACTION_PRODUCT_VIEW = 'catalog_product_view';
    private const ACTION_CATEGORY_VIEW = 'catalog_category_view';
    private const ACTION_CMS_PAGE_VIEW = 'cms_page_view';
    private const ACTION_CMS_HOME = 'cms_index_index';

    private RequestInterface $request;

    private EntityTypes $entityTypes;

    public function __construct(RequestInterface $request, EntityTypes $entityTypes)
    {
        $this->request = $request;
        $this->entityTypes = $entityTypes;
    }

    /**
     * Null means "this page is not one entity", and hreflang stays off it.
     */
    public function resolve(): ?EntityContext
    {
        // Magento\Framework\App\Request\Http::getFullActionName() concatenates route, controller
        // and action verbatim — it does not lowercase them the way layout handles are lowercased —
        // so the comparison has to.
        $action = strtolower((string) $this->request->getFullActionName());

        switch ($action) {
            case self::ACTION_PRODUCT_VIEW:
                return $this->contextFrom($this->entityTypes->getProductType(), $this->intParam('id'));
            case self::ACTION_CATEGORY_VIEW:
                return $this->contextFrom($this->entityTypes->getCategoryType(), $this->intParam('id'));
            case self::ACTION_CMS_PAGE_VIEW:
                // Magento\Cms\Controller\Page\View::getPageId() reads `page_id` and falls back to
                // `id`; the URL rewrite target path uses `page_id`, direct links use `id`.
                $id = $this->intParam('page_id') ?: $this->intParam('id');

                return $this->contextFrom($this->entityTypes->getCmsPageType(), $id);
            case self::ACTION_CMS_HOME:
                return new EntityContext(EntityContext::TYPE_HOME);
            default:
                return null;
        }
    }

    private function contextFrom(string $type, int $id): ?EntityContext
    {
        return $id > 0 ? new EntityContext($type, $id) : null;
    }

    private function intParam(string $name): int
    {
        return (int) $this->request->getParam($name);
    }
}
