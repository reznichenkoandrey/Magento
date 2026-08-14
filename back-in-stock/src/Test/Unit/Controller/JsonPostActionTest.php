<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Controller;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Controller\JsonPostAction;

/**
 * The CSRF behaviour, which is the reason this base class exists.
 *
 * `Magento\Framework\App\Request\CsrfValidator::validateRequest()` waves through any POST carrying
 * `X-Requested-With: XMLHttpRequest` without a form key, on every controller that does not implement
 * `CsrfAwareActionInterface`. Returning a non-null value from `validateForCsrf()` is what takes these
 * endpoints out of that branch.
 */
class JsonPostActionTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private FormKeyValidator&MockObject $formKeyValidator;
    private Json&MockObject $json;
    private JsonPostAction $action;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->formKeyValidator = $this->createMock(FormKeyValidator::class);

        $this->json = $this->createMock(Json::class);
        $this->json->method('setHttpResponseCode')->willReturnSelf();
        $this->json->method('setData')->willReturnSelf();

        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($this->json);

        $this->action = new class ($this->request, $factory, $this->formKeyValidator) extends JsonPostAction {
            public function execute(): Json
            {
                return $this->json(200, []);
            }

            /**
             * @return int[]
             */
            public function exposeAlertIds(): array
            {
                return $this->readAlertIds();
            }
        };
    }

    public function testTheFormKeyIsCheckedRatherThanDeferredToCore(): void
    {
        // Returning null here would hand the decision back to `CsrfValidator`, which is exactly the
        // code path that lets an XHR through unchecked.
        $this->formKeyValidator->expects($this->once())->method('validate')->willReturn(true);

        $this->assertTrue($this->action->validateForCsrf($this->request));
    }

    public function testAFailedCheckIsRefusedRatherThanRedirected(): void
    {
        // Core's default is a 302 to the referer. These endpoints are called by `fetch()`, which
        // follows a redirect silently and hands the caller some other page's HTML to parse as JSON.
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(403);

        $this->assertNotNull($this->action->createCsrfValidationException($this->request));
    }

    /**
     * @dataProvider alertIdInputs
     * @param mixed $raw
     * @param int[] $expected
     */
    public function testAlertIdsAreNormalisedWhateverTheBrowserSent($raw, array $expected): void
    {
        $this->request->method('getParam')->willReturn($raw);

        $this->assertSame($expected, $this->action->exposeAlertIds());
    }

    /**
     * @return array<string, array{0: mixed, 1: int[]}>
     */
    public static function alertIdInputs(): array
    {
        return [
            'repeated field' => [['4', '9'], [4, 9]],
            'comma separated' => ['4,9', [4, 9]],
            'duplicates collapsed' => [['4', 4, '4'], [4]],
            'zero and negatives dropped' => [['0', '-1', '4'], [4]],
            'words dropped' => [['nonsense', '4'], [4]],
            'absent' => [null, []],
            'an object instead of a list' => [new \stdClass(), []],
            'empty string' => ['', []],
        ];
    }
}
