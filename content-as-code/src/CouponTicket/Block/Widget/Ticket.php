<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Block\Widget;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Widget\Block\BlockInterface;
use Scr1be\CouponTicket\Model\Eligibility;
use Scr1be\CouponTicket\Model\Ticket as TicketData;
use Scr1be\CouponTicket\Model\TicketReader;

/**
 * The coupon ticket widget.
 *
 * Four author-facing parameters, declared in `etc/widget.xml`: which cart price rule, a headline,
 * a line of small print, and which of the two templates to use. Everything else on the ticket comes
 * out of the rule.
 */
class Ticket extends Template implements BlockInterface
{
    public const PARAM_RULE_ID = 'rule_id';
    public const PARAM_HEADING = 'heading';
    public const PARAM_NOTE = 'note';

    public const DEFAULT_TEMPLATE = 'widget/ticket.phtml';

    /**
     * Milliseconds the "Copied" confirmation stays up. Long enough to read, short enough that the
     * button is ready again before anyone reaches for it twice.
     */
    public const COPY_FEEDBACK_MS = 2000;

    /**
     * @var TicketData|null|false false = not read yet; null = there is nothing to show.
     */
    private TicketData|null|false $ticket = false;

    public function __construct(
        Context $context,
        private readonly TicketReader $ticketReader,
        private readonly Eligibility $eligibility,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Runs after the constructor has taken the widget parameters into the block's data, so a
     * `template` parameter is already visible here and only an unset one gets the default. That
     * matters for a widget placed through layout XML rather than through the widget UI, where no
     * parameter is supplied at all.
     */
    protected function _construct(): void
    {
        parent::_construct();

        if (!$this->getTemplate()) {
            $this->setTemplate(self::DEFAULT_TEMPLATE);
        }
    }

    public function getRuleId(): int
    {
        return (int)$this->getData(self::PARAM_RULE_ID);
    }

    public function getTicket(): ?TicketData
    {
        if ($this->ticket === false) {
            $this->ticket = $this->ticketReader->read($this->getRuleId());
        }

        return $this->ticket;
    }

    public function getHeading(): string
    {
        return (string)$this->getData(self::PARAM_HEADING);
    }

    public function getNote(): string
    {
        return (string)$this->getData(self::PARAM_NOTE);
    }

    public function getCopyFeedbackMs(): int
    {
        return self::COPY_FEEDBACK_MS;
    }

    /**
     * Resolved through the asset repository, so the url carries the deployment's static version and
     * respects a separate static-content domain. A hand-written `/static/...` path is how a module
     * works in developer mode and 404s in production.
     */
    public function getScriptUrl(): string
    {
        return $this->getViewFileUrl('Scr1be_CouponTicket::js/coupon-ticket.js');
    }

    /**
     * The base implementation returns `[$this->getNameInLayout()]` and nothing else, which would
     * make one cached fragment serve every customer group. This block's output depends on the
     * group, the rule and the store's currency formatting, so all three go in.
     *
     * Block HTML caching only engages when the block has a `cache_lifetime` —
     * `AbstractBlock::_loadCache()` short-circuits on `getCacheLifetime() === null` and
     * `_saveCache()` on any falsy value — and this block never sets one. The key is correct anyway:
     * the day somebody gives it a lifetime should not be the day coupon codes start leaking between
     * customer groups.
     *
     * @return string[]
     */
    public function getCacheKeyInfo(): array
    {
        return [
            ...parent::getCacheKeyInfo(),
            (string)$this->getRuleId(),
            (string)$this->eligibility->getCurrentGroupId(),
            (string)$this->_storeManager->getStore()->getId(),
            (string)$this->getTemplate(),
        ];
    }

    /**
     * A widget pointing at a deleted or disabled rule renders nothing at all — not an error, not an
     * empty frame. The author sees a missing ticket on the page they are editing, and the customer
     * sees the page as if the widget had never been placed.
     */
    protected function _toHtml(): string
    {
        if ($this->getTicket() === null) {
            return '';
        }

        return parent::_toHtml();
    }
}
