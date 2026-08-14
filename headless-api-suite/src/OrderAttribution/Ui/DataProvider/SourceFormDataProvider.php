<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Ui\DataProvider;

use Magento\Backend\Model\Session;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Model\ResourceModel\Source\CollectionFactory;

/**
 * Feeds the source form.
 *
 * Re-hydrating from the session is what makes a rejected save survivable: `Save` puts the posted
 * values back before redirecting, so an admin who typed a bad code gets the form back with their
 * label and sort order intact instead of an empty form and an error message. Reading the session
 * once and clearing it immediately is deliberate — a stale form payload that outlives its redirect
 * reappears the next time the admin opens *any* source.
 */
class SourceFormDataProvider extends AbstractDataProvider
{
    /**
     * Session key shared with the Save controller.
     */
    public const FORM_DATA_KEY = 'scr1be_order_source_form_data';

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $loadedData = null;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param Session $session
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly Session $session,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();

        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @inheritDoc
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        foreach ($this->collection->getItems() as $source) {
            /** @var \Scr1be\OrderAttribution\Model\Source $source */
            $this->loadedData[$source->getId()] = $source->getData();
        }

        // `getData($key, $clear)` rather than the magic `getScr1beOrderSourceFormData(true)`. The
        // magic call goes through `SessionManager::__call`, which forwards to the storage object —
        // a plain `Magento\Framework\DataObject`, where the second argument of `getData()` is an
        // *index* into the value, not a clear flag. Only `SessionManager::getData()` implements the
        // clear, and only when called directly.
        $restored = $this->session->getData(self::FORM_DATA_KEY, true);
        if (is_array($restored) && $restored !== []) {
            $key = (int)($restored[SourceInterface::SOURCE_ID] ?? 0);
            $this->loadedData[$key] = $restored;
        }

        return $this->loadedData;
    }
}
