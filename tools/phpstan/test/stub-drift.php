#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fails when the Hyvä stub stops matching the Hyvä that is installed.
 *
 * The stub exists so CI can analyse `hyva-product-slider` without a commercial package. That is
 * only safe while it tells the truth: the moment Hyvä changes a parameter, a copied signature
 * becomes fiction that CI cannot see past — the analysis stays green and the storefront is what
 * breaks. This is the check that notices, and it belongs on a machine that has Hyvä, which is why
 * it is a local gate rather than a CI one.
 *
 *   php tools/phpstan/test/stub-drift.php
 *
 * The stub and the real class share a fully-qualified name, so one process cannot hold both. Each
 * side is reflected in its own subprocess and compared here. The `stub` side loads the composer
 * autoloader for the parameter types and then requires the stub *before* anything asks for the
 * class, so the autoloader is never consulted for it.
 *
 * Only what the stub declares is compared. Hyvä may add methods — that is its business, and this
 * repository calls none of them. What it may not do unnoticed is change one this repository is
 * compiled against.
 */

$repoRoot = dirname(__DIR__, 3);
$magentoRoot = dirname($repoRoot);
$autoload = $magentoRoot . '/vendor/autoload.php';
$stub = $repoRoot . '/tools/phpstan/stubs/Hyva/Theme/ViewModel/ProductListItem.php';
$class = 'Hyva\Theme\ViewModel\ProductListItem';

/**
 * A method's signature reduced to what a caller is bound by: order, names, types, nullability,
 * whether a default exists. Not the body, not the docblock, not the parameter's default *value* —
 * a changed default is Hyvä's business until it changes the type with it.
 *
 * @return array<string, mixed>
 */
$describe = static function (ReflectionMethod $method): array {
    $parameters = [];

    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type !== null && !$type instanceof ReflectionNamedType) {
            // A union or intersection type would need a comparison this does not implement, and
            // silently reporting "same" would be the one outcome worse than failing.
            throw new RuntimeException(sprintf(
                'Parameter $%s of %s has a compound type this check cannot compare.',
                $parameter->getName(),
                $method->getName()
            ));
        }

        $parameters[] = [
            'name' => $parameter->getName(),
            'type' => $type?->getName(),
            'nullable' => $type?->allowsNull(),
            'optional' => $parameter->isOptional(),
            'variadic' => $parameter->isVariadic(),
            'byReference' => $parameter->isPassedByReference(),
        ];
    }

    $returnType = $method->getReturnType();

    return [
        'parameters' => $parameters,
        'returnType' => $returnType instanceof ReflectionNamedType ? $returnType->getName() : null,
        'returnNullable' => $returnType?->allowsNull(),
        'static' => $method->isStatic(),
    ];
};

// The two subprocess modes. Each prints one JSON document and exits.
$emit = $argv[1] ?? null;

if ($emit === '--emit=stub' || $emit === '--emit=real') {
    require $autoload;

    if ($emit === '--emit=stub') {
        // Before the autoloader is ever asked for this name, so the stub wins.
        require $stub;
    }

    if (!class_exists($class)) {
        fwrite(STDOUT, json_encode(['missing' => true]) . "\n");

        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit(0);
    }

    $reflection = new ReflectionClass($class);
    $methods = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $methods[$method->getName()] = $describe($method);
    }

    fwrite(STDOUT, json_encode([
        'missing' => false,
        'interfaces' => array_values(class_implements($class) ?: []),
        'methods' => $methods,
    ]) . "\n");

    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(0);
}

/** @return array<string, mixed> */
$run = static function (string $mode): array {
    $command = sprintf(
        '%s -d display_errors=stderr %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        $mode
    );
    $output = shell_exec($command);

    if (!is_string($output) || trim($output) === '') {
        throw new RuntimeException(sprintf('%s produced no output.', $mode));
    }

    $decoded = json_decode(trim($output), true);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf("%s produced something that is not JSON:\n%s", $mode, $output));
    }

    return $decoded;
};

$stubSide = $run('--emit=stub');
$realSide = $run('--emit=real');

if ($stubSide['missing'] === true) {
    fwrite(STDERR, "The stub did not declare {$class}. It is the file this check exists for.\n");

    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

if ($realSide['missing'] === true) {
    // Not a failure. Hyvä is licensed, so a machine without it is a legitimate place to be — it is
    // simply not a place where this question can be answered.
    printf("Hyvä is not installed, so there is nothing to compare %s against — skipped.\n", $class);

    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(0);
}

/**
 * What exactly disagrees, field by field. Printing both signatures whole says "these differ" and
 * leaves the reading to whoever is on call; a stub is usually one parameter out, and that is the
 * sentence worth writing.
 *
 * @param array<string, mixed> $stubMethod
 * @param array<string, mixed> $realMethod
 * @return list<string>
 */
$differences = static function (array $stubMethod, array $realMethod): array {
    $found = [];

    foreach (['returnType', 'returnNullable', 'static'] as $field) {
        if ($stubMethod[$field] !== $realMethod[$field]) {
            $found[] = sprintf(
                '%s is %s, Hyvä has %s',
                $field,
                json_encode($stubMethod[$field]),
                json_encode($realMethod[$field])
            );
        }
    }

    if (count($stubMethod['parameters']) !== count($realMethod['parameters'])) {
        // Positions stop lining up past this point, so comparing them one by one would report
        // every remaining parameter as wrong and bury the one fact that matters.
        $found[] = sprintf(
            'takes %d parameter(s), Hyvä takes %d',
            count($stubMethod['parameters']),
            count($realMethod['parameters'])
        );

        return $found;
    }

    foreach ($stubMethod['parameters'] as $position => $stubParameter) {
        $realParameter = $realMethod['parameters'][$position];

        foreach ($stubParameter as $field => $value) {
            if ($realParameter[$field] !== $value) {
                $found[] = sprintf(
                    'parameter #%d $%s: %s is %s, Hyvä has %s',
                    $position + 1,
                    $stubParameter['name'],
                    $field,
                    json_encode($value),
                    json_encode($realParameter[$field])
                );
            }
        }
    }

    return $found;
};

$problems = [];

// Compared as a set, in both directions, unlike the methods below. A stub may hold fewer methods
// than the class it stands in for — this repository calls one of them and copying the rest would
// be unchecked duplication. It may not hold fewer *interfaces*: those are the class's type, an
// omitted one silently narrows what CI believes the value can be passed as, and there is nothing
// to be gained by leaving one out.
foreach (array_diff($stubSide['interfaces'], $realSide['interfaces']) as $interface) {
    $problems[] = sprintf('the stub says %s implements %s; the installed Hyvä does not', $class, $interface);
}

foreach (array_diff($realSide['interfaces'], $stubSide['interfaces']) as $interface) {
    $problems[] = sprintf('the installed Hyvä has %s implementing %s; the stub omits it', $class, $interface);
}

foreach ($stubSide['methods'] as $name => $stubMethod) {
    if (!isset($realSide['methods'][$name])) {
        $problems[] = sprintf('the stub declares %s::%s(); the installed Hyvä has no such method', $class, $name);

        continue;
    }

    foreach ($differences($stubMethod, $realSide['methods'][$name]) as $difference) {
        $problems[] = sprintf('%s::%s() — %s', $class, $name, $difference);
    }
}

if ($problems !== []) {
    fwrite(STDERR, "The stub no longer matches the installed Hyvä:\n\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }

    fwrite(STDERR, "\nUpdate the stub to the installed signature, then check whether the code that\n");
    fwrite(STDERR, "calls it still holds. CI analyses against the stub and cannot see this.\n");

    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

printf(
    "stub matches the installed Hyvä — %d method(s), %d interface(s) checked\n",
    count($stubSide['methods']),
    count($stubSide['interfaces'])
);
