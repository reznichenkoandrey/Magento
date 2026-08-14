<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Porter;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Widget\Model\ResourceModel\Widget\Instance as InstanceResource;
use Magento\Widget\Model\ResourceModel\Widget\Instance\CollectionFactory;
use Magento\Widget\Model\Widget\Instance;
use Magento\Widget\Model\Widget\InstanceFactory;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\Content\ContentTransformer;
use Scr1be\ContentTransfer\Model\Entry;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\Summary;
use Scr1be\ContentTransfer\Model\Widget\InstanceCodec;

/**
 * Widget instances — the placements that put a block into a container on a set of layout handles.
 *
 * Depends on blocks and pages: a `cms_static_block` instance names the block it renders, and a
 * placement can be scoped to a specific CMS page's layout handle.
 *
 * ### Instances have to be loaded one at a time
 *
 * `Magento\Widget\Model\ResourceModel\Widget\Instance::_afterLoad($object)` is what fills
 * `page_groups` from `widget_instance_page`, and it is reached through `AbstractDb::load()`. A
 * collection never calls it: `AbstractCollection::_afterLoad()` sets orig data on each item and
 * dispatches its load events, and that is all. Capturing straight out of a collection therefore
 * yields instances with no placements at all, which import as widgets that appear nowhere. So the
 * collection finds the rows and each instance is then loaded properly.
 *
 * ### Claimed types
 *
 * A module that ships its own widget can register a porter that knows how to make that widget's
 * parameters portable, and add its class to `$claimedTypes` so both porters do not capture the same
 * rows. The claim lives in the claiming module's `di.xml`, so this module needs no knowledge of what
 * claimed it.
 */
class WidgetInstancePorter implements PorterInterface
{
    public const CODE = 'widget_instance';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly InstanceFactory $instanceFactory,
        private readonly InstanceResource $instanceResource,
        private readonly InstanceCodec $codec,
        private readonly ContentTransformer $contentTransformer,
        /** @var string[] Widget classes another porter is responsible for. */
        private readonly array $claimedTypes = []
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('Widget Instances');
    }

    public function getDependencies(): array
    {
        return [CmsBlockPorter::CODE, CmsPagePorter::CODE];
    }

    public function summarize(Selection $selection): array
    {
        $summaries = [];

        foreach ($this->listInstances($selection) as $row) {
            $summaries[] = new Summary(
                $row['key'],
                sprintf('%s (%s)', $row['title'], $row['type']),
                $row['stores']
            );
        }

        return $summaries;
    }

    public function capture(Selection $selection): array
    {
        $entries = [];
        $seen = [];

        foreach ($this->listInstances($selection) as $row) {
            $key = $row['key'];

            if (!$selection->includesIdentifier(self::CODE, $key)) {
                continue;
            }

            $label = self::CODE . '/' . $key;

            if (isset($seen[$key])) {
                // Not fatal, and not silently renamed either: a generated suffix would depend on
                // the order rows came back in, so the next capture would move it and every diff
                // would contain it. The operator renames one widget and the collision is gone.
                $entries[$seen[$key]] = $this->withExtraWarning(
                    $entries[$seen[$key]],
                    sprintf(
                        '%s: a second widget instance has the same type and title and was not '
                        . 'captured. Rename one of them so both can travel.',
                        $label
                    )
                );

                continue;
            }

            $instance = $this->load($row['id']);

            $payload = $this->codec->toPayload($instance);
            $transforms = [];
            $warnings = $this->codec->unportablePlacements($instance, $label);

            // A `cms_static_block` instance keeps its reference as a numeric `block_id` in
            // `widget_parameters`, where no directive scan would ever find it.
            $payload[InstanceCodec::KEY_PARAMETERS] = $this->contentTransformer->toPortableParameters(
                (string)$payload[InstanceCodec::KEY_TYPE],
                (array)$payload[InstanceCodec::KEY_PARAMETERS],
                $label,
                $transforms,
                $warnings
            );

            $seen[$key] = count($entries);
            $entries[] = new Entry(self::CODE, $key, $payload, $transforms, $warnings);
        }

        return $entries;
    }

    public function exists(EntryInterface $entry): bool
    {
        return $this->find($entry) !== null;
    }

    public function apply(EntryInterface $entry, ImportMode $mode): Outcome
    {
        $payload = $entry->getPayload();
        $title = (string)($payload[InstanceCodec::KEY_TITLE] ?? '');
        $type = (string)($payload[InstanceCodec::KEY_TYPE] ?? '');

        if ($title === '' || $type === '') {
            throw new LocalizedException(
                new Phrase('A widget instance entry needs both a title and an instance type.')
            );
        }

        $existing = $this->find($entry);

        if ($existing !== null && !$mode->replacesExisting()) {
            return Outcome::skipped((string)__('Widget "%1" is already here.', $title));
        }

        $instance = $existing ?? $this->instanceFactory->create();
        $this->codec->applyPayload($instance, $payload);
        $this->instanceResource->save($instance);

        return $existing !== null
            ? Outcome::replaced((string)__('Widget "%1" was overwritten.', $title))
            : Outcome::created((string)__('Widget "%1" was created.', $title));
    }

    /**
     * @return array<int, array{id: int, key: string, title: string, type: string, stores: string[]}>
     */
    private function listInstances(Selection $selection): array
    {
        $collection = $this->collectionFactory->create();

        if ($selection->hasStoreFilter()) {
            // `$withDefaultStore = true` prepends store id 0 to the FIND_IN_SET conditions, so
            // instances published to all store views come along with a store-scoped capture.
            $collection->addStoreFilter($selection->getStoreIds());
        }

        $rows = [];

        foreach ($collection as $instance) {
            $type = (string)$instance->getType();

            if ($this->isClaimed($type)) {
                continue;
            }

            $rows[] = [
                'id' => (int)$instance->getId(),
                'key' => $this->codec->identifierFor($instance),
                'title' => (string)$instance->getTitle(),
                'type' => $type,
                'stores' => $this->codec->storeCodesOf($instance),
            ];
        }

        return $rows;
    }

    private function isClaimed(string $instanceType): bool
    {
        $normalized = ltrim($instanceType, '\\');

        foreach ($this->claimedTypes as $claimed) {
            if (ltrim((string)$claimed, '\\') === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function load(int $instanceId): Instance
    {
        $instance = $this->instanceFactory->create();
        $this->instanceResource->load($instance, $instanceId);

        return $instance;
    }

    /**
     * Matched on type + title + theme — the same triple the bundle key is derived from, so anything
     * this finds is something whose capture would have produced the key that found it.
     *
     * @throws LocalizedException when the bundle names a theme this install does not have.
     */
    private function find(EntryInterface $entry): ?Instance
    {
        $payload = $entry->getPayload();
        $themeId = $this->codec->themeIdFor((string)($payload[InstanceCodec::KEY_THEME] ?? ''));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('instance_type', (string)($payload[InstanceCodec::KEY_TYPE] ?? ''));
        $collection->addFieldToFilter('title', (string)($payload[InstanceCodec::KEY_TITLE] ?? ''));

        foreach ($collection as $candidate) {
            if ((int)$candidate->getThemeId() === $themeId) {
                return $this->load((int)$candidate->getId());
            }
        }

        return null;
    }

    private function withExtraWarning(Entry $entry, string $warning): Entry
    {
        return new Entry(
            $entry->getPorterCode(),
            $entry->getIdentifier(),
            $entry->getPayload(),
            $entry->getTransforms(),
            [...$entry->getWarnings(), $warning]
        );
    }
}
