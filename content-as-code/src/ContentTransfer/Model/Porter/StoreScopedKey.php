<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Porter;

/**
 * The bundle key for a store-scoped CMS entity: `about-us`, or `about-us@de+fr`.
 *
 * A CMS identifier is not unique. `cms_block.identifier` and `cms_page.identifier` each carry a
 * plain btree index in `Magento_Cms/etc/db_schema.xml` — no unique constraint — because the same
 * identifier is meant to exist once per store scope: one `home` page for the German store view and
 * another for the French one is the normal way to run a multi-store catalogue. A bundle keyed on the
 * identifier alone would therefore lose one of the two, silently, which is the failure this format
 * exists to avoid.
 *
 * So the key is the identifier plus the store scope it applies to, and the scope suffix is omitted
 * for the all-store-views case, which is most entities on most installs. Codes are sorted, so the
 * key does not depend on the order the database returned the assignment rows in.
 */
class StoreScopedKey
{
    private const SCOPE_SEPARATOR = '@';

    private const CODE_SEPARATOR = '+';

    /**
     * @param string[] $storeCodes Empty means "all store views" (core's store id 0).
     */
    public function build(string $identifier, array $storeCodes): string
    {
        if ($storeCodes === []) {
            return $identifier;
        }

        sort($storeCodes);

        return $identifier . self::SCOPE_SEPARATOR . implode(self::CODE_SEPARATOR, $storeCodes);
    }
}
