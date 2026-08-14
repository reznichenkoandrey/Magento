<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model\Content;

use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Content\BlockIdentifierMap;
use Scr1be\ContentTransfer\Model\Content\ContentTransformer;

class ContentTransformerTest extends TestCase
{
    private const LABEL = 'cms_page/home';

    public function testANumericBlockReferenceBecomesTheBlockIdentifier(): void
    {
        $result = $this->transformer()->toPortable(
            '<p>Hi</p>{{widget type="Magento\Cms\Block\Widget\Block" block_id="12"}}',
            self::LABEL
        );

        $this->assertStringContainsString('block_id="footer-links"', $result->getContent());
        $this->assertSame(['cms_page/home: block_id 12 -> "footer-links".'], $result->getTransforms());
        $this->assertSame([], $result->getWarnings());
    }

    public function testTheRestOfTheDirectiveSurvivesTheRewrite(): void
    {
        $result = $this->transformer()->toPortable(
            '{{widget type="Magento\Cms\Block\Widget\Block" block_id="12" '
            . 'template="widget/static_block/default.phtml"}}',
            self::LABEL
        );

        $this->assertSame(
            '{{widget type="Magento\Cms\Block\Widget\Block" block_id="footer-links" '
            . 'template="widget/static_block/default.phtml"}}',
            $result->getContent()
        );
    }

    public function testTheDeprecatedBlockDirectiveIsRewrittenToo(): void
    {
        $result = $this->transformer()->toPortable(
            '{{block class="Magento\Cms\Block\Block" block_id="34"}}',
            self::LABEL
        );

        $this->assertStringContainsString('block_id="sidebar-promo"', $result->getContent());
    }

    public function testALeadingBackslashOnTheClassIsStillRecognised(): void
    {
        // The WYSIWYG writes the class unescaped, but hand-edited content and page builder output
        // both turn up with a leading separator.
        $result = $this->transformer()->toPortable(
            '{{widget type="\Magento\Cms\Block\Widget\Block" block_id="12"}}',
            self::LABEL
        );

        $this->assertStringContainsString('block_id="footer-links"', $result->getContent());
    }

    public function testAReferenceToAMissingBlockIsWarnedAboutAndLeftAlone(): void
    {
        $content = '{{widget type="Magento\Cms\Block\Widget\Block" block_id="999"}}';
        $result = $this->transformer()->toPortable($content, self::LABEL);

        $this->assertSame($content, $result->getContent());
        $this->assertSame([], $result->getTransforms());
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString('999', $result->getWarnings()[0]);
    }

    public function testAnIdentifierThatIsAlreadyPortableIsNotTouchedOrReported(): void
    {
        $content = '{{widget type="Magento\Cms\Block\Widget\Block" block_id="footer-links"}}';
        $result = $this->transformer()->toPortable($content, self::LABEL);

        $this->assertSame($content, $result->getContent());
        $this->assertSame([], $result->getTransforms());
        $this->assertSame([], $result->getWarnings());
    }

    public function testABlockIdOnAnUnrelatedWidgetIsLeftAlone(): void
    {
        // `block_id` is not a reserved word. Rewriting it on a widget that means something else by
        // it would corrupt that widget's configuration.
        $content = '{{widget type="Acme\Promo\Block\Widget\Banner" block_id="12"}}';

        $this->assertSame($content, $this->transformer()->toPortable($content, self::LABEL)->getContent());
    }

    public function testAPageLinkIsWarnedAboutRatherThanRewritten(): void
    {
        // Magento\Cms\Helper\Page::getPageUrl() would resolve an identifier, but
        // Magento\Cms\Model\ResourceModel\Page::getCmsPageTitleById() binds (int)$id — so a
        // rewritten page link renders a working href with an empty label.
        $content = '{{widget type="Magento\Cms\Block\Widget\Page\Link" page_id="5"}}';
        $result = $this->transformer()->toPortable($content, self::LABEL);

        $this->assertSame($content, $result->getContent());
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString('page_id "5"', $result->getWarnings()[0]);
    }

    public function testAPageLinkThatAlreadyUsesAnHrefIsNotReported(): void
    {
        $content = '{{widget type="Magento\Cms\Block\Widget\Page\Link" href="about-us" '
            . 'anchor_text="About us"}}';
        $result = $this->transformer()->toPortable($content, self::LABEL);

        $this->assertSame([], $result->getWarnings());
    }

    public function testEveryReferenceInAPageIsHandled(): void
    {
        $result = $this->transformer()->toPortable(
            '{{widget type="Magento\Cms\Block\Widget\Block" block_id="12"}}'
            . '<hr/>'
            . '{{widget type="Magento\Cms\Block\Widget\Block" block_id="34"}}',
            self::LABEL
        );

        $this->assertStringContainsString('block_id="footer-links"', $result->getContent());
        $this->assertStringContainsString('block_id="sidebar-promo"', $result->getContent());
        $this->assertCount(2, $result->getTransforms());
    }

    public function testAWidgetInstanceBlockParameterIsRewrittenToo(): void
    {
        // Luma's own sample data writes it this way: Magento\WidgetSampleData\Model\CmsBlock does
        // setWidgetParameters(['block_id' => $block->getId()]). No directive scan would find it.
        $transforms = [];
        $warnings = [];

        $parameters = $this->transformer()->toPortableParameters(
            'Magento\Cms\Block\Widget\Block',
            ['block_id' => '12', 'template' => 'widget/static_block/default.phtml'],
            'widget_instance/block--footer-links',
            $transforms,
            $warnings
        );

        $this->assertSame('footer-links', $parameters['block_id']);
        $this->assertSame('widget/static_block/default.phtml', $parameters['template']);
        $this->assertCount(1, $transforms);
        $this->assertSame([], $warnings);
    }

    public function testAWidgetParameterPointingAtAMissingBlockIsWarnedAbout(): void
    {
        $transforms = [];
        $warnings = [];

        $parameters = $this->transformer()->toPortableParameters(
            'Magento\Cms\Block\Widget\Block',
            ['block_id' => '999'],
            'widget_instance/block--orphan',
            $transforms,
            $warnings
        );

        $this->assertSame('999', $parameters['block_id']);
        $this->assertSame([], $transforms);
        $this->assertCount(1, $warnings);
    }

    public function testAnotherWidgetsBlockIdParameterIsLeftAlone(): void
    {
        $transforms = [];
        $warnings = [];

        $parameters = $this->transformer()->toPortableParameters(
            'Acme\Promo\Block\Widget\Banner',
            ['block_id' => '12'],
            'widget_instance/banner--promo',
            $transforms,
            $warnings
        );

        $this->assertSame('12', $parameters['block_id']);
        $this->assertSame([], $transforms);
        $this->assertSame([], $warnings);
    }

    public function testEmptyContentIsNotAnError(): void
    {
        $result = $this->transformer()->toPortable(null, self::LABEL);

        $this->assertSame('', $result->getContent());
        $this->assertSame([], $result->getWarnings());
    }

    public function testMarkupWithNoDirectivesComesBackByteIdentical(): void
    {
        $content = '<div class="hero"><h1>Sale</h1><p>Up to 50% off {{ nothing }}</p></div>';

        $this->assertSame($content, $this->transformer()->toPortable($content, self::LABEL)->getContent());
    }

    private function transformer(): ContentTransformer
    {
        $map = $this->createMock(BlockIdentifierMap::class);
        $map->method('identifierFor')->willReturnMap([
            [12, 'footer-links'],
            [34, 'sidebar-promo'],
            [999, null],
        ]);

        return new ContentTransformer($map);
    }
}
