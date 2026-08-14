<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model\Inspector;

use Magento\Framework\App\Http\Context;

/**
 * Explains a vary string by re-running core's own filter over the context and reporting the verdict
 * for every key, not just the survivors.
 *
 * `Magento\Framework\App\Http\Context::getData()` keeps a key when `$value && $value != $default`
 * (`vendor/magento/framework/App/Http/Context.php`, the loop inside `getData()`); `getVaryString()`
 * then hashes whatever survived, and returns null when nothing did. Two consequences trip people up
 * often enough to be worth spelling out per key rather than leaving to be rediscovered:
 *
 * - The test is on **truthiness**, so a key set to `0`, `''`, `false` or an empty array never
 *   fragments the cache no matter what its default is. This is why `customer_logged_in` is
 *   invisible for guests and appears the moment somebody logs in.
 * - The comparison is **loose**, so `'0'` and `0`, or `1` and `true`, count as the same value as
 *   their default. A key can look different from its default in a var_dump and still be inert.
 *
 * The `setter` field is a lookup table of the core code that is known to write each key in 2.4.8,
 * offered as a starting point for the search. It is a hint, not evidence: any module can write any
 * key, and a key this table does not recognise is reported as unknown rather than guessed at.
 */
class VaryBreakdown
{
    /**
     * Values are rendered for a log line, not for replay. A tax-rate array or a long list of
     * category ids would otherwise push the interesting fields off the end of the record.
     */
    private const MAX_VALUE_LENGTH = 120;

    public const REASON_CONTRIBUTES = 'contributes';
    public const REASON_FALSY = 'value is falsy, so core drops it before hashing';
    public const REASON_EQUALS_DEFAULT = 'value is loosely equal to its registered default';

    /**
     * Core writers of the well-known context keys in Magento 2.4.8, used to point the reader at a
     * first place to look.
     *
     * @var array<string, string>
     */
    private const KNOWN_SETTERS = [
        'customer_group' => 'Magento\\Customer\\Model\\App\\Action\\ContextPlugin::beforeExecute',
        'customer_logged_in' => 'Magento\\Customer\\Model\\App\\Action\\ContextPlugin::beforeExecute',
        'store' => 'Magento\\Store\\App\\Action\\Plugin\\Context::updateContext',
        'current_currency' => 'Magento\\Store\\App\\Action\\Plugin\\Context::updateContext'
            . ' or Magento\\Store\\Model\\Store::setCurrentCurrencyCode',
        'product_list_order' => 'Magento\\Catalog\\Plugin\\Framework\\App\\Action\\ContextPlugin::beforeDispatch'
            . ' or Magento\\Catalog\\Block\\Product\\ProductList\\Toolbar::getCurrentOrder',
        'product_list_dir' => 'Magento\\Catalog\\Plugin\\Framework\\App\\Action\\ContextPlugin::beforeDispatch'
            . ' or Magento\\Catalog\\Block\\Product\\ProductList\\Toolbar::getCurrentDirection',
        'product_list_mode' => 'Magento\\Catalog\\Plugin\\Framework\\App\\Action\\ContextPlugin::beforeDispatch'
            . ' or Magento\\Catalog\\Block\\Product\\ProductList\\Toolbar::getCurrentMode',
        'product_list_limit' => 'Magento\\Catalog\\Plugin\\Framework\\App\\Action\\ContextPlugin::beforeDispatch'
            . ' or Magento\\Catalog\\Block\\Product\\ProductList\\Toolbar::getLimit',
        'tax_rates' => 'Magento\\Tax\\Model\\App\\Action\\ContextPlugin::beforeExecute',
        'weee_tax_region' => 'Magento\\Weee\\Model\\App\\Action\\ContextPlugin::beforeExecute',
        'PERSISTENT' => 'Magento\\Persistent\\Model\\Plugin\\PersistentCustomerContext::beforeGetVaryString',
    ];

    public const SETTER_UNKNOWN = 'unknown — not a core key in 2.4.8; grep the codebase for setValue()';

    /**
     * @return array{contributors: array<int, array<string, mixed>>, inert: array<int, array<string, mixed>>}
     */
    public function explain(Context $context): array
    {
        $snapshot = $context->toArray();
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $defaults = is_array($snapshot['default'] ?? null) ? $snapshot['default'] : [];

        $contributors = [];
        $inert = [];

        foreach ($data as $key => $value) {
            $name = (string) $key;
            $default = $defaults[$name] ?? null;
            $entry = [
                'key' => $name,
                'value' => $this->render($value),
                'default' => $this->render($default),
                'setter' => self::KNOWN_SETTERS[$name] ?? self::SETTER_UNKNOWN,
            ];

            $reason = $this->classify($value, $default);

            if ($reason === self::REASON_CONTRIBUTES) {
                $contributors[] = $entry;
                continue;
            }

            $entry['ignored_because'] = $reason;
            $inert[] = $entry;
        }

        return ['contributors' => $contributors, 'inert' => $inert];
    }

    /**
     * Mirrors core's filter exactly, including the loose comparison. A strict comparison here would
     * report keys as cache-fragmenting that core silently discards, which is the opposite of useful.
     */
    private function classify(mixed $value, mixed $default): string
    {
        if (!$value) {
            return self::REASON_FALSY;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if ($value == $default) {
            return self::REASON_EQUALS_DEFAULT;
        }

        return self::REASON_CONTRIBUTES;
    }

    private function render(mixed $value): string
    {
        $rendered = match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES) ?: gettype($value),
        };

        if (strlen($rendered) <= self::MAX_VALUE_LENGTH) {
            return $rendered;
        }

        return substr($rendered, 0, self::MAX_VALUE_LENGTH) . '…';
    }
}
