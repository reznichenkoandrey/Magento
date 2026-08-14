<?php
declare(strict_types=1);

namespace Scr1be\WishlistShare\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Wishlist\Model\Wishlist;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\WishlistShare\Model\Config;
use Scr1be\WishlistShare\Model\ShareOutcome;
use Scr1be\WishlistShare\Model\WishlistSharer;

/**
 * The behaviour the whole module exists for: one bad recipient must not cost the others, and the
 * transport's own words must never leave the building.
 */
class WishlistSharerTest extends TestCase
{
    private TransportBuilder&MockObject $transportBuilder;
    private LoggerInterface&MockObject $logger;
    private Wishlist&MockObject $wishlist;
    private StoreInterface&MockObject $store;

    /**
     * @var string[] Addresses the builder was asked to send to, in order.
     */
    private array $addressed = [];

    /**
     * @var array<string, \Throwable> Failures to inject, keyed by address.
     */
    private array $failures = [];

    private int $sharedCount = 0;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope'] as $method) {
            $this->transportBuilder->method($method)->willReturn($this->transportBuilder);
        }
        $this->transportBuilder->method('addTo')->willReturnCallback(
            function ($address) {
                $this->addressed[] = $address;

                return $this->transportBuilder;
            }
        );
        $this->transportBuilder->method('getTransport')->willReturnCallback(
            function () {
                $address = end($this->addressed);
                $transport = $this->createMock(TransportInterface::class);
                if (isset($this->failures[$address])) {
                    $transport->method('sendMessage')->willThrowException($this->failures[$address]);
                }

                return $transport;
            }
        );

        // `getShared`/`setShared` are DataObject magic, not declared methods, so PHPUnit needs
        // `addMethods()` for them and refuses `method()` otherwise.
        $this->wishlist = $this->getMockBuilder(Wishlist::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getCustomerId', 'getItemCollection', 'save'])
            ->addMethods(['getShared', 'setShared'])
            ->getMock();
        $this->wishlist->method('getId')->willReturn(5);
        $this->wishlist->method('getCustomerId')->willReturn(9);
        $this->wishlist->method('getItemCollection')->willReturn(new \ArrayIterator([]));
        $this->wishlist->method('getShared')->willReturnCallback(fn () => $this->sharedCount);
        $this->wishlist->method('setShared')->willReturnCallback(
            function ($count) {
                $this->sharedCount = (int)$count;

                return $this->wishlist;
            }
        );

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
    }

    public function testSendsToEveryValidRecipient(): void
    {
        $result = $this->sharer()->share(
            $this->wishlist,
            ['ada@example.com', 'grace@example.com'],
            'have a look',
            $this->store,
            'https://example.com/wishlist/shared/index/code/abc/'
        );

        $this->assertSame(['ada@example.com', 'grace@example.com'], $result['sent']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(['ada@example.com', 'grace@example.com'], $this->addressed);
    }

    /**
     * Core's storefront controller wraps the whole loop in one try/catch, so the first failure
     * abandons everyone after it. For an API that would force the client to re-send to the whole
     * list and produce duplicates.
     */
    public function testOneFailingRecipientDoesNotStopTheRest(): void
    {
        $this->failures['grace@example.com'] = new \RuntimeException('550 5.1.1 User unknown');

        $result = $this->sharer()->share(
            $this->wishlist,
            ['ada@example.com', 'grace@example.com', 'alan@example.com'],
            '',
            $this->store,
            'https://example.com/shared'
        );

        $this->assertSame(['ada@example.com', 'alan@example.com'], $result['sent']);
        $this->assertSame(
            [['email' => 'grace@example.com', 'reason' => ShareOutcome::DELIVERY_FAILED->value]],
            $result['failed']
        );
    }

    /**
     * "550 5.1.1 User unknown" tells the sender whether a mailbox exists.
     */
    public function testTheTransportsWordsGoToTheLogAndNotToTheCaller(): void
    {
        $this->failures['grace@example.com'] = new \RuntimeException('550 5.1.1 User unknown');
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('550 5.1.1 User unknown'));

        $result = $this->sharer()->share(
            $this->wishlist,
            ['grace@example.com'],
            '',
            $this->store,
            'https://example.com/shared'
        );

        $this->assertSame(
            [['email' => 'grace@example.com', 'reason' => ShareOutcome::DELIVERY_FAILED->value]],
            $result['failed']
        );
        $this->assertStringNotContainsString('550', json_encode($result) ?: '');
    }

    /**
     * A malformed address is caught before a transport is built, so it is distinguishable from a
     * delivery failure and costs nothing.
     */
    public function testAnInvalidAddressIsRejectedWithoutSending(): void
    {
        $result = $this->sharer()->share(
            $this->wishlist,
            ['not an address', 'ada@example.com'],
            '',
            $this->store,
            'https://example.com/shared'
        );

        $this->assertSame(['ada@example.com'], $result['sent']);
        $this->assertSame(
            [['email' => 'not an address', 'reason' => ShareOutcome::INVALID_ADDRESS->value]],
            $result['failed']
        );
        $this->assertSame(['ada@example.com'], $this->addressed, 'No transport for a malformed address');
    }

    /**
     * The per-list share allowance must count deliveries, not attempts.
     */
    public function testOnlySuccessfulSendsCountTowardsTheAllowance(): void
    {
        $this->failures['grace@example.com'] = new \RuntimeException('nope');
        $this->wishlist->expects($this->once())->method('save');

        $this->sharer()->share(
            $this->wishlist,
            ['ada@example.com', 'grace@example.com', 'bad address'],
            '',
            $this->store,
            'https://example.com/shared'
        );

        $this->assertSame(1, $this->sharedCount);
    }

    public function testARunThatSendsNothingDoesNotTouchTheWishlist(): void
    {
        $this->wishlist->expects($this->never())->method('save');

        $result = $this->sharer()->share(
            $this->wishlist,
            ['bad address'],
            '',
            $this->store,
            'https://example.com/shared'
        );

        $this->assertSame([], $result['sent']);
    }

    private function sharer(): WishlistSharer
    {
        $config = $this->createMock(Config::class);
        $config->method('getEmailTemplate')->willReturn('scr1be_wishlist_share_email_template');
        $config->method('getEmailIdentity')->willReturn('general');

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstname')->willReturn('Ada');
        $customer->method('getLastname')->willReturn('Lovelace');
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        return new WishlistSharer($config, $this->transportBuilder, $customerRepository, $this->logger);
    }
}
