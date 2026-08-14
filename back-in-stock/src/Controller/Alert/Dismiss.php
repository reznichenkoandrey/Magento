<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Controller\Alert;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\BackInStock\Controller\JsonPostAction;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;
use Scr1be\BackInStock\Model\StorefrontScope;

/**
 * `POST scr1be_backinstock/alert/dismiss`
 *
 * The popup closing is a state change, not a UI event: the customer has now seen that these products
 * are back, and showing them the same six cards on the next page load would make the feature an
 * irritation rather than a service.
 *
 * `alert_ids` dismisses a subset — the single card whose × was clicked. Its absence dismisses
 * everything queued, which is what closing the popup means.
 */
class Dismiss extends JsonPostAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        private readonly PopupStatusWriter $writer,
        private readonly StorefrontScope $storefrontScope
    ) {
        parent::__construct($request, $resultJsonFactory, $formKeyValidator);
    }

    public function execute(): Json
    {
        try {
            $scope = $this->storefrontScope->current();
        } catch (NoSuchEntityException $exception) {
            return $this->json(400, ['success' => false, 'dismissed' => 0]);
        }

        if (!$scope->isIdentified()) {
            // A guest has no alerts, so there is nothing to dismiss and nothing to say about it.
            // 401 rather than a redirect, because the caller is `fetch()`.
            return $this->json(401, ['success' => false, 'dismissed' => 0]);
        }

        $alertIds = $this->readAlertIds();

        $dismissed = $alertIds === []
            ? $this->writer->markAllShown($scope->customerId, $scope->websiteId)
            : $this->writer->markShown($scope->customerId, $scope->websiteId, $alertIds);

        return $this->json(200, ['success' => true, 'dismissed' => $dismissed]);
    }
}
