<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Model\Porter;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Widget\Model\ResourceModel\Widget\Instance as InstanceResource;
use Magento\Widget\Model\ResourceModel\Widget\Instance\CollectionFactory;
use Magento\Widget\Model\Widget\Instance;
use Magento\Widget\Model\Widget\InstanceFactory;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\Entry;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\Porter\CmsBlockPorter;
use Scr1be\ContentTransfer\Model\Porter\CmsPagePorter;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\Summary;
use Scr1be\ContentTransfer\Model\Widget\InstanceCodec;
use Scr1be\CouponTicket\Block\Widget\Ticket;

/**
 * Coupon-ticket widget instances, with the rule reference made portable.
 *
 * This class is what the porter pool exists for. `Scr1be_ContentTransfer` knows nothing about coupon
 * tickets; this module registers a porter and, in the same `di.xml`, adds its widget class to the
 * generic widget porter's `claimedTypes` so the two do not both capture the same rows. Nothing in
 * the engine had to change to make room for it.
 *
 * ### The one thing it does that the generic porter cannot
 *
 * The widget's `rule_id` parameter is `salesrule.rule_id`, an autoincrement. Captured as-is it
 * points at whatever rule happens to hold that id on the target install — quite possibly a live
 * discount with different terms, which is a worse outcome than a broken widget. So the payload
 * carries the **rule name** instead, and import resolves it back.
 *
 * Rule names are not unique either (`salesrule` has no unique index on `name`), so a name that
 * matches more than one rule is reported rather than guessed at. Everything else about the instance
 * — placements, theme, stores, sort order — goes through the same `InstanceCodec` the generic porter
 * uses, by composition: this porter *has* a codec rather than extending one, because the only thing
 * it changes is one parameter.
 */
class CouponTicketPorter implements PorterInterface
{
    public const CODE = 'coupon_ticket';

    public const KEY_RULE_NAME = 'rule_name';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly InstanceFactory $instanceFactory,
        private readonly InstanceResource $instanceResource,
        private readonly InstanceCodec $codec,
        private readonly RuleCollectionFactory $ruleCollectionFactory
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('Coupon Tickets');
    }

    public function getDependencies(): array
    {
        return [CmsBlockPorter::CODE, CmsPagePorter::CODE];
    }

    public function summarize(Selection $selection): array
    {
        $summaries = [];

        foreach ($this->listInstances($selection) as $row) {
            $summaries[] = new Summary($row['key'], $row['title'], $row['stores']);
        }

        return $summaries;
    }

    public function capture(Selection $selection): array
    {
        $entries = [];

        foreach ($this->listInstances($selection) as $row) {
            if (!$selection->includesIdentifier(self::CODE, $row['key'])) {
                continue;
            }

            $instance = $this->load($row['id']);
            $label = self::CODE . '/' . $row['key'];

            $payload = $this->codec->toPayload($instance);
            $warnings = $this->codec->unportablePlacements($instance, $label);
            $transforms = [];

            $ruleId = (int)($payload[InstanceCodec::KEY_PARAMETERS][Ticket::PARAM_RULE_ID] ?? 0);
            $ruleName = $this->ruleName($ruleId);

            if ($ruleName === null) {
                $warnings[] = sprintf(
                    '%s: points at cart price rule %d, which does not exist here. The ticket was '
                    . 'captured without a rule and will render nothing until one is picked.',
                    $label,
                    $ruleId
                );
            } else {
                $transforms[] = sprintf('%s: rule_id %d -> "%s".', $label, $ruleId, $ruleName);
            }

            // The numeric id leaves the payload entirely rather than travelling beside the name.
            // Keeping both invites an importer — or a person — to prefer the id.
            unset($payload[InstanceCodec::KEY_PARAMETERS][Ticket::PARAM_RULE_ID]);
            $payload[self::KEY_RULE_NAME] = $ruleName;

            $entries[] = new Entry(self::CODE, $row['key'], $payload, $transforms, $warnings);
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

        if ($title === '') {
            throw new LocalizedException(new Phrase('A coupon ticket entry needs a title.'));
        }

        $existing = $this->find($entry);

        if ($existing !== null && !$mode->replacesExisting()) {
            return Outcome::skipped((string)__('Coupon ticket "%1" is already here.', $title));
        }

        $ruleName = (string)($payload[self::KEY_RULE_NAME] ?? '');
        $ruleId = $ruleName === '' ? 0 : $this->ruleId($ruleName);

        $payload[InstanceCodec::KEY_PARAMETERS][Ticket::PARAM_RULE_ID] = $ruleId;

        $instance = $existing ?? $this->instanceFactory->create();
        $this->codec->applyPayload($instance, $payload);
        $this->instanceResource->save($instance);

        $detail = $ruleId === 0
            ? (string)__('Coupon ticket "%1" has no rule: pick one in the admin.', $title)
            : (string)__('Coupon ticket "%1" is linked to rule "%2".', $title, $ruleName);

        return $existing !== null
            ? Outcome::replaced($detail)
            : Outcome::created($detail);
    }

    private function ruleName(int $ruleId): ?string
    {
        if ($ruleId <= 0) {
            return null;
        }

        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('rule_id', $ruleId);

        foreach ($collection as $rule) {
            return (string)$rule->getName();
        }

        return null;
    }

    /**
     * @throws LocalizedException when the name matches no rule or more than one. Both are conditions
     *         only a human can settle, and picking the first match would link a ticket to a discount
     *         nobody chose.
     */
    private function ruleId(string $ruleName): int
    {
        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('name', $ruleName);

        $matches = [];

        foreach ($collection as $rule) {
            $matches[] = (int)$rule->getId();
        }

        if ($matches === []) {
            throw new LocalizedException(
                new Phrase('No cart price rule named "%1" exists here; create it first.', [$ruleName])
            );
        }

        if (count($matches) > 1) {
            throw new LocalizedException(
                new Phrase(
                    '%1 cart price rules are named "%2". Rename them so the ticket can be linked '
                    . 'unambiguously.',
                    [count($matches), $ruleName]
                )
            );
        }

        return $matches[0];
    }

    /**
     * @return array<int, array{id: int, key: string, title: string, stores: string[]}>
     */
    private function listInstances(Selection $selection): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('instance_type', Ticket::class);

        if ($selection->hasStoreFilter()) {
            $collection->addStoreFilter($selection->getStoreIds());
        }

        $rows = [];

        foreach ($collection as $instance) {
            $rows[] = [
                'id' => (int)$instance->getId(),
                'key' => $this->codec->identifierFor($instance),
                'title' => (string)$instance->getTitle(),
                'stores' => $this->codec->storeCodesOf($instance),
            ];
        }

        return $rows;
    }

    private function load(int $instanceId): Instance
    {
        $instance = $this->instanceFactory->create();
        $this->instanceResource->load($instance, $instanceId);

        return $instance;
    }

    /**
     * @throws LocalizedException when the bundle names a theme this install does not have.
     */
    private function find(EntryInterface $entry): ?Instance
    {
        $payload = $entry->getPayload();
        $themeId = $this->codec->themeIdFor((string)($payload[InstanceCodec::KEY_THEME] ?? ''));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('instance_type', Ticket::class);
        $collection->addFieldToFilter('title', (string)($payload[InstanceCodec::KEY_TITLE] ?? ''));

        foreach ($collection as $candidate) {
            if ((int)$candidate->getThemeId() === $themeId) {
                return $this->load((int)$candidate->getId());
            }
        }

        return null;
    }
}
