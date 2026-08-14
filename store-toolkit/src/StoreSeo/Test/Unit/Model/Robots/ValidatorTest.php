<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Robots;

use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Robots\Validator;

class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testAcceptsARealisticFile(): void
    {
        $content = <<<ROBOTS
        # Storefront
        User-agent: *
        Disallow: /checkout/
        Disallow: /customer/
        Allow: /

        User-agent: AhrefsBot
        Disallow: /

        Sitemap: https://example.com/sitemap.xml
        ROBOTS;

        self::assertSame([], $this->validator->validate($content));
    }

    public function testAcceptsEmptyContent(): void
    {
        self::assertSame([], $this->validator->validate(''));
    }

    public function testRejectsALineThatIsNotADirectivePair(): void
    {
        $violations = $this->validator->validate("User-agent: *\n/checkout/\n");

        self::assertCount(1, $violations);
        self::assertStringContainsString('is not a "Directive: value" pair', $this->text($violations[0]));
    }

    public function testRejectsAGroupDirectiveBeforeAnyUserAgent(): void
    {
        $violations = $this->validator->validate("Disallow: /checkout/\n");

        self::assertCount(1, $violations);
        self::assertStringContainsString('before any User-agent line', $this->text($violations[0]));
    }

    public function testRejectsAPathThatIsNotRooted(): void
    {
        $violations = $this->validator->validate("User-agent: *\nDisallow: checkout/\n");

        self::assertCount(1, $violations);
        self::assertStringContainsString('starts with neither', $this->text($violations[0]));
    }

    public function testAllowsAnEmptyDisallowValue(): void
    {
        // `Disallow:` with nothing after it is the canonical way to say "allow everything".
        self::assertSame([], $this->validator->validate("User-agent: *\nDisallow:\n"));
    }

    public function testAllowsAWildcardPath(): void
    {
        self::assertSame([], $this->validator->validate("User-agent: *\nDisallow: *.pdf\$\n"));
    }

    public function testRejectsARelativeSitemap(): void
    {
        $violations = $this->validator->validate("Sitemap: /sitemap.xml\n");

        self::assertCount(1, $violations);
        self::assertStringContainsString('not an absolute http(s) URL', $this->text($violations[0]));
    }

    public function testRejectsANonHttpSitemapScheme(): void
    {
        $violations = $this->validator->validate('Sitemap: ftp://example.com/sitemap.xml');

        self::assertCount(1, $violations);
    }

    public function testRejectsAUserAgentWithNoValue(): void
    {
        $violations = $this->validator->validate("User-agent:\nDisallow: /\n");

        self::assertCount(1, $violations);
        self::assertStringContainsString('no value', $this->text($violations[0]));
    }

    public function testRejectsControlCharacters(): void
    {
        $violations = $this->validator->validate("User-agent: *\nDisallow: /a\0b\n");

        self::assertNotSame([], $violations);
        self::assertStringContainsString('control characters', $this->text($violations[0]));
    }

    public function testRejectsContentOverTheSizeLimit(): void
    {
        $violations = $this->validator->validate(str_repeat('#', Validator::MAX_BYTES + 1));

        self::assertCount(1, $violations);
        self::assertStringContainsString('over the', $this->text($violations[0]));
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        self::assertSame([], $this->validator->validate("\n#  a comment: with a colon\n\nUser-agent: *\n"));
    }

    public function testReportsEveryViolationRatherThanTheFirst(): void
    {
        $violations = $this->validator->validate("Disallow: /a\nnonsense\nSitemap: relative.xml\n");

        self::assertCount(3, $violations);
    }

    private function text(Phrase $phrase): string
    {
        // getText() returns the untranslated template, so the assertion needs no translation
        // renderer to have been installed.
        return $phrase->getText();
    }
}
