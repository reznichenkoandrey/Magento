<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;

/**
 * `availableOrderSources` — the codes a client is allowed to send.
 *
 * Public, and unauthenticated on purpose. An app has to know the vocabulary before it has a customer
 * token — the first order it places may well be a guest order — and the list is a set of channel
 * names the merchant chose, not data about anybody. Requiring a token here would mean an app either
 * hardcoding the codes (and drifting from the registry the moment a merchant adds one) or discovering
 * them only after login.
 *
 * Inactive sources are absent, which is what makes deactivation useful: a client that refreshes this
 * list stops offering a retired channel without shipping a new build.
 */
class AvailableOrderSources implements ResolverInterface
{
    /**
     * @param SourceRepositoryInterface $sourceRepository
     */
    public function __construct(private readonly SourceRepositoryInterface $sourceRepository)
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
        return array_map(
            static fn (SourceInterface $source): array => [
                'code' => $source->getCode(),
                'label' => $source->getLabel(),
            ],
            $this->sourceRepository->getActive()
        );
    }
}
