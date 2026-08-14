<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model\Inspector;

/**
 * Turns a PHP backtrace into the shortest list of frames that still identifies the caller.
 *
 * Two kinds of frame are dropped before the list is trimmed to length:
 *
 * - **This module's own frames.** The reader is looking for the code that asked for a vary string
 *   or stamped a no-cache header, not for the recorder that noticed.
 * - **Interception plumbing.** Magento routes every intercepted call through a generated
 *   `…\Interceptor` subclass, which hands the arguments to `___callPlugins()`; that method runs the
 *   plugin chain inside a `$next` closure and reaches the original implementation through
 *   `___callParent()` (see `vendor/magento/framework/Interception/Interceptor.php`). Those three
 *   names sit between the real caller and the plugin on every single hook, and none of them tells
 *   the reader anything.
 *
 * Each surviving frame reads `Class::method (file:line)`, where the file and line are the place the
 * call was made *from* — that is what PHP records in a backtrace frame, and it is the location a
 * reader wants to open.
 */
class StackTrace
{
    /**
     * Method names the interceptor trait contributes. Matched exactly rather than by an `___`
     * prefix so that an unrelated method starting with underscores stays visible.
     */
    private const PLUMBING_METHODS = ['___callPlugins', '___callParent', '___init'];

    /**
     * PHP names the frame of a closure `{closure}` up to 8.3 and `{closure:file:line}` from 8.4,
     * so the plumbing closure inside the interceptor trait is matched on the common prefix.
     */
    private const CLOSURE_PREFIX = '{closure';

    /**
     * The two namespaces that sit between a caller and a record. Listed explicitly rather than
     * filtered by the module's root namespace so the filter stays a statement about the recording
     * path itself, not a blanket rule that would also swallow a legitimate caller that happened to
     * live under the same vendor prefix.
     */
    private const OWN_NAMESPACES = [
        'Scr1be\\FpcInspector\\Model\\',
        'Scr1be\\FpcInspector\\Plugin\\',
    ];

    /**
     * Long paths make the record hard to scan; every file worth naming lives under the Magento root.
     */
    private function stripRoot(string $file): string
    {
        if (!defined('BP')) {
            return $file;
        }

        $root = rtrim((string) constant('BP'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    /**
     * @param array<int, array<string, mixed>> $frames Raw `debug_backtrace()` output.
     * @return string[]
     */
    public function flatten(array $frames, int $depth): array
    {
        $flattened = [];

        foreach ($frames as $frame) {
            if (count($flattened) >= $depth) {
                break;
            }

            $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
            $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';

            if ($this->isPlumbing($class, $function)) {
                continue;
            }

            $flattened[] = $this->describe($class, $function, $frame);
        }

        return $flattened;
    }

    /**
     * Collects the stack of whoever called into the module. The backtrace is taken without
     * arguments: the recorder never needs them, and skipping them keeps large object graphs out of
     * memory on a hook that fires several times per request.
     *
     * @return string[]
     */
    public function capture(int $depth): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return $this->flatten(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), $depth);
    }

    private function isPlumbing(string $class, string $function): bool
    {
        foreach (self::OWN_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        if (in_array($function, self::PLUMBING_METHODS, true)) {
            return true;
        }

        return str_starts_with($function, self::CLOSURE_PREFIX) && str_contains($class, '\\Interceptor');
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function describe(string $class, string $function, array $frame): string
    {
        $type = is_string($frame['type'] ?? null) ? $frame['type'] : '::';
        $callee = $class === '' ? $function : $class . $type . $function;

        $file = is_string($frame['file'] ?? null) ? $this->stripRoot($frame['file']) : 'unknown';
        $line = is_int($frame['line'] ?? null) ? $frame['line'] : 0;

        return sprintf('%s (%s:%d)', $callee, $file, $line);
    }
}
