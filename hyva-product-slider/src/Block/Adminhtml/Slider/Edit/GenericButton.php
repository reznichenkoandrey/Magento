<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Block\Adminhtml\Slider\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\AuthorizationInterface;

/**
 * The two things every button on this form needs: which slider it is looking at, and whether the
 * logged-in admin is allowed to do the thing the button offers.
 */
abstract class GenericButton
{
    public function __construct(
        private readonly Context $context,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function getSliderId(): ?int
    {
        $sliderId = (int) $this->context->getRequest()->getParam('slider_id');

        return $sliderId > 0 ? $sliderId : null;
    }

    protected function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }

    protected function isAllowed(string $resource): bool
    {
        return $this->authorization->isAllowed($resource);
    }
}
