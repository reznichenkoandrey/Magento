<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Integration\Api\Exception\UserTokenException;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Api\UserTokenValidatorInterface;

/**
 * The second lock: who is actually making the download request.
 *
 * The signed token says "customer 42 asked for invoice 7". That is a claim by this installation
 * about the past, and a claim is all it is — anyone holding the URL holds the claim. So the
 * download also has to be *made* by customer 42, proved the ordinary way: the customer's own bearer
 * token, the same credential the GraphQL mutation was called with.
 *
 * The consequence is deliberate and worth stating plainly: **the signed URL is not a capability.**
 * Pasting it into a browser address bar returns 404. It has to be fetched by the app, with the
 * customer's `Authorization` header attached. That is what makes a URL leaked through a screenshot,
 * a referrer header, an analytics beacon or a support ticket worth nothing on its own.
 *
 * Reading and validating go through Magento_Integration's own two interfaces rather than a token
 * table lookup, so token expiry, revocation and the admin-configurable lifetime keep working
 * exactly as they do for the Web API. `Magento\Webapi\Model\Authorization\TokenUserContext` uses
 * the same pair; this class exists rather than reusing it because that one reads the header from an
 * injected request in `processRequest()` and latches the answer behind an `$isRequestProcessed`
 * flag, so it answers a question about the request it first saw rather than about this call.
 */
class CustomerTokenAuthenticator
{
    private const SCHEME = 'bearer';

    private const HEADER_PARTS = 2;

    public function __construct(
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly UserTokenValidatorInterface $tokenValidator
    ) {
    }

    /**
     * @param string|false|null $authorizationHeader Whatever RequestInterface::getHeader() returned
     * @return int The authenticated customer id
     * @throws InvalidTokenException When there is no usable customer credential on the request
     */
    public function resolveCustomerId(string|false|null $authorizationHeader): int
    {
        if (!is_string($authorizationHeader) || $authorizationHeader === '') {
            throw new InvalidTokenException('no Authorization header on the download request');
        }

        $pieces = explode(' ', $authorizationHeader);
        if (count($pieces) !== self::HEADER_PARTS || strtolower($pieces[0]) !== self::SCHEME) {
            throw new InvalidTokenException('Authorization header is not a bearer token');
        }

        try {
            $userToken = $this->tokenReader->read($pieces[1]);
            $this->tokenValidator->validate($userToken);
        } catch (UserTokenException | AuthorizationException) {
            throw new InvalidTokenException('bearer token is unreadable, expired or revoked');
        }

        $context = $userToken->getUserContext();
        if ((int) $context->getUserType() !== UserContextInterface::USER_TYPE_CUSTOMER) {
            // An admin or integration token is a valid credential for something else. It is not a
            // customer, and this endpoint only knows how to authorize customers.
            throw new InvalidTokenException('bearer token belongs to a non-customer user type');
        }

        $customerId = (int) $context->getUserId();
        if ($customerId <= 0) {
            throw new InvalidTokenException('bearer token carries no customer id');
        }

        return $customerId;
    }
}
