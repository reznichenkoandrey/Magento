<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Controller\Adminhtml\MassExport\AbstractMassExport;
use Scr1be\ContentTransfer\Model\BundleDownload;

/**
 * The mass export must survive an `\Error`, not be killed by one.
 *
 * `ManagerInterface::addExceptionMessage()` declares `\Exception $exception` natively. The
 * controller catches `\Throwable` on purpose — a bad selection arrives from a form, so failure is
 * a normal outcome and the operator belongs back on the grid with a message. Handing an `\Error`
 * straight to that method raises a `TypeError` *inside* the catch, so the widest catch in the
 * class produces exactly the stack trace it was written to prevent, and the redirect never
 * happens.
 *
 * `collectKeys()` is a real source of `\Error`: it runs `Filter`, which runs the grid's data
 * provider and every plugin on it.
 */
class MassExportErrorCauseTest extends TestCase
{
    private ManagerInterface&MockObject $messageManager;
    private Filter&MockObject $filter;
    private BundleDownload&MockObject $bundleDownload;
    private Redirect&MockObject $redirect;

    /** Whatever reached addExceptionMessage(), in call order. */
    private array $reported = [];

    protected function setUp(): void
    {
        $this->filter = $this->createMock(Filter::class);
        $this->bundleDownload = $this->createMock(BundleDownload::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();

        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->messageManager->method('addExceptionMessage')->willReturnCallback(
            function ($exception) {
                $this->reported[] = $exception;

                return $this->messageManager;
            }
        );
    }

    /**
     * `collectKeys()` is the abstract hook each concrete mass action fills in, and it is where an
     * `\Error` realistically originates — it drives `Filter`, which drives the grid's data
     * provider and every plugin on it. The subclass here delegates to a callable so a test can be
     * that hook, rather than staging a failure several mocks deep and hoping it surfaces.
     *
     * @param callable():array $collectKeys
     */
    private function controller(callable $collectKeys): AbstractMassExport
    {
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($this->redirect);

        $context = $this->createMock(Context::class);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $controller = new class ($context, $this->filter, $this->bundleDownload) extends AbstractMassExport {
            /** @var callable():array */
            public $collectKeysWith;

            protected function getPorterCode(): string
            {
                return 'cms_page';
            }

            protected function collectKeys(Filter $filter): array
            {
                return ($this->collectKeysWith)();
            }
        };
        $controller->collectKeysWith = $collectKeys;

        return $controller;
    }

    public function testAnErrorWhileReadingTheSelectionStillRedirectsInsteadOfGoingFatal(): void
    {
        $result = $this->controller(static fn (): array => throw new \TypeError('provider blew up'))->execute();

        self::assertSame($this->redirect, $result);
        self::assertCount(1, $this->reported);
        self::assertInstanceOf(\Exception::class, $this->reported[0]);
        self::assertSame('provider blew up', $this->reported[0]->getMessage());
    }

    public function testTheOriginalErrorStaysReachableInTheChain(): void
    {
        $original = new \TypeError('provider blew up');

        $this->controller(static fn (): array => throw $original)->execute();

        self::assertSame($original, $this->reported[0]->getPrevious());
    }

    public function testAnErrorWhileBuildingTheBundleIsReportedTheSameWay(): void
    {
        $this->bundleDownload->method('create')->willThrowException(new \DivisionByZeroError('nope'));

        $result = $this->controller(static fn (): array => ['page-1'])->execute();

        self::assertSame($this->redirect, $result);
        self::assertCount(1, $this->reported);
        self::assertInstanceOf(\Exception::class, $this->reported[0]);
        self::assertSame('nope', $this->reported[0]->getMessage());
    }

    public function testARealExceptionIsReportedWithoutBeingWrapped(): void
    {
        // Wrapping unconditionally would bury the type an operator-facing handler may inspect.
        $original = new \RuntimeException('disk full');

        $this->controller(static fn (): array => throw $original)->execute();

        self::assertSame($original, $this->reported[0]);
    }

    public function testAnEmptySelectionIsRejectedWithoutReachingTheBundleBuilder(): void
    {
        // Unchanged behaviour, asserted because the wrapping edit sits between these two branches.
        $this->bundleDownload->expects(self::never())->method('create');

        $result = $this->controller(static fn (): array => [])->execute();

        self::assertSame($this->redirect, $result);
        self::assertSame([], $this->reported);
    }
}
