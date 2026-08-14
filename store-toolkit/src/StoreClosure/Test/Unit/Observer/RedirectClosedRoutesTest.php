<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Observer;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\ClosedRouteRegistry;
use Scr1be\StoreClosure\Model\ClosureState;
use Scr1be\StoreClosure\Observer\RedirectClosedRoutes;

class RedirectClosedRoutesTest extends TestCase
{
    /**
     * @var ClosureState&MockObject
     */
    private $closureState;

    /**
     * @var ActionFlag&MockObject
     */
    private $actionFlag;

    /**
     * @var HttpResponse&MockObject
     */
    private $response;

    /**
     * @var MessageManager&MockObject
     */
    private $messageManager;

    private RedirectClosedRoutes $observer;

    protected function setUp(): void
    {
        $this->closureState = $this->createMock(ClosureState::class);
        $this->actionFlag = $this->createMock(ActionFlag::class);
        $this->response = $this->createMock(HttpResponse::class);
        $this->messageManager = $this->createMock(MessageManager::class);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/de/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->observer = new RedirectClosedRoutes(
            $this->closureState,
            new ClosedRouteRegistry(['checkout'], ['customer_account_login']),
            $this->actionFlag,
            $this->response,
            $storeManager,
            $this->messageManager
        );
    }

    public function testAnOpenStoreIsLeftAlone(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(false);

        $this->actionFlag->expects(self::never())->method('set');
        $this->response->expects(self::never())->method('setRedirect');

        $this->observer->execute($this->observerFor('checkout', 'checkout_index_index'));
    }

    public function testAClosedRouteIsStoppedAndRedirected(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);

        // Both halves are needed: without the flag the action runs and overwrites the redirect,
        // and without the redirect the flag produces an empty 200.
        $this->actionFlag->expects(self::once())
            ->method('set')
            ->with('', ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->expects(self::once())
            ->method('setRedirect')
            ->with('https://example.com/de/');
        $this->messageManager->expects(self::once())->method('addNoticeMessage');

        $this->observer->execute($this->observerFor('checkout', 'checkout_index_index'));
    }

    public function testAnOpenRouteOnAClosedStoreStillRenders(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);

        $this->actionFlag->expects(self::never())->method('set');

        $this->observer->execute($this->observerFor('catalog', 'catalog_product_view'));
    }

    public function testTheStoreSwitcherKeepsWorkingOnAClosedStore(): void
    {
        // The redirect endpoint is how a visitor on a closed store reaches an open sibling.
        // Blocking it would strand them on a store that cannot sell them anything.
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);

        $this->actionFlag->expects(self::never())->method('set');

        $this->observer->execute($this->observerFor('stores', 'stores_store_redirect'));
    }

    public function testTheRedirectTargetIsNeverItselfAClosedRoute(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);

        $this->response->expects(self::once())
            ->method('setRedirect')
            ->with(self::callback(static fn (string $url): bool => !str_contains($url, 'checkout')));

        $this->observer->execute($this->observerFor('checkout', 'checkout_cart_index'));
    }

    public function testANonHttpRequestIsIgnored(): void
    {
        $this->closureState->expects(self::never())->method('isCurrentStoreClosed');

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('request')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $this->observer->execute($observer);
    }

    private function observerFor(string $routeName, string $fullActionName): Observer
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getRouteName')->willReturn($routeName);
        $request->method('getFullActionName')->willReturn($fullActionName);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('request')->willReturn($request);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
