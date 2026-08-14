<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The four places where a rename in one file silently breaks another.
 *
 * None of these are checked by anything else: a template referring to a component Alpine never
 * registered renders an empty div, a config selector that stopped matching produces a popup whose
 * buttons post nowhere, a section key that drifted produces a popup with nothing in it, and an
 * import-map specifier that no longer matches package.json means the browser and `node --test` are
 * running different code. All four fail quietly, in production, on a surface that is invisible until
 * a customer has an alert.
 */
class TemplateContractTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testTheTemplateAsksForTheComponentTheAdapterRegisters(): void
    {
        $name = $this->jsConstant('view/frontend/web/js/popup-register.js', 'COMPONENT_POPUP');

        $this->assertSame('scr1beBackInStockPopup', $name);
        $this->assertStringContainsString(
            'x-data="' . $name . '"',
            self::read('view/frontend/templates/popup.phtml')
        );
    }

    public function testTheAdapterLooksForTheElementTheScriptsTemplatePrints(): void
    {
        $selector = $this->jsConstant('view/frontend/web/js/popup-register.js', 'CONFIG_SELECTOR');
        $attribute = trim($selector, '[]');

        $this->assertStringContainsString(
            $attribute,
            self::read('view/frontend/templates/popup-scripts.phtml')
        );
    }

    public function testTheComponentReadsTheSectionKeyDiXmlRegisters(): void
    {
        $section = $this->jsConstant('view/frontend/web/js/popup.js', 'SECTION');

        $this->assertStringContainsString(
            '<item name="' . $section . '"',
            self::read('etc/frontend/di.xml')
        );
    }

    public function testEveryMethodTheTemplateCallsExistsOnTheComponent(): void
    {
        $template = self::read('view/frontend/templates/popup.phtml');
        $component = self::read('view/frontend/web/js/popup.js');

        // Alpine directives that hold a call: `@click`, `@keydown…`, `x-init`, and the
        // `private-content-loaded` binding.
        preg_match_all('/(?:@[a-z@.\-]+|x-init)="([a-zA-Z]+)\(/', $template, $matches);

        $called = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($called, 'The template stopped calling the component at all.');

        foreach ($called as $method) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($method, '/') . '\s*\(/',
                $component,
                sprintf('popup.phtml calls %s() and popup.js does not define it', $method)
            );
        }
    }

    public function testTheImportMapAndPackageJsonBindTheSameSpecifiers(): void
    {
        // The browser resolves bare specifiers through the import map the block prints; `node --test`
        // resolves them through package.json's exports. If the two lists drift, the specs are
        // testing files the storefront does not load.
        $block = self::read('Block/PopupScripts.php');
        preg_match_all("/'(scr1be-back-in-stock\/[a-z\-]+\.js)' => '([^']+)'/", $block, $matches);

        $aliases = array_combine($matches[1], $matches[2]);
        $this->assertNotEmpty($aliases);

        $exports = json_decode(self::read('package.json'), true, 512, JSON_THROW_ON_ERROR)['exports'];

        foreach ($aliases as $specifier => $viewFileId) {
            $key = './' . substr($specifier, strlen('scr1be-back-in-stock/'));

            $this->assertArrayHasKey($key, $exports, $specifier . ' is missing from package.json');
            $this->assertStringEndsWith(
                basename($viewFileId),
                $exports[$key],
                $specifier . ' points at a different file in each map'
            );
        }

        $this->assertCount(count($aliases), $exports, 'package.json exports a module the page never loads');
    }

    public function testEveryMappedScriptFileIsActuallyThere(): void
    {
        $block = self::read('Block/PopupScripts.php');
        preg_match_all("/=> 'Scr1be_BackInStock::js\/([a-z\-]+\.js)'/", $block, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $file) {
            $this->assertFileExists(self::root() . '/view/frontend/web/js/' . $file);
        }
    }

    /**
     * Reads `export const NAME = 'value';` out of a module without executing it.
     */
    private function jsConstant(string $relative, string $name): string
    {
        preg_match(
            '/export const ' . preg_quote($name, '/') . " = '([^']+)'/",
            self::read($relative),
            $matches
        );

        $this->assertNotEmpty($matches, $name . ' is no longer exported from ' . $relative);

        return $matches[1];
    }
}
