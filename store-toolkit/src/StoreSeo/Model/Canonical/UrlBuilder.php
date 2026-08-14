<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Canonical;

/**
 * Assembles a canonical URL out of primitives.
 *
 * Deliberately free of every Magento service: the whole interesting part of a canonical is string
 * handling (which query parameters survive, in which order, with what separator), and that is much
 * easier to hold to account when it is a pure function. The ViewModel does the I/O.
 */
class UrlBuilder
{
    /**
     * Build the canonical URL for one request.
     *
     * @param string $baseUrl Store base link URL, e.g. https://example.com/ or https://example.com/de/.
     *                        With web/url/use_store on, Magento\Store\Model\Store::_updatePathUseStoreView()
     *                        has already appended the store code to it.
     * @param string $pathInfo Request path with the store code removed — what
     *                         Magento\Store\App\Request\PathInfoProcessor::process() leaves behind, so
     *                         appending it to the base URL restores exactly one store code, never two.
     * @param array<string, mixed> $query Raw query parameters of the request.
     * @param string[] $allowedParams Parameter names that may survive into the canonical.
     */
    public function build(string $baseUrl, string $pathInfo, array $query, array $allowedParams): string
    {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($pathInfo, '/');

        $kept = array_intersect_key($query, array_flip($allowedParams));

        // Sorted so that ?p=2&sort=name and ?sort=name&p=2 canonicalise to the same string. Two
        // spellings of one page is the problem a canonical exists to solve; producing two
        // canonicals for them would reintroduce it one level up.
        ksort($kept);

        $queryString = http_build_query($kept);

        return $queryString === '' ? $url : $url . '?' . $queryString;
    }
}
