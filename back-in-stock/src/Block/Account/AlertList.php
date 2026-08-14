<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Block\Account;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\BackInStock\Model\AlertItem;
use Scr1be\BackInStock\Model\AlertItemFormatter;
use Scr1be\BackInStock\Model\AlertItemProvider;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\StorefrontScope;

/**
 * "My Product Alerts" — every stock alert the customer holds, in the state it is actually in.
 *
 * This is the page the popup is a shortcut to, and it exists for the case the popup cannot serve: an
 * alert that has not fired yet. Magento's own account section has no such page — a customer who
 * clicks "Notify me when this product is in stock" gets a confirmation message and then no way, ever,
 * to see or manage what they subscribed to except the unsubscribe link at the bottom of an email
 * they may not have received yet.
 *
 * Rendering happens server side and cached nowhere: the page is a customer account page, which
 * `Magento_PageCache` does not cache, so there is no reason to pay for a second round trip to render
 * a list the block already has.
 */
class AlertList extends Template
{
    public function __construct(
        Context $context,
        private readonly AlertItemProvider $provider,
        private readonly AlertItemFormatter $formatter,
        private readonly StorefrontScope $storefrontScope,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Alerts grouped the way the page reads them: the ones that fired first, then the ones still
     * waiting.
     *
     * @return array{queued: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>, shown: array<int, array<string, mixed>>}
     */
    public function getGroupedAlerts(): array
    {
        $groups = ['queued' => [], 'waiting' => [], 'shown' => []];

        try {
            $scope = $this->storefrontScope->current();
        } catch (NoSuchEntityException $exception) {
            return $groups;
        }

        foreach ($this->provider->getAll($scope) as $item) {
            $groups[$this->groupOf($item)][] = $this->formatter->toArray($item, $scope->storeId);
        }

        return $groups;
    }

    /**
     * The URL of core's own unsubscribe action.
     *
     * Reusing it rather than writing one is not laziness: `Magento\ProductAlert\Controller\Unsubscribe\Stock`
     * deletes the alert through `Magento\ProductAlert\Model\Stock::deleteCustomer()`, which is the
     * only code path in the system that a future core change to the alert row would keep working.
     * It is an `HttpPostActionInterface`, hence the form in the template rather than a link.
     */
    public function getUnsubscribeUrl(): string
    {
        return $this->getUrl('productalert/unsubscribe/stock', ['_secure' => true]);
    }

    public function getUnsubscribeAllUrl(): string
    {
        return $this->getUrl('productalert/unsubscribe/stockall', ['_secure' => true]);
    }

    private function groupOf(AlertItem $item): string
    {
        if ($item->alertStatus === AlertState::ALERT_ARMED) {
            return 'waiting';
        }

        return $item->popupStatus === AlertState::POPUP_QUEUED ? 'queued' : 'shown';
    }
}
