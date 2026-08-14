<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model;

/**
 * The four sales documents a customer is allowed to ask for.
 *
 * The case names are the GraphQL enum members verbatim. That is not decoration: a schema-defined
 * enum with no explicit internal value reaches the resolver as its own name — the stitching reader
 * (Magento\Framework\GraphQlSchemaStitching\GraphQlReader\Reader\EnumType::read()) stores
 * `_value => $enumValueMeta->value`, EnumFactory::createFromConfigData() passes that through as the
 * EnumValue's value, and Magento\Framework\GraphQl\Schema\Type\Enum\Enum builds
 * `['value' => $value->getValue()]` from it. So `tryFrom($args['document_type'])` is a direct
 * mapping rather than a lookup table that has to be kept in step with the schema by hand.
 */
enum DocumentType: string
{
    case ORDER = 'ORDER';
    case INVOICE = 'INVOICE';
    case SHIPMENT = 'SHIPMENT';
    case CREDITMEMO = 'CREDITMEMO';

    /**
     * Filename stem used for the downloaded file, joined to the document's increment id.
     */
    public function filenamePrefix(): string
    {
        return match ($this) {
            self::ORDER => 'order',
            self::INVOICE => 'invoice',
            self::SHIPMENT => 'shipment',
            self::CREDITMEMO => 'creditmemo',
        };
    }
}
