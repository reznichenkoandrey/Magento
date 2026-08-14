<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Api\Data\CustomerMatchInterfaceFactory;
use Scr1be\PosBridge\Api\Data\CustomerSearchResultInterfaceFactory;
use Scr1be\PosBridge\Model\Config;
use Scr1be\PosBridge\Model\CustomerLookup;
use Scr1be\PosBridge\Model\Data\CustomerMatch;
use Scr1be\PosBridge\Model\Data\CustomerSearchResult;
use Scr1be\PosBridge\Model\ResourceModel\CustomerMatchQuery;
use Scr1be\PosBridge\Model\Search\CustomerColumns;
use Scr1be\PosBridge\Model\Search\QueryTokenizer;

/**
 * The tokenizer is the real one. It is pure, it has its own test, and stubbing it here would mean
 * asserting the ladder against a query-splitting rule the module does not actually use.
 */
class CustomerLookupTest extends TestCase
{
    private const LIMIT = 3;

    private Config&MockObject $config;
    private CustomerMatchQuery&MockObject $matchQuery;
    private StoreManagerInterface&MockObject $storeManager;
    private CustomerLookup $lookup;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->matchQuery = $this->createMock(CustomerMatchQuery::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getResultLimit')->willReturn(self::LIMIT);

        $matchFactory = $this->createMock(CustomerMatchInterfaceFactory::class);
        $matchFactory->method('create')->willReturnCallback(
            static fn (array $data): CustomerMatch => new CustomerMatch(
                $data['customerId'],
                $data['name'],
                $data['email'],
                $data['billingName'],
                $data['billingTelephone'],
                $data['websiteId'],
                $data['groupId']
            )
        );

        $resultFactory = $this->createMock(CustomerSearchResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static fn (array $data): CustomerSearchResult => new CustomerSearchResult(
                $data['items'],
                $data['hasMore']
            )
        );

        $this->lookup = new CustomerLookup(
            $this->config,
            new QueryTokenizer(),
            $this->matchQuery,
            $this->storeManager,
            $matchFactory,
            $resultFactory
        );
    }

    /**
     * The switch is read before the input is, so a disabled bridge cannot be distinguished from an
     * enabled one by feeding it different queries.
     */
    public function testASwitchedOffBridgeRefusesBeforeItLooksAtTheQuery(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $lookup = new CustomerLookup(
            $config,
            new QueryTokenizer(),
            $this->matchQuery,
            $this->storeManager,
            $this->createMock(CustomerMatchInterfaceFactory::class),
            $this->createMock(CustomerSearchResultInterfaceFactory::class)
        );

        $this->matchQuery->expects($this->never())->method('fetch');

        // The query handed in is deliberately too short to pass validation: if the ladder ever
        // reorders, this test fails with an InputException and says so.
        try {
            $lookup->search('a');
            $this->fail('A switched-off bridge must refuse.');
        } catch (LocalizedException $refusal) {
            $this->assertNotInstanceOf(InputException::class, $refusal);
            $this->assertStringContainsString('switched off', $refusal->getMessage());
        }
    }

    public function testAQueryTooShortToBeUsefulIsRejected(): void
    {
        $this->matchQuery->expects($this->never())->method('fetch');
        $this->expectException(InputException::class);

        $this->lookup->search('ab');
    }

    /**
     * An empty list would read as "no such customer", and an operator told that stops looking.
     */
    public function testAnUnknownWebsiteIsAnErrorRatherThanAnEmptyList(): void
    {
        $this->storeManager->method('getWebsite')
            ->with(99)
            ->willThrowException(new NoSuchEntityException(new Phrase('no website')));

        $this->matchQuery->expects($this->never())->method('fetch');
        $this->expectException(NoSuchEntityException::class);

        $this->lookup->search('smith', 99);
    }

    public function testTheWebsiteIsValidatedAndThenPassedToTheQuery(): void
    {
        $this->storeManager->expects($this->once())->method('getWebsite')->with(1);

        $this->matchQuery->expects($this->once())
            ->method('fetch')
            ->with($this->anything(), 1, self::LIMIT)
            ->willReturn([]);

        $this->lookup->search('smith', 1);
    }

    public function testTheWholeInstallationIsSearchedWhenNoWebsiteIsGiven(): void
    {
        $this->storeManager->expects($this->never())->method('getWebsite');

        $this->matchQuery->expects($this->once())
            ->method('fetch')
            ->with($this->anything(), null, self::LIMIT)
            ->willReturn([]);

        $this->lookup->search('smith');
    }

    public function testEveryWordIsHandedToTheQueryAsItsOwnToken(): void
    {
        $this->matchQuery->expects($this->once())
            ->method('fetch')
            ->with($this->callback(static function (array $tokens): bool {
                return count($tokens) === 2
                    && $tokens[0]->getTerm() === 'jane'
                    && $tokens[1]->getTerm() === 'smith';
            }))
            ->willReturn([]);

        $this->lookup->search('jane smith');
    }

    public function testARowBecomesTheFieldsTheTerminalShows(): void
    {
        $this->matchQuery->method('fetch')->willReturn([$this->row()]);

        $items = $this->lookup->search('smith')->getItems();

        $this->assertCount(1, $items);
        $this->assertSame(7, $items[0]->getCustomerId());
        $this->assertSame('Jane Smith', $items[0]->getName());
        $this->assertSame('jane@example.com', $items[0]->getEmail());
        $this->assertSame('Jane Smith-Jones', $items[0]->getBillingName());
        $this->assertSame('(555) 229-3326', $items[0]->getBillingTelephone());
        $this->assertSame(1, $items[0]->getWebsiteId());
        $this->assertSame(2, $items[0]->getGroupId());
    }

    /**
     * A customer with no default billing address left-joins to nulls. Those must come out as "there
     * is nothing here" rather than as a stray space the terminal renders as a blank name.
     */
    public function testACustomerWithoutABillingAddressReportsNoBillingDetails(): void
    {
        $this->matchQuery->method('fetch')->willReturn([
            $this->row([
                CustomerColumns::BILLING_FIRSTNAME => null,
                CustomerColumns::BILLING_LASTNAME => null,
                CustomerColumns::BILLING_TELEPHONE => null,
            ]),
        ]);

        $match = $this->lookup->search('smith')->getItems()[0];

        $this->assertNull($match->getBillingName());
        $this->assertNull($match->getBillingTelephone());
    }

    public function testAnAccountWithOnlyASurnameStillProducesACleanName(): void
    {
        $this->matchQuery->method('fetch')->willReturn([
            $this->row([CustomerColumns::FIRSTNAME => '']),
        ]);

        $this->assertSame('Smith', $this->lookup->search('smith')->getItems()[0]->getName());
    }

    /**
     * The extra row exists only to answer "was the cap reached"; it must never reach the terminal.
     */
    public function testTheOverFetchedRowIsTrimmedAndReportedAsMore(): void
    {
        $this->matchQuery->method('fetch')->willReturn(array_fill(0, self::LIMIT + 1, $this->row()));

        $result = $this->lookup->search('smith');

        $this->assertCount(self::LIMIT, $result->getItems());
        $this->assertTrue($result->getHasMore());
    }

    public function testAFullPageThatIsNotOverTheCapIsNotReportedAsMore(): void
    {
        $this->matchQuery->method('fetch')->willReturn(array_fill(0, self::LIMIT, $this->row()));

        $result = $this->lookup->search('smith');

        $this->assertCount(self::LIMIT, $result->getItems());
        $this->assertFalse($result->getHasMore());
    }

    public function testNoMatchesIsAnEmptyResultRatherThanAnError(): void
    {
        $this->matchQuery->method('fetch')->willReturn([]);

        $result = $this->lookup->search('smith');

        $this->assertSame([], $result->getItems());
        $this->assertFalse($result->getHasMore());
    }

    /**
     * @param array<string, string|null> $overrides
     * @return array<string, string|null>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            CustomerColumns::CUSTOMER_ID => '7',
            CustomerColumns::FIRSTNAME => 'Jane',
            CustomerColumns::LASTNAME => 'Smith',
            CustomerColumns::EMAIL => 'jane@example.com',
            CustomerColumns::WEBSITE_ID => '1',
            CustomerColumns::GROUP_ID => '2',
            CustomerColumns::BILLING_FIRSTNAME => 'Jane',
            CustomerColumns::BILLING_LASTNAME => 'Smith-Jones',
            CustomerColumns::BILLING_TELEPHONE => '(555) 229-3326',
        ];
    }
}
