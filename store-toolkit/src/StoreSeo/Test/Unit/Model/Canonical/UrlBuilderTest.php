<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Canonical;

use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Canonical\UrlBuilder;

class UrlBuilderTest extends TestCase
{
    private UrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new UrlBuilder();
    }

    public function testDropsEveryParameterThatIsNotWhitelisted(): void
    {
        $url = $this->builder->build(
            'https://example.com/',
            '/women/tops-women.html',
            ['p' => '2', 'product_list_order' => 'name', 'color' => '58', 'gclid' => 'abc'],
            ['p']
        );

        self::assertSame('https://example.com/women/tops-women.html?p=2', $url);
    }

    public function testDropsTheStoreEcho(): void
    {
        // ___store and ___from_store are never in the whitelist, because Model\Config removes them
        // from it. The builder only has to not reinvent them.
        $url = $this->builder->build(
            'https://example.com/',
            '/women/tops-women.html',
            ['___store' => 'de', '___from_store' => 'default'],
            ['p']
        );

        self::assertSame('https://example.com/women/tops-women.html', $url);
    }

    public function testOrdersParametersSoThatTwoSpellingsOfOnePageAgree(): void
    {
        $whitelist = ['p', 'product_list_limit'];

        $first = $this->builder->build('https://example.com/', '/c.html', ['p' => '2', 'product_list_limit' => '36'], $whitelist);
        $second = $this->builder->build('https://example.com/', '/c.html', ['product_list_limit' => '36', 'p' => '2'], $whitelist);

        self::assertSame($first, $second);
        self::assertSame('https://example.com/c.html?p=2&product_list_limit=36', $first);
    }

    public function testKeepsTheStoreCodeExactlyOnceWhenItIsInTheBaseUrl(): void
    {
        // With web/url/use_store on, the base URL already carries the code and the path info has
        // had it stripped. Naively concatenating a raw REQUEST_URI here would produce /de/de/.
        $url = $this->builder->build('https://example.com/de/', '/damen/tops.html', [], []);

        self::assertSame('https://example.com/de/damen/tops.html', $url);
    }

    public function testHomePageCanonicalIsTheBaseUrl(): void
    {
        self::assertSame('https://example.com/', $this->builder->build('https://example.com/', '/', [], []));
    }

    public function testEmitsNoTrailingQuestionMarkWhenNothingSurvives(): void
    {
        $url = $this->builder->build('https://example.com/', '/c.html', ['color' => '58'], ['p']);

        self::assertSame('https://example.com/c.html', $url);
    }
}
