<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Controller\Alert;

use Magento\Checkout\Model\Cart;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Controller\JsonPostAction;
use Scr1be\BackInStock\Model\AlertItem;
use Scr1be\BackInStock\Model\AlertItemProvider;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;
use Scr1be\BackInStock\Model\StorefrontScope;

/**
 * `POST scr1be_backinstock/alert/addtocart`
 *
 * One endpoint for both the single-card button and "add everything", because they are the same
 * operation with a different number of ids, and because a second endpoint would be a second place
 * where the alert has to be marked as dealt with.
 *
 * **What makes it safe.** The alert ids arrive from a browser and address rows in a shared table.
 * They are never trusted: the queued alerts are re-read for the *session's* customer, and the
 * request is filtered down to the intersection. An id belonging to someone else does not resolve to
 * a product, so the worst a forged request achieves is a response saying nothing was added.
 *
 * **What it deliberately does not do.** It does not add composite products. A configurable needs a
 * `super_attribute` map and a bundle needs its options; a card has neither, and a cart controller
 * that guesses produces a quote item nobody chose. Those cards render a link to the product page
 * instead, and this endpoint reports them as skipped rather than pretending.
 */
class AddToCart extends JsonPostAction
{
    private const PARAM_QTY = 'qty';

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        private readonly AlertItemProvider $provider,
        private readonly PopupStatusWriter $writer,
        private readonly StorefrontScope $storefrontScope,
        private readonly Cart $cart,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($request, $resultJsonFactory, $formKeyValidator);
    }

    public function execute(): Json
    {
        try {
            $scope = $this->storefrontScope->current();
        } catch (NoSuchEntityException $exception) {
            return $this->json(400, ['success' => false, 'added' => 0, 'skipped' => []]);
        }

        if (!$scope->isIdentified()) {
            return $this->json(401, ['success' => false, 'added' => 0, 'skipped' => []]);
        }

        $requested = $this->readAlertIds();
        $queued = $this->provider->getQueued($scope);

        // No ids means "everything in the popup", which is what the bulk button sends.
        $selected = $requested === []
            ? $queued
            : array_values(array_filter(
                $queued,
                static fn (AlertItem $item): bool => in_array($item->alertId, $requested, true)
            ));

        if ($selected === []) {
            return $this->json(200, ['success' => true, 'added' => 0, 'skipped' => []]);
        }

        [$added, $skipped] = $this->addAll($selected);

        if ($added !== []) {
            try {
                $this->cart->save();
            } catch (\Exception $exception) {
                $this->logger->error(
                    'Back-in-stock bulk add-to-cart could not save the quote: ' . $exception->getMessage(),
                    ['exception' => $exception]
                );

                return $this->json(500, [
                    'success' => false,
                    'added' => 0,
                    'skipped' => $skipped,
                    'message' => (string)__('Your cart could not be updated. Please try again.'),
                ]);
            }

            // Only after the quote is safely saved. Marking first and failing second would take the
            // popup away from a customer whose cart never changed.
            $this->writer->markShown($scope->customerId, $scope->websiteId, $added);
        }

        return $this->json(200, [
            'success' => true,
            'added' => count($added),
            'skipped' => $skipped,
        ]);
    }

    /**
     * @param AlertItem[] $items
     * @return array{0: int[], 1: array<int, array{alert_id: int, url: string, reason: string}>}
     */
    private function addAll(array $items): array
    {
        $added = [];
        $skipped = [];

        foreach ($items as $item) {
            if (!$item->isAddToCartable()) {
                $skipped[] = [
                    'alert_id' => $item->alertId,
                    'url' => (string)$item->product->getProductUrl(),
                    'reason' => $item->isSalable ? 'requires_options' : 'not_salable',
                ];
                continue;
            }

            try {
                // The product id rather than the loaded model: core reloads it through
                // `ProductRepositoryInterface` for the current store, which is also what supplies
                // the website check inside `Cart::_getProduct()`. Handing over a collection-hydrated
                // model would make that check issue its own query per line anyway.
                $this->cart->addProduct((int)$item->product->getId(), [self::PARAM_QTY => $this->resolveQty($item)]);
                $added[] = $item->alertId;
            } catch (LocalizedException $exception) {
                // A stock rule the popup's data was too old to know about is the normal case here,
                // and it is information for the customer rather than an error for the log.
                $skipped[] = [
                    'alert_id' => $item->alertId,
                    'url' => (string)$item->product->getProductUrl(),
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return [$added, $skipped];
    }

    /**
     * The requested quantity, floored to what the product actually sells.
     *
     * The client sends `qty[<alert id>]`. Anything below the minimum, or absent, becomes the start
     * quantity the same `QtyRules` gave the card — so the endpoint and the stepper agree without the
     * endpoint trusting the stepper.
     */
    private function resolveQty(AlertItem $item): float
    {
        $map = $this->request->getParam(self::PARAM_QTY);
        $requested = is_array($map) && isset($map[$item->alertId]) ? (float)$map[$item->alertId] : 0.0;
        $start = $item->qtyRules->getStartQty();

        if ($requested < $start) {
            return $start;
        }

        if ($item->qtyRules->increment > 0.0) {
            return ceil($requested / $item->qtyRules->increment) * $item->qtyRules->increment;
        }

        return $item->qtyRules->isDecimal ? $requested : (float)(int)$requested;
    }
}
