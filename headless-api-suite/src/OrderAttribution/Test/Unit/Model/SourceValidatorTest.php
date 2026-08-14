<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Test\Unit\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;
use Scr1be\OrderAttribution\Model\Attribution;
use Scr1be\OrderAttribution\Model\SourceValidator;
use Scr1be\OrderAttribution\Model\UnknownSourceException;

/**
 * The validator is the boundary between an untrusted client and a reporting column.
 */
class SourceValidatorTest extends TestCase
{
    private SourceRepositoryInterface&MockObject $repository;
    private SourceValidator $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SourceRepositoryInterface::class);
        $this->validator = new SourceValidator($this->repository);
    }

    public function testNoInputIsNotAnError(): void
    {
        $this->repository->expects($this->never())->method('getByCode');

        $this->assertNull($this->validator->validate(null));
    }

    public function testAnEmptyCodeIsTreatedAsNoInput(): void
    {
        $this->repository->expects($this->never())->method('getByCode');

        $this->assertNull($this->validator->validate(['source_code' => '  ']));
    }

    public function testAcceptsAnActiveSource(): void
    {
        $this->repository->method('getByCode')->with('ios-app')->willReturn($this->source('ios-app', true));

        $attribution = $this->validator->validate(['source_code' => 'ios-app', 'source_detail' => 'build 412']);

        $this->assertInstanceOf(Attribution::class, $attribution);
        $this->assertSame('ios-app', $attribution->sourceCode);
        $this->assertSame('build 412', $attribution->detail);
    }

    /**
     * The stored code comes from the registry row, not from the request, so a client sending a code
     * that differs only in whitespace cannot create a second value in the reports.
     */
    public function testStoresTheRegistrysCodeRatherThanTheClients(): void
    {
        $this->repository->method('getByCode')->willReturn($this->source('ios-app', true));

        $attribution = $this->validator->validate(['source_code' => '  ios-app  ']);

        $this->assertSame('ios-app', $attribution?->sourceCode);
    }

    public function testRejectsAnUnknownCode(): void
    {
        $this->repository->method('getByCode')
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));

        $this->expectException(UnknownSourceException::class);
        $this->validator->validate(['source_code' => 'made-up']);
    }

    /**
     * Deactivation has to refuse new traffic, or it is only a label.
     */
    public function testRejectsADeactivatedCode(): void
    {
        $this->repository->method('getByCode')->willReturn($this->source('retired', false));

        $this->expectException(UnknownSourceException::class);
        $this->validator->validate(['source_code' => 'retired']);
    }

    /**
     * A typed code in `extensions` is what lets an app distinguish "you sent a bad source" from
     * "the cart is gone" without matching on message text.
     */
    public function testTheRejectionCarriesATypedCode(): void
    {
        $this->repository->method('getByCode')->willReturn($this->source('retired', false));

        try {
            $this->validator->validate(['source_code' => 'retired']);
            $this->fail('Expected UnknownSourceException');
        } catch (UnknownSourceException $e) {
            $extensions = $e->getExtensions();
            $this->assertSame(UnknownSourceException::CODE, $extensions['code']);
            $this->assertSame('retired', $extensions['source_code']);
            $this->assertArrayHasKey('category', $extensions, 'The base class category must survive');
        }
    }

    public function testBlankDetailBecomesNull(): void
    {
        $this->repository->method('getByCode')->willReturn($this->source('web', true));

        $this->assertNull($this->validator->validate(['source_code' => 'web', 'source_detail' => '   '])?->detail);
    }

    /**
     * The column is varchar(255). Truncating here means the value the object reports is the value the
     * database holds; leaving it to MySQL means the two disagree, and in strict mode it means the
     * checkout throws.
     */
    public function testDetailIsTruncatedToTheColumnWidth(): void
    {
        $this->repository->method('getByCode')->willReturn($this->source('web', true));

        $detail = $this->validator->validate([
            'source_code' => 'web',
            'source_detail' => str_repeat('x', 400),
        ])?->detail;

        $this->assertSame(Attribution::MAX_DETAIL_LENGTH, mb_strlen((string)$detail));
    }

    private function source(string $code, bool $active): SourceInterface&MockObject
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getCode')->willReturn($code);
        $source->method('isActive')->willReturn($active);

        return $source;
    }
}
