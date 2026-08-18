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
 * One detail worth knowing before naming an action in this route: `isActionAllowed()` matches
 * `$this->request->getActionName()` against the allow-list with `/^(…)$/i` — the *action name
 * alone*, with no reference to the route it belongs to. The list is the account-entry set —
 * `create`, `login`, `loginpost`, `logoutsuccess`, `forgotpassword`, `forgotpasswordpost`,
 * `resetpassword`, `resetpasswordpost`, `confirm`, `confirmation`, `createpassword`, `createpost`
 * (Magento_Customer/etc/frontend/di.xml, and nothing else in core adds to it). So an action named
 * `Confirm` or `Login` in *any* module's own account route is public without meaning to be, while
 * every other name — `Index` included, since it is not on that list — gets the redirect. This one
 * is called `Alerts`, which is on nobody's list.
 *
 * **`execute()` deliberately declares no return type**, which is why the `@return` tag carries the
 * contract instead. The same plugin that produces the login redirect returns *nothing* while doing
 * it — `$this->session->authenticate()` writes the redirect onto the response and answers `false`,
 * so `aroundExecute()` falls out of its `if` with an implicit `null`. A narrowed `: Page` here is
 * copied onto the generated interceptor, and the guest who was supposed to be redirected gets a
 * TypeError and an HTTP 500 instead. Core's own account controllers — `Customer\Account\Index`,
 * `Account\Edit`, `Wishlist\Index\Index` — are untyped for exactly this reason.
 */
class Alerts implements AccountInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory
    ) {
    }

    /**
     * @return Page|null Null whenever the customer plugin short-circuits with a login redirect.
     */
    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Product Alerts'));

        return $page;
    }
}
