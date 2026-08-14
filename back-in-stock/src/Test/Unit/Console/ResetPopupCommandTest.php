<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Console;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Console\Command\ResetPopupCommand;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;
use Symfony\Component\Console\Tester\CommandTester;

class ResetPopupCommandTest extends TestCase
{
    private PopupStatusWriter&MockObject $writer;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private WebsiteRepositoryInterface&MockObject $websiteRepository;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(PopupStatusWriter::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->websiteRepository = $this->createMock(WebsiteRepositoryInterface::class);

        $this->tester = new CommandTester(
            new ResetPopupCommand($this->writer, $this->customerRepository, $this->websiteRepository)
        );
    }

    public function testTheBareFormRefusesRatherThanResettingEveryone(): void
    {
        // On a production database this would re-open a popup for every customer who has ever
        // dismissed one. `--all` has to be spelled out.
        $this->writer->expects($this->never())->method('requeueSent');

        $this->assertSame(Cli::RETURN_FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('--customer', $this->tester->getDisplay());
    }

    public function testTheTwoScopesCannotBeCombined(): void
    {
        $this->writer->expects($this->never())->method('requeueSent');

        $this->assertSame(
            Cli::RETURN_FAILURE,
            $this->tester->execute(['--customer' => 'a@b.c', '--all' => true])
        );
    }

    public function testResettingOneCustomer(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $this->customerRepository->method('get')->with('a@b.c', null)->willReturn($customer);

        $this->writer->expects($this->once())->method('requeueSent')->with(7, null)->willReturn(2);

        $this->assertSame(Cli::RETURN_SUCCESS, $this->tester->execute(['--customer' => 'a@b.c']));
        $this->assertStringContainsString('2 alerts re-queued.', $this->tester->getDisplay());
    }

    public function testTheWebsiteDisambiguatesACustomerEmail(): void
    {
        // Emails are unique per website, not globally, so an install with per-website account
        // sharing can legitimately hold the same address twice.
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(2);
        $this->websiteRepository->method('get')->with('eu')->willReturn($website);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $this->customerRepository->expects($this->once())->method('get')->with('a@b.c', 2)->willReturn($customer);

        $this->writer->expects($this->once())->method('requeueSent')->with(7, 2)->willReturn(1);

        $this->assertSame(
            Cli::RETURN_SUCCESS,
            $this->tester->execute(['--customer' => 'a@b.c', '--website' => 'eu'])
        );
        $this->assertStringContainsString('1 alert re-queued.', $this->tester->getDisplay());
    }

    public function testAnUnknownCustomerFailsWithTheReasonRatherThanAStackTrace(): void
    {
        $this->customerRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('No such entity with email = a@b.c')));

        $this->writer->expects($this->never())->method('requeueSent');

        $this->assertSame(Cli::RETURN_FAILURE, $this->tester->execute(['--customer' => 'a@b.c']));
        $this->assertStringContainsString('No such entity', $this->tester->getDisplay());
    }

    public function testTheGlobalResetPassesNoScopeAtAll(): void
    {
        $this->writer->expects($this->once())->method('requeueSent')->with(null, null)->willReturn(11);

        $this->assertSame(Cli::RETURN_SUCCESS, $this->tester->execute(['--all' => true]));
    }
}
