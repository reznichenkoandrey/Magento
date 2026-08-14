<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Controller\Account;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * `GET scr1be_backinstock/account/alerts` — the "My Product Alerts" page.
 *
 * **Why it implements `AccountInterface` and nothing else.** `Magento_Customer/etc/frontend/di.xml`
 * puts `Magento\Customer\Controller\Plugin\Account` on that interface, and its `aroundExecute()`
 * calls `$this->session->authenticate()` for any action not in its allow-list — which is how every
 * account page in Magento gets its login redirect. Re-implementing the check here would be a second
 * opinion about who is logged in.
 *
 * One detail worth knowing before naming an action in this route: that plugin's allow-list is
 * matched against the *action name alone*, not the full route, and it contains `index`, `login`,
 * `create` and friends. An account controller called `Index` in any module's own route would
 * therefore be public. This one is called `Alerts`.
 */
class Alerts implements AccountInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory
    ) {
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Product Alerts'));

        return $page;
    }
}
