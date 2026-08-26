<?php
declare(strict_types=1);

namespace Scr1be\PHPStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\Native\NativeParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\TrinaryLogic;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\MixedType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;

/**
 * One resolved magic accessor: `getSku()`, `setQty()`, `hasChildren()`, `unsPrice()`.
 *
 * The return types come from what `__call` actually delegates to, read out of
 * `Magento\Framework\DataObject`:
 *
 *   get*  ->  getData()     @return mixed
 *   set*  ->  setData()     @return $this
 *   uns*  ->  unsetData()   @return $this
 *   has*  ->  isset(...)    a bool expression, not a delegated call
 *
 * `$this` rather than the declaring class, because a subclass's `setFoo()` answers the subclass —
 * chaining `$product->setSku('x')->getName()` has to stay on `Product`.
 *
 * The parameter list is one optional variadic. `__call` receives `$args` and reads at most
 * `$args[0]`, so no arity is wrong enough to be worth reporting: an extra argument is ignored at
 * runtime rather than fatal, and inventing a stricter signature here would turn working code red.
 */
final class MagicDataAccessor implements MethodReflection
{
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly string $name,
        private readonly string $prefix
    ) {
    }

    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    public function isStatic(): bool
    {
        return false;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * @return list<FunctionVariant>
     */
    public function getVariants(): array
    {
        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                [
                    new NativeParameterReflection(
                        'args',
                        true,
                        new MixedType(),
                        PassedByReference::createNo(),
                        true,
                        null
                    ),
                ],
                true,
                $this->returnType()
            ),
        ];
    }

    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type
    {
        return null;
    }

    /**
     * `set*` and `uns*` mutate the data bag, and `has*`/`get*` read it. None of that is a side
     * effect PHPStan needs to reason about, and claiming otherwise costs `@phpstan-pure`-style
     * checks elsewhere for nothing.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createMaybe();
    }

    private function returnType(): Type
    {
        return match ($this->prefix) {
            MagicDataAccessorExtension::PREFIX_HAS => new BooleanType(),
            MagicDataAccessorExtension::PREFIX_SET,
            MagicDataAccessorExtension::PREFIX_UNS => new ThisType($this->declaringClass),
            default => new MixedType(),
        };
    }
}
