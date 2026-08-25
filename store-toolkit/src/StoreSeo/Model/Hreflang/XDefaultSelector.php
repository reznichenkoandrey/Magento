<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Hreflang;

/**
 * Picks the alternate that also gets `hreflang="x-default"`.
 *
 * x-default is the page a crawler sends a visitor to when none of the advertised locales matches
 * them. There is exactly one per group, and leaving it out means the crawler picks for you. The
 * ladder below is deliberate and is the part of hreflang that most implementations get wrong by
 * hardcoding "the default store".
 */
class XDefaultSelector
{
    /**
     * @param AlternateLink[] $links Alternates for this page, in the order they will be emitted.
     * @param int|null $primaryStoreId Store nominated in configuration as the group's primary.
     * @param string|null $primaryLanguage Language subtag of that store, e.g. `en`.
     */
    public function select(array $links, ?int $primaryStoreId, ?string $primaryLanguage): ?AlternateLink
    {
        if ($links === []) {
            return null;
        }

        // Rung 1 — the nominated primary, when this page exists in it. The common case, and the
        // only rung a naive implementation has.
        if ($primaryStoreId !== null) {
            foreach ($links as $link) {
                if ($link->getStoreId() === $primaryStoreId) {
                    return $link;
                }
            }
        }

        // Rung 2 — same language as the primary. This is the rung that matters: a product carried
        // by the UK store but not by the US one should send undirected traffic to the UK page, not
        // to whichever locale happens to be first alphabetically.
        if ($primaryLanguage !== null && $primaryLanguage !== '') {
            $primaryLanguage = strtolower($primaryLanguage);
            foreach ($links as $link) {
                if ($link->getLanguage() === $primaryLanguage) {
                    return $link;
                }
            }
        }

        // Rung 3 — first available. Emitting *an* x-default beats emitting none: a group without
        // one leaves the choice to the crawler, and the crawler's choice is not visible to anyone.
        // The empty case returned at the top, and the members are objects, so this is never
        // falsy — `?: null` here would only be reachable if the list held something that is not
        // an AlternateLink.
        return reset($links);
    }
}
