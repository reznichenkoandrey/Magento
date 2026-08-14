<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;
use Scr1be\OrderAttribution\Model\Resolver\AvailableOrderSources;
use Scr1be\OrderAttribution\Model\Resolver\Cache\AvailableOrderSourcesIdentity;
use Scr1be\OrderAttribution\Model\Source;

class AvailableOrderSourcesTest extends TestCase
{
    private SourceRepositoryInterface&MockObject $repository;
    private AvailableOrderSources $resolver;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SourceRepositoryInterface::class);
        $this->resolver = new AvailableOrderSources($this->repository);
    }

    public function testProjectsCodeAndLabelOnly(): void
    {
        $this->repository->method('getActive')->willReturn([
            $this->source('web', 'Website'),
            $this->source('ios-app', 'iOS App'),
        ]);

        $this->assertSame(
            [
                ['code' => 'web', 'label' => 'Website'],
                ['code' => 'ios-app', 'label' => 'iOS App'],
            ],
            $this->resolve()
        );
    }

    /**
     * A list type is `[OrderSource!]!` — a null would be a schema violation, and returning `[]` is
     * the honest answer for a registry nobody has populated yet.
     */
    public function testAnEmptyRegistryIsAnEmptyList(): void
    {
        $this->repository->method('getActive')->willReturn([]);

        $this->assertSame([], $this->resolve());
    }

    /**
     * Caching an empty list would hide the first source a merchant creates behind a cache flush.
     */
    public function testTheCacheIdentityIsEmptyForAnEmptyList(): void
    {
        $identity = new AvailableOrderSourcesIdentity();

        $this->assertSame([], $identity->getIdentities([]));
        $this->assertSame([Source::CACHE_TAG], $identity->getIdentities([['code' => 'web', 'label' => 'W']]));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function resolve(): array
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            []
        );
    }

    private function source(string $code, string $label): SourceInterface&MockObject
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getCode')->willReturn($code);
        $source->method('getLabel')->willReturn($label);

        return $source;
    }
}
