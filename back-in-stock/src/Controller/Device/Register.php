<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Controller\Device;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\BackInStock\Controller\JsonPostAction;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\DeviceTokenRegistry;
use Scr1be\BackInStock\Model\StorefrontScope;

/**
 * `POST scr1be_backinstock/device/register`
 *
 * Where a browser or an app hands over the token a push service gave it.
 *
 * **Guests are allowed.** The sequence that actually happens is: a visitor grants notification
 * permission on a product page, *then* creates an account, *then* subscribes to an alert. Refusing
 * the registration until there is a customer id would mean asking for permission a second time after
 * login, which is the request browsers are least likely to grant twice. The row is written with a
 * null customer id and claimed by the next registration that carries one — see
 * `DeviceTokenWriter::upsert()` for why that overwrite goes both ways.
 *
 * **It is closed when push is off.** A public write endpoint that nothing reads is a table that
 * fills up for no reason, so it answers 404 unless the channel is switched on for the website.
 */
class Register extends JsonPostAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        private readonly DeviceTokenRegistry $registry,
        private readonly StorefrontScope $storefrontScope,
        private readonly Config $config
    ) {
        parent::__construct($request, $resultJsonFactory, $formKeyValidator);
    }

    public function execute(): Json
    {
        try {
            $scope = $this->storefrontScope->current();
        } catch (NoSuchEntityException $exception) {
            return $this->json(400, ['success' => false]);
        }

        if (!$this->config->isPushEnabled($scope->websiteId)) {
            return $this->json(404, ['success' => false]);
        }

        try {
            $this->registry->register(
                (string)$this->request->getParam('token', ''),
                $scope->customerId,
                $scope->websiteId,
                (string)$this->request->getParam('platform', '')
            );
        } catch (LocalizedException $exception) {
            return $this->json(400, ['success' => false, 'message' => $exception->getMessage()]);
        }

        return $this->json(200, ['success' => true]);
    }
}
