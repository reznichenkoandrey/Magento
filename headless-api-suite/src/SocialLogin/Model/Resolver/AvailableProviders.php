<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Scr1be\SocialLogin\Model\Verifier\VerifierPool;

/**
 * `availableSocialLoginProviders` — which buttons the app should draw.
 *
 * Without this, a client either hardcodes the provider list (and shows an Apple button on a store
 * that has no Apple client id, producing a sign-in that always fails) or discovers availability by
 * trying. Both are worse than one field.
 *
 * Not cached. Availability is derived from configuration, so a `@cache` identity here would have to
 * be invalidated on a config save — and the query is two `isSetFlag`-shaped reads against config
 * that is already cached by `Magento_Config`.
 */
class AvailableProviders implements ResolverInterface
{
    /**
     * @param VerifierPool $verifierPool
     */
    public function __construct(private readonly VerifierPool $verifierPool)
    {
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $store = $context->getExtensionAttributes()->getStore();

        return $store instanceof StoreInterface
            ? $this->verifierPool->getAvailableCodes((int)$store->getId())
            : [];
    }
}
