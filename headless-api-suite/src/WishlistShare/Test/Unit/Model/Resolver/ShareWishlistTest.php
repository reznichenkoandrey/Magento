<?php
declare(strict_types=1);

namespace Scr1be\WishlistShare\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\UrlInterface;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Wishlist\Model\ResourceModel\Wishlist as WishlistResource;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\Wishlist\Config as WishlistModuleConfig;
use Magento\Wishlist\Model\WishlistFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\WishlistShare\Model\Config;
use Scr1be\WishlistShare\Model\Resolver\ShareWishlist;
use Scr1be\WishlistShare\Model\WishlistSharer;

/**
 * Authorisation and input validation, which is all this resolver does before handing off.
 *
 * `ContextExtensionInterface` exists in two shapes: an empty stub produced by the unit-test
 * autoloader on a bare checkout (core's own GraphQL unit tests assume this and use `addMethods()`),
 * and the real generated interface on an installed store, where `addMethods()` on a declared method
 * is fatal. `extensionAttributesMock()` picks per method so both environments pass.
 */
class ShareWishlistTest extends TestCase
{
    private const CUSTOMER_ID = 9;

    private WishlistSharer&MockObject $sharer;
    private WishlistResource&MockObject $wishlistResource;
    private Wishlist&MockObject $wishlist;
    private ShareWishlist $resolver;

    protected function setUp(): void
    {
        $this->wishlist = $this->getMockBuilder(Wishlist::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getCustomerId', 'generateSharingCode', 'save'])
            ->addMethods(['getSharingCode'])
            ->getMock();
        $this->wishlist->method('getId')->willReturn(5);
        $this->wishlist->method('getCustomerId')->willReturn(self::CUSTOMER_ID);
        $this->wishlist->method('getSharingCode')->willReturn('code-abc');

        $factory = $this->createMock(WishlistFactory::class);
        $factory->method('create')->willReturn($this->wishlist);

        $this->wishlistResource = $this->createMock(WishlistResource::class);

        $moduleConfig = $this->createMock(WishlistModuleConfig::class);
        $moduleConfig->method('isEnabled')->willReturn(true);

        $this->sharer = $this->createMock(WishlistSharer::class);
        $this->sharer->method('share')->willReturn(['sent' => ['ada@example.com'], 'failed' => []]);

        $config = $this->createMock(Config::class);
        $config->method('getRecipientLimit')->willReturn(3);
        $config->method('getMessageLimit')->willReturn(20);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.com/wishlist/shared/index/code/code-abc/');

        $this->resolver = new ShareWishlist(
            $factory,
            $this->wishlistResource,
            $moduleConfig,
            $this->sharer,
            $config,
            $url
        );
    }

    public function testReturnsTheFrozenShape(): void
    {
        $result = $this->resolve(['wishlist_id' => 5, 'emails' => ['ada@example.com']]);

        $this->assertSame(
            [
                'wishlist_id' => 5,
                'shared_url' => 'https://example.com/wishlist/shared/index/code/code-abc/',
                'sent' => ['ada@example.com'],
                'failed' => [],
            ],
            $result
        );
    }

    public function testRefusesAnUnauthenticatedCaller(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->resolve(['emails' => ['ada@example.com']], customerId: 0);
    }

    /**
     * The oracle test. A wishlist that is not there and a wishlist belonging to somebody else have to
     * be indistinguishable, or the mutation becomes a way to enumerate which ids exist.
     */
    public function testAMissingAndAForeignWishlistFailIdentically(): void
    {
        $missing = $this->captureFailure(fn () => $this->resolveWithWishlist(null, 0));
        $foreign = $this->captureFailure(fn () => $this->resolveWithWishlist(5, 4242));

        $this->assertSame($missing[0], $foreign[0], 'The same exception class');
        $this->assertSame($missing[1], $foreign[1], 'The same message');
    }

    public function testRequiresAtLeastOneRecipient(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->resolve(['wishlist_id' => 5, 'emails' => ['   ']]);
    }

    public function testEnforcesTheRecipientLimit(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->resolve([
            'wishlist_id' => 5,
            'emails' => ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'],
        ]);
    }

    /**
     * Two spellings of the same address are one recipient. Otherwise the limit is easy to game and
     * the recipient gets two copies.
     */
    public function testDeduplicatesRecipientsCaseInsensitively(): void
    {
        $captured = null;
        $this->sharer = $this->createMock(WishlistSharer::class);
        $this->sharer->method('share')->willReturnCallback(
            static function ($wishlist, array $emails) use (&$captured) {
                $captured = $emails;

                return ['sent' => $emails, 'failed' => []];
            }
        );
        $this->rebuildResolver();

        $this->resolve(['wishlist_id' => 5, 'emails' => ['Ada@Example.com', 'ada@example.com']]);

        $this->assertSame(['Ada@Example.com'], $captured);
    }

    public function testEnforcesTheMessageLimit(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->resolve([
            'wishlist_id' => 5,
            'emails' => ['ada@example.com'],
            'message' => str_repeat('x', 21),
        ]);
    }

    /**
     * @param callable $call
     * @return array{0: string, 1: string}
     */
    private function captureFailure(callable $call): array
    {
        try {
            $call();
            $this->fail('Expected refusal');
        } catch (GraphQlNoSuchEntityException $e) {
            return [$e::class, $e->getMessage()];
        }
    }

    /**
     * @param int|null $wishlistId
     * @param int $ownerId
     * @return void
     */
    private function resolveWithWishlist(?int $wishlistId, int $ownerId): void
    {
        $wishlist = $this->getMockBuilder(Wishlist::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getCustomerId'])
            ->getMock();
        $wishlist->method('getId')->willReturn($wishlistId);
        $wishlist->method('getCustomerId')->willReturn($ownerId);

        $factory = $this->createMock(WishlistFactory::class);
        $factory->method('create')->willReturn($wishlist);

        $moduleConfig = $this->createMock(WishlistModuleConfig::class);
        $moduleConfig->method('isEnabled')->willReturn(true);

        $config = $this->createMock(Config::class);
        $config->method('getRecipientLimit')->willReturn(3);
        $config->method('getMessageLimit')->willReturn(20);

        $resolver = new ShareWishlist(
            $factory,
            $this->wishlistResource,
            $moduleConfig,
            $this->sharer,
            $config,
            $this->createMock(UrlInterface::class)
        );

        $resolver->resolve(
            $this->createMock(Field::class),
            $this->context(self::CUSTOMER_ID),
            $this->createMock(ResolveInfo::class),
            null,
            ['input' => ['wishlist_id' => 5, 'emails' => ['ada@example.com']]]
        );
    }

    private function rebuildResolver(): void
    {
        $factory = $this->createMock(WishlistFactory::class);
        $factory->method('create')->willReturn($this->wishlist);

        $moduleConfig = $this->createMock(WishlistModuleConfig::class);
        $moduleConfig->method('isEnabled')->willReturn(true);

        $config = $this->createMock(Config::class);
        $config->method('getRecipientLimit')->willReturn(3);
        $config->method('getMessageLimit')->willReturn(20);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.com/shared');

        $this->resolver = new ShareWishlist(
            $factory,
            $this->wishlistResource,
            $moduleConfig,
            $this->sharer,
            $config,
            $url
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param int $customerId
     * @return array<string, mixed>
     */
    private function resolve(array $input, int $customerId = self::CUSTOMER_ID): array
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->context($customerId),
            $this->createMock(ResolveInfo::class),
            null,
            ['input' => $input]
        );
    }

    /**
     * @param int $customerId
     * @return ContextInterface&MockObject
     */
    private function context(int $customerId): ContextInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $extension = $this->extensionAttributesMock(['getStore']);
        $extension->method('getStore')->willReturn($store);

        $context = $this->getMockBuilder(ContextInterface::class)
            ->onlyMethods(['getExtensionAttributes', 'getUserId', 'getUserType'])
            ->getMockForAbstractClass();
        $context->method('getExtensionAttributes')->willReturn($extension);
        $context->method('getUserId')->willReturn($customerId);

        return $context;
    }

    /**
     * Mock the context's extension attributes, whichever version of the interface is loaded.
     *
     * See the class docblock: PHPUnit throws rather than tolerating `addMethods()` on a declared
     * method or `onlyMethods()` on an undeclared one, so reflection decides instead of the
     * environment.
     *
     * @param string[] $methods
     * @return ContextExtensionInterface&MockObject
     */
    private function extensionAttributesMock(array $methods): ContextExtensionInterface
    {
        $declared = get_class_methods(ContextExtensionInterface::class);
        $builder = $this->getMockBuilder(ContextExtensionInterface::class);

        $existing = array_values(array_intersect($methods, $declared));
        $missing = array_values(array_diff($methods, $declared));

        if ($existing !== []) {
            $builder->onlyMethods($existing);
        }
        if ($missing !== []) {
            $builder->addMethods($missing);
        }

        return $builder->getMockForAbstractClass();
    }
}
