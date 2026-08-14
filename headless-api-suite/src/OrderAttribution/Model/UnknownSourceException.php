<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\Phrase;

/**
 * The client named a source the registry does not have, or has deactivated.
 *
 * A typed code goes into `extensions` so an app can branch on the reason without string-matching the
 * message. `GraphQL\Error\Error::__construct()` copies `getExtensions()` from the previous exception
 * when that exception implements `ProvidesExtensions` — which `GraphQlInputException` does — so
 * overriding the method here is enough to have the code reach the response body.
 */
class UnknownSourceException extends GraphQlInputException
{
    public const CODE = 'UNKNOWN_ORDER_SOURCE';

    /**
     * @param string $sourceCode
     */
    public function __construct(private readonly string $sourceCode)
    {
        parent::__construct(new Phrase('Order source "%1" is not available.', [$sourceCode]));
    }

    /**
     * @inheritDoc
     */
    public function getExtensions(): array
    {
        return parent::getExtensions() + [
            'code' => self::CODE,
            'source_code' => $this->sourceCode,
        ];
    }
}
