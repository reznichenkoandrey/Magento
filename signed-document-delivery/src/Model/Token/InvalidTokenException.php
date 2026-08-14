<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised for every way a token can fail to be a token.
 *
 * The reason is carried in `$reason` for the log and never in the message: "bad signature",
 * "expired" and "malformed" are three different pieces of information a probing client would love
 * to have, and the controller answers all of them with the same 404.
 */
class InvalidTokenException extends LocalizedException
{
    public function __construct(
        public readonly string $reason
    ) {
        parent::__construct(__('The download link is not valid.'));
    }
}
