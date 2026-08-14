<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The cross-file promises nothing else checks.
 *
 * A template says `x-data="scr1beStoreSwitcherDrawer"` and a JavaScript file registers that name;
 * a template writes `data-scr1be-store-switcher-config` and the JavaScript queries for it; the
 * JavaScript base64-encodes a URL in the alphabet a PHP class in `vendor/` decodes. Every one of
 * those is a string in one file that has to equal a string in another, none of them is exercised
 * by a PHP unit test of a class, and all of them fail silently: the switcher renders, the select
 * works, and choosing a store does nothing at all.
 */
class TemplateContractTest extends TestCase
{
    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Walks up from the module looking for the framework, because the module can legitimately sit
     * at app/code/Scr1be/StoreSwitcher, at vendor/scr1be/store-toolkit-switcher, or inside this
     * portfolio repository — three different depths below the same `vendor/`.
     */
    private static function findEncoderSource(): ?string
    {
        $directory = self::moduleRoot();

        for ($level = 0; $level < 8; $level++) {
            $candidate = $directory . '/vendor/magento/framework/Url/Encoder.php';

            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }

    private function read(string $relativePath): string
    {
        $path = self::moduleRoot() . '/' . $relativePath;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testDesktopTemplateUsesTheComponentNameTheModuleRegisters(): void
    {
        $template = $this->read('view/frontend/templates/switcher/desktop.phtml');
        $js = $this->read('view/frontend/web/js/store-switcher.js');

        self::assertMatchesRegularExpression('/x-data="scr1beStoreSwitcherLinks"/', $template);
        self::assertStringContainsString("COMPONENT_LINKS = 'scr1beStoreSwitcherLinks'", $js);
    }

    public function testDrawerTemplateUsesTheComponentNameTheModuleRegisters(): void
    {
        $template = $this->read('view/frontend/templates/switcher/drawer.phtml');
        $js = $this->read('view/frontend/web/js/store-switcher.js');

        self::assertMatchesRegularExpression('/x-data="scr1beStoreSwitcherDrawer"/', $template);
        self::assertStringContainsString("COMPONENT_DRAWER = 'scr1beStoreSwitcherDrawer'", $js);
    }

    public function testTheConfigSelectorMatchesTheAttributeTheDrawerWrites(): void
    {
        $template = $this->read('view/frontend/templates/switcher/drawer.phtml');
        $js = $this->read('view/frontend/web/js/store-switcher.js');

        self::assertStringContainsString('data-scr1be-store-switcher-config', $template);
        self::assertStringContainsString(
            "CONFIG_SELECTOR = '[data-scr1be-store-switcher-config]'",
            $js
        );
    }

    /**
     * @dataProvider switcherTemplateProvider
     */
    public function testEveryHandlerNamedInATemplateExistsInTheComponent(string $templatePath): void
    {
        $template = $this->read($templatePath);
        $js = $this->read('view/frontend/web/js/store-switcher.js');

        preg_match_all('/@(?:change|click|input)="([A-Za-z0-9_]+)"/', $template, $matches);

        self::assertNotEmpty($matches[1], 'The template binds no handlers at all, which is unexpected.');

        foreach ($matches[1] as $handler) {
            self::assertStringContainsString(
                $handler . '()',
                $js,
                sprintf('%s binds "%s", which the component does not define.', $templatePath, $handler)
            );
        }
    }

    /**
     * @dataProvider switcherTemplateProvider
     */
    public function testEveryTemplateExposesTheRefTheComponentReads(string $templatePath): void
    {
        // The components read the selected value through $refs rather than off the event, so that
        // the x-on attribute can stay a bare method reference for strict-CSP builds of Alpine.
        self::assertStringContainsString('x-ref="select"', $this->read($templatePath));
        self::assertStringContainsString('this.$refs.select', $this->read('view/frontend/web/js/store-switcher.js'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function switcherTemplateProvider(): array
    {
        return [
            'desktop' => ['view/frontend/templates/switcher/desktop.phtml'],
            'drawer' => ['view/frontend/templates/switcher/drawer.phtml'],
        ];
    }

    /**
     * @dataProvider switcherTemplateProvider
     */
    public function testFlagReferencesMatchTheSymbolIdsTheSpriteEmits(string $templatePath): void
    {
        $sprite = $this->read('view/frontend/templates/switcher/flags.phtml');

        self::assertStringContainsString('id="scr1be-flag-', $sprite);
        self::assertStringContainsString('href="#scr1be-flag-', $this->read($templatePath));
    }

    public function testTheJavascriptUsesMagentosBase64UrlAlphabet(): void
    {
        // Read out of vendor, not out of memory. Magento\Framework\Url\Encoder::encode() is a
        // strtr over three character pairs, and the third one ("=" to "~") is the one that gets
        // mis-remembered; a wrong mapping produces a `uenc` that decodes to nothing, and core's
        // redirect then silently drops the visitor on the target store's home page instead of on
        // the page they were reading.
        $encoder = self::findEncoderSource();

        if ($encoder === null) {
            self::markTestSkipped('Magento framework sources are not available above this module.');
        }

        preg_match(
            '/strtr\(base64_encode\(\$url\), \'(.+?)\', \'(.+?)\'\)/',
            (string) file_get_contents($encoder),
            $matches
        );

        self::assertCount(3, $matches, 'Magento\Framework\Url\Encoder no longer has the shape this test reads.');
        self::assertSame(strlen($matches[1]), strlen($matches[2]));

        $js = $this->read('view/frontend/web/js/store-switcher.js');

        foreach (str_split($matches[1]) as $index => $character) {
            $replacement = $matches[2][$index];

            // Both spellings are accepted because a JavaScript regular expression literal needs a
            // backslash before some of these characters and not before others.
            $plain = sprintf("/%s/g, '%s'", $character, $replacement);
            $escaped = sprintf("/\\%s/g, '%s'", $character, $replacement);

            self::assertTrue(
                str_contains($js, $plain) || str_contains($js, $escaped),
                sprintf('The module does not map "%s" to "%s" the way core does.', $character, $replacement)
            );
        }
    }
}
