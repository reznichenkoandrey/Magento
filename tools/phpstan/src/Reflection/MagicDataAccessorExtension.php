<?php
declare(strict_types=1);

namespace Scr1be\PHPStan\Reflection;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

/**
 * Teaches PHPStan the magic accessors on Magento's two data bags.
 *
 * Magento ships an extension that does this, and it is 1.x only: it pulls PHPStan's internal
 * `AnnotationsMethodsClassReflectionExtension` out of the DI container, and PHPStan's own upgrade
 * guide says an extension cannot span a major version. This is the replacement, written against
 * what the two `__call()` implementations actually do rather than against that one.
 *
 * `Magento\Framework\DataObject::__call()` switches on the first three characters and delegates:
 * `get`, `set`, `uns` and `has` reach `getData()`, `setData()`, `unsetData()` and an `isset()` on
 * the data array. Anything else throws `LocalizedException('Invalid method %1::%2')`.
 *
 * `Magento\Framework\Session\SessionManager::__call()` is not a DataObject and is reached
 * separately, but the rule is identical — the same four prefixes, forwarded to a storage object,
 * with an `InvalidArgumentException` for everything else.
 *
 * That "anything else throws" half is the point of writing this rather than suppressing the
 * findings wholesale. A suppression makes `$order->fetchTotal()` analysable; this does not,
 * because `fetch` is not one of the four and the runtime would have thrown.
 *
 * What it deliberately does not do is check the *key*. `getPorductName()` carries a valid prefix,
 * so it resolves here and returns null at runtime — same as it would under Magento's own
 * extension. Catching that needs the entity's real attribute set, which is a database question,
 * not a static one.
 */
final class MagicDataAccessorExtension implements MethodsClassReflectionExtension
{
    public const PREFIX_GET = 'get';
    public const PREFIX_SET = 'set';
    public const PREFIX_UNS = 'uns';
    public const PREFIX_HAS = 'has';

    private const PREFIXES = [
        self::PREFIX_GET,
        self::PREFIX_SET,
        self::PREFIX_UNS,
        self::PREFIX_HAS,
    ];

    /**
     * The two roots that answer a magic accessor. Everything Magento models — products, orders,
     * quote items, sessions, collections' rows — reaches one of them.
     */
    private const MAGIC_ROOTS = [
        \Magento\Framework\DataObject::class,
        \Magento\Framework\Session\SessionManager::class,
    ];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // A declared method always wins. Without this the extension would answer for
        // `getData()` itself and hide its real signature behind a `mixed`.
        if ($classReflection->hasNativeMethod($methodName)) {
            return false;
        }

        // An `@method` annotation wins too, and this is the half that is easy to get wrong.
        // Magento annotates hundreds of these — `@method Instance setStoreIds(string $value)` on
        // Widget\Instance is one — and each carries a real signature. Answering here instead
        // would quietly replace `string $value` with `mixed ...$args`, turning a typed accessor
        // into an untyped one and losing exactly the checking this extension exists to add.
        if ($this->hasAnnotatedMethod($classReflection, $methodName)) {
            return false;
        }

        if (!$this->isMagicRoot($classReflection)) {
            return false;
        }

        return $this->prefixOf($methodName) !== null;
    }

    /**
     * Whether the class or one of its ancestors declares this method with `@method`.
     *
     * Read off the docblocks rather than asked of PHPStan: `ClassReflection::hasMethod()` consults
     * every registered extension, this one included, so calling it from here would recurse.
     *
     * The name has to be followed by `(` and not preceded by a word character or a `$`, which is
     * what keeps `@method Foo setStoreIdsAndMore()` and a `$setStoreIds` parameter from matching.
     */
    private function hasAnnotatedMethod(ClassReflection $classReflection, string $methodName): bool
    {
        $pattern = '/@method\s[^\r\n]*?(?<![\w$])' . preg_quote($methodName, '/') . '\s*\(/i';

        for ($class = $classReflection; $class !== null; $class = $class->getParentClass()) {
            $docComment = $class->getNativeReflection()->getDocComment();

            if (is_string($docComment) && preg_match($pattern, $docComment) === 1) {
                return true;
            }
        }

        return false;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new MagicDataAccessor(
            $classReflection,
            $methodName,
            (string) $this->prefixOf($methodName)
        );
    }

    private function isMagicRoot(ClassReflection $classReflection): bool
    {
        foreach (self::MAGIC_ROOTS as $root) {
            if ($classReflection->is($root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `__call` compares exactly three characters, so `get`, `getX` and `getting` all match while
     * `ge` does not — and a bare `get()` reaches `getData('')`, which is legal.
     */
    private function prefixOf(string $methodName): ?string
    {
        $prefix = substr($methodName, 0, 3);

        return in_array($prefix, self::PREFIXES, true) ? $prefix : null;
    }
}
