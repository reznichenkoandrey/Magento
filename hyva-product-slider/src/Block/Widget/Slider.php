<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Block\Widget;

use Magento\Widget\Block\BlockInterface;
use Scr1be\HyvaProductSlider\Block\Slider as SliderBlock;

/**
 * The same slider, placed by a merchandiser instead of by layout XML.
 *
 * It is a subclass with no behaviour of its own, and that is deliberate. A widget that re-implemented
 * the rendering would be a second answer to "what does a slider look like"; a widget that only
 * declares `BlockInterface` is the same block reached through a different door.
 *
 * The widget parameter is the slider's identifier, so a merchandiser can rebuild a carousel — new
 * source, different breakpoints — without touching the page it sits on. `widget.xml` gives the widget
 * a `ttl`, which is what makes Magento cache a widget instance at all; the identities the parent
 * block declares are what stop that cache outliving a price change.
 */
class Slider extends SliderBlock implements BlockInterface
{
    /**
     * `widget.xml` writes the chosen identifier into this parameter. Mapping it onto the block's own
     * `identifier` data key keeps the parent oblivious to which door it was reached through.
     */
    public const PARAM_IDENTIFIER = 'slider_identifier';

    public function getIdentifier(): string
    {
        $fromWidget = trim((string) $this->getData(self::PARAM_IDENTIFIER));

        return $fromWidget !== '' ? $fromWidget : parent::getIdentifier();
    }
}
