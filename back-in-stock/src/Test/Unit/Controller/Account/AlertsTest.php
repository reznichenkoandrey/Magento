<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Controller\Account;

use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Scr1be\BackInStock\Controller\Account\Alerts;

/**
 * The page itself is three lines; the test that matters is the one about the signature.
 *
 * `Magento\Customer\Controller\Plugin\Account::aroundExecute()` is what turns this controller into
 * an account page: it calls `Session::authenticate()`, which writes a login redirect onto the
 * response and answers `false` — and then the plugin falls out of its `if` returning nothing. A
 * narrowed return type on `execute()` is copied onto the generated interceptor, so that implicit
 * `null` becomes a TypeError and the guest gets an HTTP 500 where the redirect belonged.
 *
 * Calling `execute()` directly can never catch that: the interceptor is where the type is enforced,
 * and a unit test does not go through one. So the signature is asserted instead.
 */
class AlertsTest extends TestCase
{
    public function testExecuteDeclaresNoReturnType(): void
    {
        $method = new ReflectionMethod(Alerts::class, 'execute');

        $this->assertFalse(
            $method->hasReturnType(),
            'execute() must stay untyped: the customer plugin returns null for a guest, and a '
            . 'declared return type turns that redirect into a 500.'
        );
    }

    public function testExecuteReturnsAPageTitledMyProductAlerts(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())
            ->method('set')
            ->with($this->callback(
                static fn ($value): bool => (string) $value === 'My Product Alerts'
            ));

        $config = $this->createMock(Config::class);
        $config->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);

        $factory = $this->createMock(PageFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($page);

        $this->assertSame($page, (new Alerts($factory))->execute());
    }
}
