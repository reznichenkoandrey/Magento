<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Resolver;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Phrase;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\UserToken\UserTokenParametersFactory;
use Magento\Store\Api\Data\StoreInterface;
use Scr1be\SocialLogin\Model\Provisioner;
use Scr1be\SocialLogin\Model\SocialLoginException;
use Scr1be\SocialLogin\Model\Verifier\VerifierPool;

/**
 * `socialLogin(provider:, id_token:)` → a Magento customer token.
 *
 * The store comes from the GraphQL context and nowhere else. `Magento_StoreGraphQl` declares `store`
 * as an extension attribute of `Magento\GraphQl\Model\Query\ContextInterface`
 * (`etc/extension_attributes.xml`), and core's own resolvers read the store from it — see
 * `Magento\QuoteGraphQl\Model\Resolver\PlaceOrder::resolve()`, which does
 * `$context->getExtensionAttributes()->getStore()->getId()`. Accepting a store as a mutation argument
 * instead would let a caller pick which website's customer table their Google account resolves
 * against, which is an account-enumeration primitive and, on a shared-catalogue setup, worse.
 *
 * The token is minted through `UserTokenIssuerInterface` rather than
 * `Magento\Integration\Model\Oauth\Token::createCustomerToken()`, which core marks `@deprecated` and
 * points at this same SPI.
 */
class SocialLogin implements ResolverInterface
{
    /**
     * @param VerifierPool $verifierPool
     * @param Provisioner $provisioner
     * @param UserTokenIssuerInterface $tokenIssuer
     * @param UserTokenParametersFactory $tokenParametersFactory
     */
    public function __construct(
        private readonly VerifierPool $verifierPool,
        private readonly Provisioner $provisioner,
        private readonly UserTokenIssuerInterface $tokenIssuer,
        private readonly UserTokenParametersFactory $tokenParametersFactory
    ) {
    }

    /**
     * @inheritDoc
     * @throws SocialLoginException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $provider = trim((string)($args['input']['provider'] ?? ''));
        $idToken = trim((string)($args['input']['id_token'] ?? ''));

        if ($provider === '' || $idToken === '') {
            throw new SocialLoginException(
                SocialLoginException::INVALID_TOKEN,
                new Phrase('A provider and an ID token are required.')
            );
        }

        $store = $context->getExtensionAttributes()->getStore();
        if (!$store instanceof StoreInterface) {
            throw new SocialLoginException(
                SocialLoginException::PROVIDER_UNAVAILABLE,
                new Phrase('This sign-in method is not available.')
            );
        }

        $storeId = (int)$store->getId();
        $claims = $this->verifierPool->get($provider, $storeId)->verify($idToken, $storeId);
        $customer = $this->provisioner->resolve($claims, $store);

        return [
            'token' => $this->tokenIssuer->create(
                new CustomUserContext((int)$customer->getId(), UserContextInterface::USER_TYPE_CUSTOMER),
                $this->tokenParametersFactory->create()
            ),
        ];
    }
}
