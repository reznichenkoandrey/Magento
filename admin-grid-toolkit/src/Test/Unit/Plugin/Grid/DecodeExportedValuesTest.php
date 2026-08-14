<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Test\Unit\Plugin\Grid;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\AdminGridToolkit\Model\Config;
use Scr1be\AdminGridToolkit\Model\Export\ValueDecoder;
use Scr1be\AdminGridToolkit\Plugin\Grid\DecodeExportedValues;

class DecodeExportedValuesTest extends TestCase
{
    private Config&MockObject $config;
    private ValueDecoder&MockObject $decoder;
    private AbstractRenderer&MockObject $renderer;
    private DecodeExportedValues $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->decoder = $this->createMock(ValueDecoder::class);
        $this->renderer = $this->createMock(AbstractRenderer::class);

        $this->plugin = new DecodeExportedValues($this->config, $this->decoder);
    }

    public function testARenderedCellIsDecoded(): void
    {
        $this->config->method('isExportDecodingEnabled')->willReturn(true);
        $this->decoder->expects($this->once())
            ->method('decode')
            ->with('Hoodies &amp; Sweatshirts')
            ->willReturn('Hoodies & Sweatshirts');

        $this->assertSame(
            'Hoodies & Sweatshirts',
            $this->plugin->afterRenderExport($this->renderer, 'Hoodies &amp; Sweatshirts')
        );
    }

    public function testTheFixCanBeSwitchedOff(): void
    {
        $this->config->method('isExportDecodingEnabled')->willReturn(false);
        $this->decoder->expects($this->never())->method('decode');

        $this->assertSame(
            'Hoodies &amp; Sweatshirts',
            $this->plugin->afterRenderExport($this->renderer, 'Hoodies &amp; Sweatshirts')
        );
    }

    /**
     * A Phrase is a translated literal rather than row data, and a renderer that answers with one
     * has to keep getting that type back.
     *
     * @dataProvider passThroughProvider
     */
    public function testAnythingThatIsNotANonEmptyStringPassesThrough(mixed $result): void
    {
        $this->config->expects($this->never())->method('isExportDecodingEnabled');
        $this->decoder->expects($this->never())->method('decode');

        $this->assertSame($result, $this->plugin->afterRenderExport($this->renderer, $result));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function passThroughProvider(): array
    {
        return [
            'phrase' => [new Phrase('All Store Views')],
            'null' => [null],
            'empty string' => [''],
            'integer' => [42],
        ];
    }
}
