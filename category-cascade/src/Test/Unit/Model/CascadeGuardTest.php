<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\CascadeGuard;
use Scr1be\CategoryCascade\Model\Config;

class CascadeGuardTest extends TestCase
{
    private Config&MockObject $config;
    private CascadeGuard $guard;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isCascadeEnabled')->willReturn(true);
        $this->guard = new CascadeGuard($this->config);
    }

    public function testCascadesWhenAnEstablishedCategoryIsSwitchedOff(): void
    {
        $this->assertTrue($this->guard->shouldCascade($this->category()));
    }

    public function testDoesNotCascadeWhenTheKillSwitchIsOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->once())
            ->method('isCascadeEnabled')
            ->with(3)
            ->willReturn(false);

        $guard = new CascadeGuard($config);

        $this->assertFalse($guard->shouldCascade($this->category(['store_id' => 3])));
    }

    /**
     * A category created in this request has an id by the time the commit callback runs, so the
     * only reliable marker of "never loaded" is the missing pre-save snapshot.
     */
    public function testDoesNotCascadeForACategoryCreatedInThisRequest(): void
    {
        $this->assertFalse($this->guard->shouldCascade($this->category(['orig' => null])));
        $this->assertFalse($this->guard->shouldCascade($this->category(['is_new' => true])));
    }

    public function testDoesNotCascadeFromTheTreeRootOrAStoreRoot(): void
    {
        $this->assertFalse($this->guard->shouldCascade($this->category(['level' => 0, 'path' => '1'])));
        $this->assertFalse($this->guard->shouldCascade($this->category(['level' => 1, 'path' => '1/2'])));
    }

    /**
     * Re-enabling is a separate decision from the disable that preceded it, and reversing the
     * cascade would republish categories nobody asked to republish.
     */
    public function testReEnablingDoesNotCascade(): void
    {
        $category = $this->category([
            'orig' => ['is_active' => 0],
            'data' => ['is_active' => 1],
        ]);

        $this->assertFalse($this->guard->shouldCascade($category));
    }

    public function testASaveThatLeavesTheCategoryDisabledIsNotATransition(): void
    {
        $category = $this->category([
            'orig' => ['is_active' => 0],
            'data' => ['is_active' => 0],
        ]);

        $this->assertFalse($this->guard->shouldCascade($category));
    }

    /**
     * An import or an integration touching one unrelated attribute must not look like a disable
     * just because is_active is absent from the payload.
     */
    public function testASaveWithoutTheAttributeIsNotATransition(): void
    {
        $category = $this->category(['data' => ['name' => 'Gear']]);

        $this->assertFalse($this->guard->shouldCascade($category));
    }

    public function testASnapshotWithoutTheAttributeIsNotATransition(): void
    {
        $category = $this->category(['orig' => ['name' => 'Gear']]);

        $this->assertFalse($this->guard->shouldCascade($category));
    }

    public function testFallsBackToThePathWhenTheLevelIsMissing(): void
    {
        $deepEnough = $this->category(['level' => null, 'path' => '1/2/20/22']);
        $tooShallow = $this->category(['level' => null, 'path' => '1/2']);

        $this->assertTrue($this->guard->shouldCascade($deepEnough));
        $this->assertFalse($this->guard->shouldCascade($tooShallow));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function category(array $options = []): Category&MockObject
    {
        $options += [
            'store_id' => 0,
            'id' => 22,
            'is_new' => false,
            'orig' => ['is_active' => 1],
            'level' => 2,
            'path' => '1/2/22',
            'data' => ['is_active' => 0],
        ];

        $category = $this->createMock(Category::class);
        $category->method('getStoreId')->willReturn($options['store_id']);
        $category->method('getId')->willReturn($options['id']);
        $category->method('isObjectNew')->willReturn($options['is_new']);
        $category->method('getLevel')->willReturn($options['level']);
        $category->method('getPath')->willReturn($options['path']);
        $category->method('getOrigData')->willReturnCallback(
            static function ($key = null) use ($options) {
                if (!is_array($options['orig'])) {
                    return null;
                }

                return $key === null ? $options['orig'] : ($options['orig'][$key] ?? null);
            }
        );
        $category->method('hasData')->willReturnCallback(
            static fn ($key = '') => array_key_exists($key, $options['data'])
        );
        $category->method('getData')->willReturnCallback(
            static fn ($key = '') => $options['data'][$key] ?? null
        );

        return $category;
    }
}
