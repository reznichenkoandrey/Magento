<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model;

use Magento\Framework\UrlInterface;

/**
 * Absolute, HTTPS, store-correct download URL for a token.
 *
 * No `_scope` parameter, on purpose. By the time a resolver runs,
 * `Magento\StoreGraphQl\Controller\HttpHeaderProcessor\StoreProcessor::processHeaderValue()` has
 * already called `$storeManager->setCurrentStore()` from the `Store` header — falling back to the
 * default store view when the header is absent — so the URL builder is pointing at the right store
 * without being told. Passing `_scope` would also mutate the shared `Magento\Framework\Url`
 * instance for the rest of the request, which is a side effect nobody downstream is expecting.
 * Core's own `Magento\CustomerDownloadableGraphQl\Model\Resolver\CustomerDownloadableProducts`
 * builds its `download_url` the same way, with `_secure` and nothing else.
 *
 * `_secure => true` is not decoration either: the token is a credential, short-lived or not, and
 * handing out an `http://` URL for it would be handing it to the first proxy on the path.
 */
class DocumentUrlBuilder
{
    public const ROUTE_PATH = 'signeddocument/download/index';

    public const TOKEN_PARAM = 'token';

    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function build(string $token): string
    {
        return $this->urlBuilder->getUrl(
            self::ROUTE_PATH,
            [
                '_secure' => true,
                '_query' => [self::TOKEN_PARAM => $token],
            ]
        );
    }
}
