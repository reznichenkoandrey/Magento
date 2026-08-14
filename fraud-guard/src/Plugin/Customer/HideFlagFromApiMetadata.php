<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Plugin\Customer;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Scr1be\FraudGuard\Model\FlagResolver;

/**
 * Keeps the flag out of the customer API surface.
 *
 * A user-defined customer EAV attribute is automatically published in the `custom_attributes`
 * array of CustomerInterface, which means a flagged carder could simply call
 * `GET /V1/customers/me` and read `is_carder: 1`. That is the same tell the silent decline exists
 * to avoid, so it has to be closed.
 *
 * This is the right seam rather than filtering the finished response: CustomerInterface builds its
 * custom-attribute list from getCustomAttributesCodes(), which resolves through this exact method.
 * Filter here and the attribute is never populated onto the data object in the first place —
 * nothing to strip, nothing cached wrong, and the shared repository instance is left untouched.
 *
 * Scoped to webapi_rest and graphql only (etc/webapi_rest/di.xml, etc/graphql/di.xml). The
 * adminhtml area keeps the metadata, which is what makes the customer-form checkbox save at all:
 * Adminhtml\Index\Save populates the customer through DataObjectHelper::populateWithArray(), and
 * that call only accepts keys the metadata still knows about.
 *
 * The trade this makes: the flag is admin-UI-managed, not API-managed. An integration cannot read
 * or write it over REST. For an anti-fraud flag that is the correct default — and it is a
 * deliberate choice, not an accident, so it is documented in the README.
 */
class HideFlagFromApiMetadata
{
    /**
     * @param AttributeMetadataInterface[] $result
     * @return AttributeMetadataInterface[]
     */
    public function afterGetCustomAttributesMetadata(
        CustomerMetadataInterface $subject,
        array $result
    ): array {
        // Re-index: callers iterate the list, but a gapped array serialises as an object rather
        // than a list once it reaches a JSON response.
        return array_values(
            array_filter(
                $result,
                static fn (AttributeMetadataInterface $attribute): bool
                    => $attribute->getAttributeCode() !== FlagResolver::ATTRIBUTE_CODE
            )
        );
    }
}
