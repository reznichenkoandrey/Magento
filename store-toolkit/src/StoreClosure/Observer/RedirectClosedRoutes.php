<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Observer;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreClosure\Model\ClosedRouteRegistry;
use Scr1be\StoreClosure\Model\ClosureState;

/**
 * Sends a visitor away from the routes a closed store no longer serves.
 *
 * `controller_action_predispatch` is dispatched by
 * Magento\Framework\App\FrontController::dispatchPreDispatchEvents(), with `controller_action` and
 * `request` in the payload, immediately before the action's own response is produced — and
 * FrontController checks `ActionInterface::FLAG_NO_DISPATCH` afterwards, in getActionResponse(),
 * which is what makes a redirect from an observer stick.
 *
 * Deliberately *not* in the closed set: `stores/store/redirect` and `stores/store/switch`. A closed
 * store still has to be able to hand the visitor over to an open sibling, and blocking the store
 * switcher's own endpoints would strand them.
 */
class RedirectClosedRoutes implements ObserverInterface
{
    private ClosureState $closureState;

    private ClosedRouteRegistry $routeRegistry;

    private ActionFlag $actionFlag;

    /**
     * The concrete HTTP response, not Magento\Framework\App\ResponseInterface: setRedirect() is not
     * on that interface, and Magento\Framework\App\Response\HttpInterface — which does declare it —
     * has no DI preference to resolve. app/etc/di.xml maps ResponseInterface to this same class, so
     * asking for it directly gets the shared instance the front controller will send.
     */
    private HttpResponse $response;

    private StoreManagerInterface $storeManager;

    private MessageManager $messageManager;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        ClosureState $closureState,
        ClosedRouteRegistry $routeRegistry,
        ActionFlag $actionFlag,
        HttpResponse $response,
        StoreManagerInterface $storeManager,
        MessageManager $messageManager
    ) {
        $this->closureState = $closureState;
        $this->routeRegistry = $routeRegistry;
        $this->actionFlag = $actionFlag;
        $this->response = $response;
        $this->storeManager = $storeManager;
        $this->messageManager = $messageManager;
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getData('request');

        if (!$request instanceof HttpRequest || !$this->closureState->isCurrentStoreClosed()) {
            return;
        }

        if (!$this->routeRegistry->isClosedRoute($request->getRouteName(), $request->getFullActionName())) {
            return;
        }

        $home = $this->getHomeUrl();

        if ($home === null) {
            return;
        }

        $this->messageManager->addNoticeMessage(__('This store is not accepting orders at the moment.'));

        // The order matters: the flag stops the action from running, and the redirect is what the
        // response then carries. Setting only the redirect would let the action run and overwrite it.
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->setRedirect($home);
    }

    /**
     * The store's own home page — never a configured landing page, because that page could itself
     * be on a closed route and the redirect would then loop.
     */
    private function getHomeUrl(): ?string
    {
        try {
            $store = $this->storeManager->getStore();
        } catch (NoSuchEntityException $e) {
            return null;
        }

        return $store instanceof Store ? $store->getBaseUrl(UrlInterface::URL_TYPE_LINK) : null;
    }
}
