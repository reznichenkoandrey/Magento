<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Test\Unit;

use PHPUnit\Framework\TestCase;
use Scr1be\CouponTicket\Block\Widget\Ticket;

/**
 * The contracts that live between files rather than inside one.
 *
 * A component name in `x-data`, a template path in `widget.xml`, a script url in a block — none of
 * them is checked by PHP, by the browser, or by either of the other two test suites. They break
 * silently: the widget renders, Alpine logs nothing useful, and the button does nothing. Reading
 * the real files and asserting they agree is the cheapest place to catch that.
 *
 * The JavaScript specs in `Test/Js` cover the component's behaviour; this covers whether the
 * component the page asks for is the component the module ships.
 */
class TemplateContractTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    private const TEMPLATES = [
        'view/frontend/templates/widget/ticket.phtml',
        'view/frontend/templates/widget/ticket-compact.phtml',
    ];

    /**
     * Duplicated from `coupon-ticket.js` on purpose: the point of the assertion is that the two
     * agree, so reading the value out of the file under test would assert nothing.
     */
    private const COMPONENT_NAME = 'scr1beCouponTicket';

    public function testBothTemplatesAskForTheComponentTheModuleRegisters(): void
    {
        $this->assertStringContainsString(
            "export const COMPONENT_NAME = '" . self::COMPONENT_NAME . "'",
            $this->read('view/frontend/web/js/coupon-ticket.js')
        );

        foreach (self::TEMPLATES as $template) {
            $this->assertStringContainsString(
                'x-data="' . self::COMPONENT_NAME . '(',
                $this->read($template),
                $template . ' binds a component name nothing registers.'
            );
        }
    }

    public function testEveryTemplatePathOfferedInWidgetXmlExists(): void
    {
        preg_match_all('/value="(widget\/[^"]+\.phtml)"/', $this->read('etc/widget.xml'), $matches);

        $this->assertNotEmpty($matches[1], 'widget.xml offers no templates at all.');

        foreach ($matches[1] as $path) {
            $this->assertFileExists(
                self::MODULE_ROOT . '/view/frontend/templates/' . $path,
                'widget.xml offers a template that is not in the module.'
            );
        }
    }

    public function testTheBlockDefaultTemplateIsOneOfTheOfferedOnes(): void
    {
        // A widget placed through layout XML gets no `template` parameter and falls back to this
        // constant; a value that widget.xml does not offer would mean two different defaults.
        $this->assertStringContainsString(
            'value="' . Ticket::DEFAULT_TEMPLATE . '"',
            $this->read('etc/widget.xml')
        );
    }

    public function testEveryComponentMemberTheTemplatesBindToIsInTheModule(): void
    {
        $module = $this->read('view/frontend/web/js/coupon-ticket.js');

        foreach (self::TEMPLATES as $template) {
            $markup = $this->read($template);

            preg_match_all('/(?:@click|x-show|:disabled)="([a-zA-Z]+)"/', $markup, $matches);

            foreach (array_unique($matches[1]) as $member) {
                $this->assertMatchesRegularExpression(
                    '/\b' . preg_quote($member, '/') . '\b/',
                    $module,
                    sprintf('%s binds "%s", which the component does not define.', $template, $member)
                );
            }
        }
    }

    public function testTheTemplatesLoadTheModuleThroughTheBlockNotAHardcodedPath(): void
    {
        // getViewFileUrl() carries the deployment's static version and honours a separate static
        // domain; a literal /static/... path works in developer mode and 404s in production.
        foreach (self::TEMPLATES as $template) {
            $markup = $this->read($template);

            $this->assertStringContainsString('$block->getScriptUrl()', $markup);
            $this->assertStringNotContainsString('src="/static/', $markup);
        }
    }

    public function testTheBlockPointsAtTheFileThatExists(): void
    {
        $this->assertStringContainsString(
            "'Scr1be_CouponTicket::js/coupon-ticket.js'",
            $this->read('Block/Widget/Ticket.php')
        );

        $this->assertFileExists(self::MODULE_ROOT . '/view/frontend/web/js/coupon-ticket.js');
    }

    public function testThePackageExportsMapPointsAtTheFileTheBrowserLoads(): void
    {
        // The specs import through this map, so a stale entry means the specs pass against a file
        // the storefront no longer serves.
        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($package['exports'] as $target) {
            $this->assertFileExists(self::MODULE_ROOT . '/' . ltrim($target, './'));
        }
    }

    public function testTheWidgetXmlClassIsTheBlockThatExists(): void
    {
        $this->assertStringContainsString('class="' . Ticket::class . '"', $this->read('etc/widget.xml'));
    }

    public function testTheParameterNamesInWidgetXmlAreTheOnesTheBlockReads(): void
    {
        $widgetXml = $this->read('etc/widget.xml');

        foreach ([Ticket::PARAM_RULE_ID, Ticket::PARAM_HEADING, Ticket::PARAM_NOTE] as $parameter) {
            $this->assertStringContainsString(
                'name="' . $parameter . '"',
                $widgetXml,
                sprintf('The block reads "%s" but widget.xml never offers it.', $parameter)
            );
        }
    }

    private function read(string $relativePath): string
    {
        $path = self::MODULE_ROOT . '/' . $relativePath;

        $this->assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
