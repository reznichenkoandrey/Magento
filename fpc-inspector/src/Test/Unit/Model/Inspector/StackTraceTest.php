<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model\Inspector;

use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\Inspector\StackTrace;

class StackTraceTest extends TestCase
{
    private StackTrace $stackTrace;

    protected function setUp(): void
    {
        $this->stackTrace = new StackTrace();
    }

    public function testAFrameReadsAsCalleeAndCallSite(): void
    {
        $frames = [
            [
                'function' => 'getValue',
                'class' => 'Magento\\Framework\\App\\PageCache\\Identifier',
                'type' => '::',
                'file' => '/srv/shop/vendor/magento/framework/App/PageCache/Identifier.php',
                'line' => 59,
            ],
        ];

        $this->assertSame(
            ['Magento\\Framework\\App\\PageCache\\Identifier::getValue'
                . ' (/srv/shop/vendor/magento/framework/App/PageCache/Identifier.php:59)'],
            $this->stackTrace->flatten($frames, 10)
        );
    }

    public function testInterceptionPlumbingIsDroppedSoTheRealCallerSurfaces(): void
    {
        $frames = [
            $this->frame('___callPlugins', 'Magento\\Framework\\App\\Http\\Context\\Interceptor'),
            $this->frame('{closure}', 'Magento\\Framework\\App\\Http\\Context\\Interceptor'),
            $this->frame('___callParent', 'Magento\\Framework\\App\\Http\\Context\\Interceptor'),
            $this->frame('getValue', 'Magento\\Framework\\App\\PageCache\\Identifier'),
        ];

        $flattened = $this->stackTrace->flatten($frames, 10);

        $this->assertCount(1, $flattened);
        $this->assertStringStartsWith('Magento\\Framework\\App\\PageCache\\Identifier::getValue', $flattened[0]);
    }

    public function testThePhp84ClosureNamingIsRecognisedAsPlumbingToo(): void
    {
        $frames = [
            $this->frame('{closure:/srv/shop/vendor/magento/framework/Interception/Interceptor.php:106}',
                'Magento\\Framework\\App\\Http\\Context\\Interceptor'),
            $this->frame('getValue', 'Magento\\Framework\\App\\PageCache\\Identifier'),
        ];

        $this->assertCount(1, $this->stackTrace->flatten($frames, 10));
    }

    public function testAClosureOutsideTheInterceptorIsKept(): void
    {
        // Only the interceptor's own plumbing closure is noise; an application closure is a caller.
        $frames = [
            $this->frame('{closure}', 'Magento\\Framework\\View\\Layout'),
        ];

        $this->assertCount(1, $this->stackTrace->flatten($frames, 10));
    }

    public function testThisModulesOwnFramesAreNeverReported(): void
    {
        $frames = [
            $this->frame('build', 'Scr1be\\FpcInspector\\Model\\RecordBuilder'),
            $this->frame('afterGetVaryString', 'Scr1be\\FpcInspector\\Plugin\\LogVaryString'),
            $this->frame('getValue', 'Magento\\Framework\\App\\PageCache\\Identifier'),
        ];

        $flattened = $this->stackTrace->flatten($frames, 10);

        $this->assertCount(1, $flattened);
        $this->assertStringNotContainsString('Scr1be\\FpcInspector\\Model', $flattened[0]);
        $this->assertStringNotContainsString('Scr1be\\FpcInspector\\Plugin', $flattened[0]);
    }

    public function testTheDepthLimitCountsSurvivingFramesNotRawOnes(): void
    {
        $frames = [
            $this->frame('___callPlugins', 'Magento\\Framework\\App\\Http\\Context\\Interceptor'),
            $this->frame('one', 'A'),
            $this->frame('two', 'B'),
            $this->frame('three', 'C'),
        ];

        $this->assertCount(2, $this->stackTrace->flatten($frames, 2));
    }

    public function testAPlainFunctionFrameCarriesNoClassSeparator(): void
    {
        $frames = [
            ['function' => 'call_user_func', 'file' => '/srv/shop/pub/index.php', 'line' => 30],
        ];

        $this->assertSame(
            ['call_user_func (/srv/shop/pub/index.php:30)'],
            $this->stackTrace->flatten($frames, 10)
        );
    }

    public function testAFrameWithNoFileIsStillReportable(): void
    {
        // Internal PHP callbacks produce frames without file or line; losing the caller entirely
        // would be worse than reporting it as unknown.
        $frames = [['function' => 'call_user_func_array', 'class' => 'A', 'type' => '->']];

        $this->assertSame(['A->call_user_func_array (unknown:0)'], $this->stackTrace->flatten($frames, 10));
    }

    public function testCaptureDropsItsOwnFrameAndReportsTheCaller(): void
    {
        // The seam between the module and PHP's backtrace: flatten() can be fed anything, but only
        // capture() decides what a real stack looks like, and getting the first frame wrong would
        // make every de-duplication fingerprint in the module wrong with it.
        $captured = $this->captureFromHelper();

        $this->assertNotEmpty($captured);
        $this->assertStringNotContainsString('Scr1be\\FpcInspector\\Model', $captured[0]);
        $this->assertStringContainsString('captureFromHelper', $captured[0]);
        $this->assertStringContainsString(basename(__FILE__), $captured[0]);
    }

    /**
     * @return string[]
     */
    private function captureFromHelper(): array
    {
        return $this->stackTrace->capture(3);
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(string $function, string $class): array
    {
        return [
            'function' => $function,
            'class' => $class,
            'type' => '::',
            'file' => '/srv/shop/vendor/example/File.php',
            'line' => 10,
        ];
    }
}
