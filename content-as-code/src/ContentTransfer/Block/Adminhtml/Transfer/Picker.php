<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Block\Adminhtml\Transfer;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\PorterPool;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\StoreScope;
use Scr1be\ContentTransfer\Model\Summary;

/**
 * The Content Transfer page: a store filter and, under it, everything the pool can see, grouped by
 * porter and ticked individually.
 *
 * The page ships **no JavaScript**. The store filter is a `GET` form and the export is a `POST`
 * form; there is nothing here a browser has not been able to do since 1997. That is a deliberate
 * trade against a "select all in this section" checkbox, which is the only thing script would buy:
 * this page is a rarely-visited operator tool, and no script means nothing to break when the admin
 * theme's requirejs bundle changes and nothing to whitelist when a CSP policy tightens.
 *
 * The picker calls `summarize()`, never `capture()`. Capturing everything to render a list would
 * make opening the page cost the same as an export — on an install with a few hundred CMS blocks,
 * that is seconds of rewriting content nobody asked for.
 */
class Picker extends Template
{
    public const PARAM_STORE = 'store_id';
    public const PARAM_ENTRIES = 'entries';
    public const PARAM_FORMAT = 'format';

    public const FORMAT_JSON = 'json';
    public const FORMAT_ZIP = 'zip';

    /**
     * @var array<string, Summary[]>|null
     */
    private ?array $summaries = null;

    public function __construct(
        Context $context,
        private readonly PorterPool $porterPool,
        private readonly StoreScope $storeScope,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return PorterInterface[]
     */
    public function getPorters(): array
    {
        return $this->porterPool->getSorted();
    }

    /**
     * @return Summary[]
     */
    public function getSummaries(string $porterCode): array
    {
        if ($this->summaries === null) {
            $selection = new Selection($this->getSelectedStoreIds());
            $this->summaries = [];

            foreach ($this->porterPool->getSorted() as $porter) {
                $this->summaries[$porter->getCode()] = $porter->summarize($selection);
            }
        }

        return $this->summaries[$porterCode] ?? [];
    }

    /**
     * @return int[] Empty for "every store view", which is also the default.
     */
    public function getSelectedStoreIds(): array
    {
        $storeId = (string)$this->getRequest()->getParam(self::PARAM_STORE, '');

        return $storeId === '' ? [] : [(int)$storeId];
    }

    /**
     * @return array<int, string> store id => code
     */
    public function getStoreOptions(): array
    {
        return $this->storeScope->storeOptions();
    }

    public function isStoreSelected(int $storeId): bool
    {
        return in_array($storeId, $this->getSelectedStoreIds(), true);
    }

    public function getFilterUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('*/*/export');
    }

    /**
     * Label for a summary row: the entity's own name plus the stores it belongs to, because on a
     * multi-store install two rows with the same title and different scopes are the normal case and
     * the one an operator most needs to tell apart.
     */
    public function describeStores(Summary $summary): string
    {
        return $summary->getStoreCodes() === []
            ? (string)__('All store views')
            : implode(', ', $summary->getStoreCodes());
    }
}
