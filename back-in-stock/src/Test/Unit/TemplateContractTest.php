<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The places where a rename in one file silently breaks another.
 *
 * None of these are checked by anything else: a template referring to a component Alpine never
 * registered renders an empty div, a config selector that stopped matching produces a popup whose
 * buttons post nowhere, a section key that drifted produces a popup with nothing in it, and a bare
 * specifier added to a storefront module resolves against a map this page does not ship. All of them
 * fail quietly, in production, on a surface that is invisible until a customer has an alert — and
 * the last one fails in Firefox only, which is how it survived a Chromium-only check once already.
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

    /**
     * This module ships no import map, and must not start.
     *
     * It renders from `default.xml`, so its head block is on every page — behind
     * `Scr1be_HyvaProductSlider`, whose map is ordered `before="-"` in the same container. A
     * document installs the first import map it declares and Firefox rejects the rest, so a map
     * printed here is dead on arrival in Firefox and merely redundant in Chromium. That is exactly
     * how the storefront came to ship two maps while a commit message recorded one.
     */
    public function testTheScriptsTemplateShipsNoImportMap(): void
    {
        $this->assertStringNotContainsString(
            'type="importmap"',
            self::read('view/frontend/templates/popup-scripts.phtml'),
            'This module must not print an import map — the slider owns the only one on the page.'
        );
    }

    /**
     * The reason the missing map costs nothing: every storefront module here imports its siblings
     * relatively. A bare specifier added to one of these files would resolve against a map this page
     * does not ship, and would fail in Firefox only.
     */
    public function testTheStorefrontModulesImportRelatively(): void
    {
        $modules = ['popup-register.js', 'popup.js', 'alert-client.js'];
        $seen = [];

        foreach ($modules as $module) {
            preg_match_all(
                '/^\s*import\s+[^;]*?\sfrom\s+[\'"]([^\'"]+)[\'"]/m',
                self::read('view/frontend/web/js/' . $module),
                $matches
            );

            foreach ($matches[1] as $specifier) {
                $seen[] = $specifier;
                $this->assertStringStartsWith(
                    '.',
                    $specifier,
                    sprintf('%s imports the bare specifier "%s" and this page ships no import map', $module, $specifier)
                );
            }
        }

        // Without this the test passes when the pattern stops matching — the entry module is known
        // to import both of its siblings, so an empty result means the check, not the code, broke.
        $this->assertNotEmpty($seen, 'No import statement was recognised in any storefront module.');
    }

    /**
     * `package.json` is the Node half on its own now: the specs name these entry points, the browser
     * never does. It still has to point at files that exist, or the specs test nothing.
     */
    public function testEveryFileExportedToNodeIsActuallyThere(): void
    {
        $exports = json_decode(self::read('package.json'), true, 512, JSON_THROW_ON_ERROR)['exports'];
        $this->assertNotEmpty($exports);

        foreach ($exports as $specifier => $relative) {
            $this->assertFileExists(
                self::root() . '/' . ltrim($relative, './'),
                $specifier . ' is exported to node --test but the file is gone'
            );
        }
    }

    public function testTheEntryModuleTheBlockPointsAtExists(): void
    {
        preg_match(
            "/ENTRY_FILE = 'Scr1be_BackInStock::js\/([a-z\-]+\.js)'/",
            self::read('Block/PopupScripts.php'),
            $matches
        );

        $this->assertNotEmpty($matches, 'PopupScripts no longer names an entry file.');
        $this->assertFileExists(self::root() . '/view/frontend/web/js/' . $matches[1]);
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
