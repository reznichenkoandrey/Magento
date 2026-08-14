<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Hreflang;

/**
 * Magento locale code to hreflang tag.
 *
 * Magento stores `general/locale/code` in ICU form (`en_US`, `pt_BR`, `zh_Hans_CN`); hreflang wants
 * BCP 47 (`en-US`, `pt-BR`, `zh-Hans-CN`). The transformation is one character, but doing it in a
 * named class keeps the one place that would need editing if a store were ever configured with a
 * locale Magento accepts and hreflang does not.
 */
class LocaleFormatter
{
    /**
     * Language (2-3 letters) followed by any number of 1-8 alphanumeric subtags.
     */
    private const BCP47_PATTERN = '/^[a-z]{2,3}(-[a-zA-Z0-9]{1,8})*$/';

    /**
     * Null for anything that would not be a legal hreflang value, so a malformed locale drops the
     * store out of the group instead of emitting a tag crawlers will ignore anyway.
     */
    public function format(?string $localeCode): ?string
    {
        $tag = str_replace('_', '-', trim((string) $localeCode));

        if ($tag === '' || preg_match(self::BCP47_PATTERN, $tag) !== 1) {
            return null;
        }

        return $tag;
    }
}
