<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Api\Data\SliderSearchResultsInterfaceFactory;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider as SliderResource;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\CollectionFactory;
use Scr1be\HyvaProductSlider\Model\Slider;
use Scr1be\HyvaProductSlider\Model\SliderFactory;
use Scr1be\HyvaProductSlider\Model\SliderRepository;
use Scr1be\HyvaProductSlider\Model\SliderValidator;

/**
 * What happens when the resource throws an `\Error` rather than an `\Exception`.
 *
 * `LocalizedException::__construct(Phrase $phrase, ?\Exception $cause = null, ...)` types its
 * cause natively. Handing it the value from a `catch (\Throwable)` therefore raises a `TypeError`
 * while constructing the exception meant to describe the failure — so the caller sees a
 * `TypeError` thrown from the repository instead of the `CouldNotSaveException` the interface
 * documents, and the `catch (CouldNotSaveException)` it wrote never runs.
 *
 * An `\Error` here is not exotic: a `TypeError` from a mis-typed column value, a
 * `DivisionByZeroError`, or an `ArgumentCountError` from a plugin on the resource all arrive this
 * way. PHPStan finds this statically; these tests pin the behaviour so it cannot come back.
 */
class SliderRepositoryErrorCauseTest extends TestCase
{
    private SliderResource&MockObject $resource;
    private SliderValidator&MockObject $validator;
    private SliderRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(SliderResource::class);
        $this->validator = $this->createMock(SliderValidator::class);

        $this->repository = new SliderRepository(
            $this->resource,
            $this->createMock(SliderFactory::class),
            $this->createMock(CollectionFactory::class),
            $this->createMock(SliderSearchResultsInterfaceFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
            $this->validator
        );
    }

    public function testSaveReportsAnErrorAsCouldNotSaveRatherThanRaisingATypeError(): void
    {
        $this->resource->method('save')->willThrowException(new \TypeError('column type'));

        try {
            $this->repository->save($this->createMock(Slider::class));
            self::fail('save() was expected to throw.');
        } catch (\TypeError $typeError) {
            self::fail('The repository leaked a TypeError instead of CouldNotSaveException: ' . $typeError->getMessage());
        } catch (CouldNotSaveException $expected) {
            self::assertSame('The slider could not be saved.', $expected->getMessage());
        }
    }

    public function testSaveKeepsTheOriginalErrorReachableThroughTheWrapper(): void
    {
        // Wrapping must not lose the cause: whatever actually broke has to stay in the chain, or
        // the log records a message with no origin.
        $original = new \TypeError('column type');
        $this->resource->method('save')->willThrowException($original);

        try {
            $this->repository->save($this->createMock(Slider::class));
            self::fail('save() was expected to throw.');
        } catch (CouldNotSaveException $caught) {
            $wrapper = $caught->getPrevious();

            self::assertInstanceOf(\RuntimeException::class, $wrapper);
            self::assertSame('column type', $wrapper->getMessage());
            self::assertSame($original, $wrapper->getPrevious());
        }
    }

    public function testSavePassesARealExceptionThroughUnwrapped(): void
    {
        // An \Exception already satisfies the constructor, so it must arrive as itself — wrapping
        // everything would bury the type a caller inspects.
        $original = new \RuntimeException('disk full');
        $this->resource->method('save')->willThrowException($original);

        try {
            $this->repository->save($this->createMock(Slider::class));
            self::fail('save() was expected to throw.');
        } catch (CouldNotSaveException $caught) {
            self::assertSame($original, $caught->getPrevious());
        }
    }

    public function testAValidationFailureIsStillNotConvertedIntoASaveFailure(): void
    {
        // The validator throws before the try block; that contract is unchanged by the wrapping.
        $this->validator->method('validate')->willThrowException(new LocalizedException(__('Enter a title')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Enter a title');

        $this->repository->save($this->createMock(Slider::class));
    }

    public function testDeleteReportsAnErrorAsCouldNotDeleteRatherThanRaisingATypeError(): void
    {
        $original = new \TypeError('bad id');
        $this->resource->method('delete')->willThrowException($original);

        try {
            $this->repository->delete($this->createMock(Slider::class));
            self::fail('delete() was expected to throw.');
        } catch (\TypeError $typeError) {
            self::fail('The repository leaked a TypeError instead of CouldNotDeleteException: ' . $typeError->getMessage());
        } catch (CouldNotDeleteException $expected) {
            self::assertSame('The slider could not be deleted.', $expected->getMessage());
            self::assertSame($original, $expected->getPrevious()?->getPrevious());
        }
    }
}
